<?php

namespace App\Http\Controllers;

use App\Enums\DeviceStatus;
use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\UserDevice;
use App\Notifications\SystemNotification;
use App\Services\WebAuthnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Registration and lifecycle of WebAuthn platform authenticators (phones).
 *
 * The two ceremony endpoints return JSON rather than Inertia responses: they are called with
 * fetch() from inside the WebAuthn handler chain, not as page visits.
 */
class UserDeviceController extends Controller
{
    public function __construct(private readonly WebAuthnService $webauthn) {}

    /**
     * Own devices; Admins additionally see everyone's.
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', UserDevice::class);

        $user = $request->user();
        $isAdmin = $user->hasRole(RoleName::Admin->value);

        $devices = UserDevice::with('user')
            ->when(! $isAdmin, fn ($query) => $query->where('user_id', $user->id))
            ->orderByRaw("status = 'active' desc")
            ->latest()
            ->paginate(15)
            ->through(fn (UserDevice $device) => [
                'id' => $device->id,
                'device_name' => $device->device_name,
                'owner' => $device->user?->name,
                'is_own' => $device->user_id === $user->id,
                'status' => $device->status->value,
                'status_label' => $device->status->label(),
                'rp_id' => $device->rp_id,
                // Surfaced so a domain move is diagnosable from the UI rather than from logs:
                // a credential bound to a different RP ID can never verify here.
                'rp_id_matches' => $device->rp_id === $this->webauthn->rpId(),
                'transports' => $device->transports ?? [],
                'last_used_at' => $device->last_used_at?->toDateTimeString(),
                'last_used_ip' => $isAdmin ? $device->last_used_ip : null,
                'registered_at' => $device->created_at?->toDateTimeString(),
                'revoked_at' => $device->revoked_at?->toDateTimeString(),
                'revoked_reason' => $device->revoked_reason,
                'can_delete' => $user->can('delete', $device),
            ]);

        return Inertia::render('devices/index', [
            'devices' => $devices,
            'stats' => [
                'active' => UserDevice::where('status', DeviceStatus::Active)
                    ->when(! $isAdmin, fn ($q) => $q->where('user_id', $user->id))->count(),
                'revoked' => UserDevice::where('status', DeviceStatus::Revoked)
                    ->when(! $isAdmin, fn ($q) => $q->where('user_id', $user->id))->count(),
                'mine' => UserDevice::where('user_id', $user->id)
                    ->where('status', DeviceStatus::Active)->count(),
            ],
            'actions' => [
                'register' => $user->can('create', UserDevice::class),
            ],
            'isAdmin' => $isAdmin,
            'rpId' => $this->webauthn->rpId(),
            'status' => session('status'),
        ]);
    }

    /**
     * Issue creation options. The challenge is stored server-side by the service.
     */
    public function registerOptions(Request $request): JsonResponse
    {
        $this->authorize('create', UserDevice::class);

        return response()->json($this->webauthn->registrationOptions($request->user()));
    }

    /**
     * Verify the attestation and persist the credential.
     */
    public function registerVerify(Request $request): JsonResponse
    {
        $this->authorize('create', UserDevice::class);

        $validated = $request->validate([
            'device_name' => ['required', 'string', 'max:100'],
            'credential' => ['required', 'array'],
        ]);

        try {
            $device = $this->webauthn->verifyRegistration(
                $request->user(),
                $validated['credential'],
                $validated['device_name'],
                $request,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        AuditLog::create([
            'user_id' => $request->user()->id,
            'auditable_type' => UserDevice::class,
            'auditable_id' => $device->id,
            'action' => 'device.registered',
            'new_values' => [
                'device_name' => $device->device_name,
                'rp_id' => $device->rp_id,
                'ip' => $request->ip(),
            ],
        ]);

        // Told to the owner, not just logged: an unexpected registration is the signal that a
        // session was hijacked, and only the owner can recognise it.
        $request->user()->notify(SystemNotification::deviceRegistered(
            $device->device_name,
            route('devices.index'),
        ));

        return response()->json([
            'message' => 'Device registered.',
            'device' => ['id' => $device->id, 'device_name' => $device->device_name],
        ]);
    }

    /**
     * Revoke a device. Never a hard delete — the row is audit evidence.
     */
    public function destroy(Request $request, UserDevice $device): RedirectResponse
    {
        $this->authorize('delete', $device);

        $byAdmin = $device->user_id !== $request->user()->id;

        if ($device->isActive()) {
            $device->revoke($byAdmin ? 'Revoked by an administrator.' : 'Revoked by the owner.');

            AuditLog::create([
                'user_id' => $request->user()->id,
                'auditable_type' => UserDevice::class,
                'auditable_id' => $device->id,
                'action' => 'device.revoked',
                'old_values' => ['status' => DeviceStatus::Active->value],
                'new_values' => ['status' => DeviceStatus::Revoked->value, 'ip' => $request->ip()],
            ]);

            $device->user?->notify(SystemNotification::deviceRevoked(
                $device->device_name,
                route('devices.index'),
            ));
        }

        return redirect()->route('devices.index')->with('status', 'Device revoked.');
    }
}
