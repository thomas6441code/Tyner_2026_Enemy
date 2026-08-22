<?php

namespace App\Http\Controllers;

use App\Enums\DeviceResetStatus;
use App\Enums\DeviceStatus;
use App\Enums\RoleName;
use App\Http\Concerns\HasIndexFilters;
use App\Models\AuditLog;
use App\Models\DeviceResetRequest;
use App\Models\User;
use App\Models\UserDevice;
use App\Notifications\SystemNotification;
use App\Services\DeviceTokenService;
use App\Services\WebAuthnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Registration and lifecycle of the one WebAuthn platform authenticator bound to an account.
 *
 * The two ceremony endpoints return JSON rather than Inertia responses: they are called with
 * fetch() from inside the WebAuthn handler chain, not as page visits.
 */
class UserDeviceController extends Controller
{
    use HasIndexFilters;

    /**
     * @return array<string, mixed>
     */
    private function sortable(): array
    {
        return [
            'device_name' => 'device_name',
            'owner' => User::select('name')->whereColumn('users.id', 'user_devices.user_id'),
            'status' => 'status',
            'last_used_at' => 'last_used_at',
            'registered_at' => 'created_at',
        ];
    }

    public function __construct(
        private readonly WebAuthnService $webauthn,
        private readonly DeviceTokenService $deviceTokens,
    ) {}

    /**
     * The employee's own device; Admins additionally see everyone's.
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', UserDevice::class);

        $user = $request->user();
        $isAdmin = $user->hasRole(RoleName::Admin->value);

        $filters = $this->indexFilters($request, $this->sortable(), 'registered_at', 'desc');

        $query = UserDevice::with('user')
            ->when(! $isAdmin, fn ($q) => $q->where('user_id', $user->id));

        // An Admin looks a device up by whose it is; the owner themselves by what they called
        // it. `user.name`/`user.email` are only ever reachable once the scope above has already
        // narrowed the set, so this cannot widen what an employee sees.
        $this->applySearch($query, $filters['search'], [
            'device_name', 'rp_id', 'user.name', 'user.email',
        ]);

        // Active devices first by default; a chosen column takes over completely.
        if (! $filters['explicit']) {
            $query->orderByRaw("status = 'active' desc");
        }

        $this->applySort($query, $filters, $this->sortable());

        $devices = $query
            ->paginate(15)
            ->withQueryString()
            ->through(fn (UserDevice $device) => [
                'id' => $device->id,
                'device_name' => $device->device_name,
                'owner' => $device->user?->name,
                'is_own' => $device->user_id === $user->id,
                // The device currently holding its owner's single binding slot, as opposed to
                // one that is merely un-revoked. Under the unique index these are the same
                // thing; surfacing it explicitly means the UI never has to infer it.
                'is_linked' => $device->active_user_id !== null,
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

        $canRegister = $user->can('create', UserDevice::class);
        $myDevice = UserDevice::boundTo($user->id);
        $openReset = DeviceResetRequest::query()->where('user_id', $user->id)->usable()->first();
        $pendingReset = DeviceResetRequest::query()
            ->where('user_id', $user->id)
            ->where('status', DeviceResetStatus::Pending)
            ->latest()
            ->first();

        return Inertia::render('devices/index', [
            'devices' => $devices,
            'filters' => $filters,
            'stats' => [
                'active' => UserDevice::where('status', DeviceStatus::Active)
                    ->when(! $isAdmin, fn ($q) => $q->where('user_id', $user->id))->count(),
                'revoked' => UserDevice::where('status', DeviceStatus::Revoked)
                    ->when(! $isAdmin, fn ($q) => $q->where('user_id', $user->id))->count(),
                'mine' => $myDevice !== null ? 1 : 0,
            ],
            // Everything the employee-facing card needs to decide what to offer: register,
            // request a reset, or simply wait for a decision.
            'binding' => [
                'has_device' => $myDevice !== null,
                'device_name' => $myDevice?->device_name,
                'last_used_at' => $myDevice?->last_used_at?->toDateTimeString(),
                'registered_at' => $myDevice?->created_at?->toDateTimeString(),
                'reset_pending' => $pendingReset !== null,
                'reset_approved_until' => $openReset?->approved_until?->toDateTimeString(),
            ],
            'actions' => [
                'register' => $canRegister,
                // Offered whenever the employee cannot register, not merely when they still
                // hold a device. Someone whose phone an Admin revoked has no device AND no
                // approval, and without this they would be stranded — unable to link a
                // replacement and unable to ask for one.
                'requestReset' => ! $canRegister
                    && $pendingReset === null
                    && $user->can('create', DeviceResetRequest::class),
                'reviewResets' => $user->can('viewAny', DeviceResetRequest::class),
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
     * Verify the attestation, bind the device to this account, and mint its binding token.
     */
    public function registerVerify(Request $request): JsonResponse
    {
        $this->authorize('create', UserDevice::class);

        $validated = $request->validate([
            'device_name' => ['required', 'string', 'max:100'],
            'credential' => ['required', 'array'],
        ]);

        // Before the ceremony: is the handset already spoken for? The authenticator should have
        // refused this at excludeCredentials, so reaching here means either the passkey was
        // deleted and re-created, or the client is not behaving. Either way it is the signal
        // the binding exists to catch, so it is refused loudly rather than quietly.
        if ($conflict = $this->conflictingDevice($request)) {
            return $this->refuseLinkConflict($request, $conflict);
        }

        try {
            $result = DB::transaction(function () use ($request, $validated) {
                $device = $this->webauthn->verifyRegistration(
                    $request->user(),
                    $validated['credential'],
                    $validated['device_name'],
                    $request,
                );

                // Spend the reset approval inside the same transaction that consumes it. If the
                // device write fails, the approval must still be there; if it succeeds, the
                // approval must be gone. Anything else lets one approval buy two devices.
                $this->consumeResetApproval($request->user());

                return [$device, $this->deviceTokens->issue($device)];
            });
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        [$device, $token] = $result;

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

        $this->deviceTokens->queueCookie($token);

        return response()->json([
            'message' => 'Device linked to your account.',
            'device' => ['id' => $device->id, 'device_name' => $device->device_name],
            // Returned exactly once, for the client to mirror into localStorage. The cookie
            // above carries the same value; two stores because browsers clear them separately.
            'device_token' => $token,
        ]);
    }

    /**
     * Revoke a device. Never a hard delete — the row is audit evidence.
     *
     * Admin-only by policy. An owner who has lost their phone files a device reset request
     * instead; see UserDevicePolicy::delete for why self-revocation is not offered.
     */
    public function destroy(Request $request, UserDevice $device): RedirectResponse
    {
        $this->authorize('delete', $device);

        if ($device->isActive()) {
            $device->revoke('Revoked by an administrator.');

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

    /**
     * A device already bound to somebody else, identified by the token this handset presented.
     */
    private function conflictingDevice(Request $request): ?UserDevice
    {
        $existing = $this->deviceTokens->resolve($this->deviceTokens->presented($request));

        return $existing !== null && $existing->user_id !== $request->user()->id ? $existing : null;
    }

    private function refuseLinkConflict(Request $request, UserDevice $conflict): JsonResponse
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'auditable_type' => UserDevice::class,
            'auditable_id' => $conflict->id,
            'action' => 'device.link_conflict',
            'new_values' => [
                'bound_to_user_id' => $conflict->user_id,
                'attempted_by_user_id' => $request->user()->id,
                'ip' => $request->ip(),
            ],
        ]);

        $admins = User::role(RoleName::Admin->value)->get();

        if ($admins->isNotEmpty()) {
            Notification::send($admins, SystemNotification::deviceLinkConflict(
                $request->user()->name,
                $conflict->user?->name ?? 'another account',
                route('devices.index'),
            ));
        }

        return response()->json([
            'message' => 'This phone is already linked to another account. One device can only be linked to one account.',
        ], 422);
    }

    /**
     * Mark the reset approval that permitted this registration as spent.
     *
     * `lockForUpdate` because two concurrent registrations would otherwise both read the same
     * unspent approval. The unique index on `active_user_id` would stop the second device from
     * being created anyway, but leaving a spent approval looking unspent is its own bug.
     *
     * A first-ever registration has no approval to consume, which is why this is a no-op rather
     * than an error when nothing is found.
     */
    private function consumeResetApproval(User $user): void
    {
        DeviceResetRequest::query()
            ->where('user_id', $user->id)
            ->usable()
            ->lockForUpdate()
            ->first()
            ?->forceFill(['used_at' => now()])
            ->save();
    }
}
