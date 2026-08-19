<?php

namespace App\Services\WebAuthn;

use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Serializer\SerializerInterface;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredentialDescriptor;

/**
 * The single place credentials are loaded from storage.
 *
 * Revocation and RP-ID binding are enforced HERE rather than at each call site, so no future
 * caller can forget them: a revoked device is simply not findable, and neither is a credential
 * created under a different domain. That is the point of routing every lookup through one
 * class instead of querying UserDevice directly.
 */
class CredentialSourceRepository
{
    public function __construct(
        private readonly SerializerInterface $serializer,
        private readonly string $rpId,
    ) {}

    /**
     * Find one usable credential by its (base64url) credential ID.
     */
    public function findDevice(string $credentialId): ?UserDevice
    {
        return UserDevice::query()->usable($this->rpId)
            ->where('credential_id', $credentialId)
            ->first();
    }

    /**
     * Find a usable credential belonging to a specific user.
     *
     * Scoping to the user prevents a valid assertion from one account being replayed against
     * another: the credential must both verify AND belong to the session's user.
     */
    public function findDeviceForUser(User $user, string $credentialId): ?UserDevice
    {
        return UserDevice::query()->usable($this->rpId)
            ->where('user_id', $user->id)
            ->where('credential_id', $credentialId)
            ->first();
    }

    /**
     * @return Collection<int, UserDevice>
     */
    public function usableDevicesFor(User $user): Collection
    {
        return UserDevice::query()->usable($this->rpId)
            ->where('user_id', $user->id)
            ->orderBy('id')
            ->get();
    }

    /**
     * Rehydrate the stored credential record for verification.
     *
     * The whole record was serialized on registration precisely so this is a round-trip rather
     * than a reconstruction from scattered columns.
     */
    public function toCredentialRecord(UserDevice $device): CredentialRecord
    {
        return $this->serializer->deserialize($device->public_key, CredentialRecord::class, 'json');
    }

    /**
     * Descriptors for `excludeCredentials` (registration) and `allowCredentials` (assertion).
     *
     * Because this reads through `usableDevicesFor()`, a revoked device is automatically
     * absent from both lists.
     *
     * @return array<int, PublicKeyCredentialDescriptor>
     */
    public function descriptorsFor(User $user): array
    {
        return $this->toDescriptors($this->usableDevicesFor($user));
    }

    /**
     * Descriptors for EVERY active credential in the system, for `excludeCredentials`.
     *
     * This is the hardware half of the one-account-one-device rule. Handed the full list, a
     * platform authenticator that already holds any of these credentials aborts registration
     * itself with InvalidStateError — so a phone linked to one account physically cannot mint
     * a passkey for a second. The check happens inside the secure element, below anything a
     * tampered client could reach.
     *
     * Privacy: credential IDs are opaque random bytes, not identifiers of people, and the list
     * only ever reaches an authenticated employee who is mid-registration. The one thing it
     * leaks is a rough count of enrolled devices, which is acceptable for what it buys.
     *
     * Ordering is most-recently-used first so that if `exclude_limit` ever truncates, the
     * credentials actually in circulation are the ones still covered.
     *
     * @return array<int, PublicKeyCredentialDescriptor>
     */
    public function allActiveDescriptors(): array
    {
        $limit = max(1, (int) config('webauthn.exclude_limit', 500));

        $total = UserDevice::query()->usable($this->rpId)->count();

        if ($total > $limit) {
            // Not fatal, but it means the guarantee has holes: some already-linked phone is no
            // longer being excluded and could enroll for a second account.
            Log::warning('WebAuthn excludeCredentials truncated; raise WEBAUTHN_EXCLUDE_LIMIT.', [
                'active_credentials' => $total,
                'limit' => $limit,
            ]);
        }

        $devices = UserDevice::query()->usable($this->rpId)
            ->orderByRaw('last_used_at is null')
            ->orderByDesc('last_used_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return $this->toDescriptors($devices);
    }

    /**
     * @param  Collection<int, UserDevice>  $devices
     * @return array<int, PublicKeyCredentialDescriptor>
     */
    private function toDescriptors(Collection $devices): array
    {
        return $devices
            ->map(fn (UserDevice $device) => PublicKeyCredentialDescriptor::create(
                PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                $this->toCredentialRecord($device)->publicKeyCredentialId,
                $device->transports ?? [],
            ))
            ->values()
            ->all();
    }
}
