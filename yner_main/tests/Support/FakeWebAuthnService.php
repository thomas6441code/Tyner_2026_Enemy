<?php

namespace Tests\Support;

use App\Models\User;
use App\Models\UserDevice;
use App\Services\WebAuthnService;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A WebAuthnService whose ceremony always succeeds — or always fails, on request.
 *
 * Real ceremony cryptography cannot be produced inside PHPUnit: a genuine assertion needs a
 * platform authenticator holding a private key that only the hardware can use. Tests of the
 * check-in gate chain are about business rules — geofence, schedule window, ordering, audit —
 * so the crypto is stubbed here and the real service is exercised separately in UserDeviceTest
 * against the challenge lifecycle it actually owns.
 *
 * The one thing this must NOT do is make the WebAuthn gate untestable: `$shouldFail` exists so
 * `test_a_check_in_without_a_valid_assertion_is_rejected` still proves the hard block works.
 */
class FakeWebAuthnService extends WebAuthnService
{
    public bool $shouldFail = false;

    public ?UserDevice $device = null;

    public function verifyAssertion(User $user, array $payload, ?Request $request = null): UserDevice
    {
        if ($this->shouldFail) {
            throw new RuntimeException('Fake assertion failure.');
        }

        return $this->device ?? UserDevice::where('user_id', $user->id)->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    public function assertionOptions(User $user): array
    {
        return ['challenge' => 'fake-challenge', 'rpId' => $this->rpId()];
    }
}
