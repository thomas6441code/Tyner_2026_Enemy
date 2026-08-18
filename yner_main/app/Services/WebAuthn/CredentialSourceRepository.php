<?php

namespace App\Services\WebAuthn;

use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Support\Collection;
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
        return $this->usableDevicesFor($user)
            ->map(fn (UserDevice $device) => PublicKeyCredentialDescriptor::create(
                PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                $this->toCredentialRecord($device)->publicKeyCredentialId,
                $device->transports ?? [],
            ))
            ->values()
            ->all();
    }
}
