<?php

namespace App\Services;

use App\Models\BiometricDevice;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Signed HTTP client for the internal Python bio-service (biometric device adapter).
 *
 * Every call is signed with the shared `X-Internal-Secret` header (verified by bio-service's
 * `verify_internal_secret`). All methods degrade gracefully: a network error, timeout, or
 * non-2xx response is logged and returns `null` rather than throwing.
 */
class BioServiceClient
{
    /**
     * Live connectivity check for the Biometric Devices "Test Connection" action. bio-service
     * builds the matching driver (stub/zkteco/hikvision) and attempts connect()+health().
     *
     * @return array{ok: bool, status: string, driver: ?string, error: ?string, checked_at: string}|null
     */
    public function testConnection(BiometricDevice $device): ?array
    {
        return $this->post('/api/devices/test-connection', [
            'type' => $device->type,
            'serial' => $device->serial,
            'host' => $device->host,
            'port' => $device->port,
            'username' => $device->username,
            'password' => $device->password,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function post(string $path, array $payload): ?array
    {
        $url = rtrim((string) config('services.bio.url'), '/').$path;

        try {
            $response = Http::timeout((int) config('services.bio.timeout', 10))
                ->withHeaders(['X-Internal-Secret' => config('services.internal_secret')])
                ->acceptJson()
                ->post($url, $payload);

            if ($response->failed()) {
                Log::warning('Bio service call failed', [
                    'url' => $url,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            return $response->json();
        } catch (Throwable $e) {
            Log::warning('Bio service unreachable', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
