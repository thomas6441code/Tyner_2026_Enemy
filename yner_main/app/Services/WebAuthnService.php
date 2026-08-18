<?php

namespace App\Services;

use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\UserDevice;
use App\Notifications\SystemNotification;
use App\Services\WebAuthn\CredentialSourceRepository;
use Cose\Algorithm\Signature\ECDSA\ES256;
use Cose\Algorithm\Signature\RSA\RS256;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\SerializerInterface;
use Throwable;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\Exception\CounterException;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * The one and only place `web-auth/webauthn-lib` is touched.
 *
 * Everything the library exposes — CBOR decoding, COSE key parsing, ES256 signature
 * verification, rpIdHash / challenge / origin / user-presence checks — stays behind this
 * class. Two reasons:
 *
 *   1. The v4 → v5 ceremony API changed substantially and will change again at v6. Isolating
 *      it means an upgrade touches one file rather than every controller.
 *   2. None of this may be hand-rolled. A single skipped check in a hand-written
 *      implementation (a missing rpIdHash comparison, an unverified challenge) is a complete
 *      authentication bypass, not a partial one.
 *
 * What WebAuthn actually proves: that the private key held by a specific authenticator signed
 * this specific server-issued challenge, for this specific RP ID, with the user present and
 * verified. It proves *which device*. It says nothing about where that device is — see
 * GeofenceService for that half of the problem, and its threat model.
 */
class WebAuthnService
{
    private readonly SerializerInterface $serializer;

    private readonly AttestationStatementSupportManager $attestationSupport;

    public function __construct(private readonly CredentialSourceRepository $credentials)
    {
        // `none` attestation only: we do not verify authenticator provenance against the FIDO
        // MDS. We care that the device is consistent over time, not who manufactured it, and
        // requesting attestation would prompt users with an extra privacy dialog for data we
        // would then ignore.
        $this->attestationSupport = new AttestationStatementSupportManager([
            new NoneAttestationStatementSupport,
        ]);

        $this->serializer = (new WebauthnSerializerFactory($this->attestationSupport))->create();
    }

    /**
     * The relying party ID: the public registrable domain, no scheme and no port.
     *
     * Falls back to the APP_URL host, which is correct locally and wrong behind a proxy —
     * hence WEBAUTHN_RP_ID being the documented deployment step.
     */
    public function rpId(): string
    {
        $configured = config('webauthn.rp_id');

        if (filled($configured)) {
            return $configured;
        }

        return parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost';
    }

    public function isRequired(): bool
    {
        return (bool) config('webauthn.require', true);
    }

    /*
    |--------------------------------------------------------------------------
    | Registration ceremony
    |--------------------------------------------------------------------------
    */

    /**
     * Build creation options and stash them in the session.
     *
     * The challenge is 32 bytes from the CSPRNG and lives in the SESSION, never in a hidden
     * field or a client-supplied round-trip. A challenge the client can choose is not a
     * challenge — it lets an attacker replay a previously captured signature.
     *
     * @return array<string, mixed> the options, ready to hand to navigator.credentials.create()
     */
    public function registrationOptions(User $user): array
    {
        $options = PublicKeyCredentialCreationOptions::create(
            rp: new PublicKeyCredentialRpEntity(config('webauthn.rp_name'), $this->rpId()),
            user: $this->userEntity($user),
            challenge: random_bytes(32),
            pubKeyCredParams: [
                // ES256 first: it is what every platform authenticator actually produces.
                // RS256 is the fallback for older Windows Hello TPMs.
                PublicKeyCredentialParameters::createPk(ES256::ID),
                PublicKeyCredentialParameters::createPk(RS256::ID),
            ],
            authenticatorSelection: AuthenticatorSelectionCriteria::create(
                // `platform`: the phone's own fingerprint/face sensor, not a roaming USB key.
                // The whole design assumes the authenticator is the device in the employee's
                // hand at the work location.
                authenticatorAttachment: AuthenticatorSelectionCriteria::AUTHENTICATOR_ATTACHMENT_PLATFORM,
                // `required`: a biometric or PIN must be presented for every ceremony. Without
                // this, an unlocked phone in someone else's hand can check its owner in.
                userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
                residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_PREFERRED,
            ),
            attestation: PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            // Stops the same phone being registered twice, which would otherwise leave the
            // user with two credentials and no way to tell them apart in the devices list.
            excludeCredentials: $this->credentials->descriptorsFor($user),
            timeout: (int) config('webauthn.timeout_ms'),
        );

        return $this->stash(config('webauthn.session.registration'), $options);
    }

    /**
     * Verify an attestation and persist the resulting credential.
     *
     * @param  array<string, mixed>  $payload  the raw credential JSON from @simplewebauthn/browser
     *
     * @throws RuntimeException when there is no pending challenge or the ceremony fails
     */
    public function verifyRegistration(User $user, array $payload, string $deviceName, ?Request $request = null): UserDevice
    {
        $options = $this->pull(config('webauthn.session.registration'), PublicKeyCredentialCreationOptions::class);

        $credential = $this->deserializeCredential($payload);
        $response = $credential->response;

        if (! $response instanceof AuthenticatorAttestationResponse) {
            throw new RuntimeException('Expected an attestation response.');
        }

        $validator = AuthenticatorAttestationResponseValidator::create(
            (new CeremonyStepManagerFactory)->creationCeremony(),
        );

        try {
            $record = $validator->check($response, $options, $this->rpId());
        } catch (Throwable $e) {
            throw new RuntimeException('The device could not be registered: '.$e->getMessage(), previous: $e);
        }

        return UserDevice::create([
            'user_id' => $user->id,
            'credential_id' => $this->encodeId($record->publicKeyCredentialId),
            // The whole record, not just the key: verification later is a round-trip, never a
            // reconstruction from the scalar columns beside it.
            'public_key' => $this->serializer->serialize($record, 'json'),
            'sign_count' => $record->counter,
            'aaguid' => $record->aaguid->__toString(),
            'transports' => $record->transports,
            'attestation_type' => $record->attestationType,
            'rp_id' => $this->rpId(),
            'device_name' => $deviceName,
            'platform' => $request?->userAgent() ? Str::limit($request->userAgent(), 250, '') : null,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Assertion ceremony
    |--------------------------------------------------------------------------
    */

    /**
     * Build request options for an authentication ceremony.
     *
     * `allowCredentials` comes from the repository, so revoked devices are absent by
     * construction rather than by a filter someone has to remember to apply.
     *
     * @return array<string, mixed>
     */
    public function assertionOptions(User $user): array
    {
        $options = PublicKeyCredentialRequestOptions::create(
            challenge: random_bytes(32),
            rpId: $this->rpId(),
            allowCredentials: $this->credentials->descriptorsFor($user),
            userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            timeout: (int) config('webauthn.timeout_ms'),
        );

        return $this->stash(config('webauthn.session.assertion'), $options);
    }

    /**
     * Verify an assertion and return the device that produced it.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws RuntimeException on any failure — there is no partial success here
     */
    public function verifyAssertion(User $user, array $payload, ?Request $request = null): UserDevice
    {
        $options = $this->pull(config('webauthn.session.assertion'), PublicKeyCredentialRequestOptions::class);

        $credential = $this->deserializeCredential($payload);
        $response = $credential->response;

        if (! $response instanceof AuthenticatorAssertionResponse) {
            throw new RuntimeException('Expected an assertion response.');
        }

        // Scoped to the user, not merely to the credential ID: a signature that verifies is
        // only acceptable if the credential also belongs to the session's account.
        $device = $this->credentials->findDeviceForUser($user, $this->encodeId($credential->rawId));

        if ($device === null) {
            throw new RuntimeException('This device is not registered, or has been revoked.');
        }

        $record = $this->credentials->toCredentialRecord($device);

        $validator = AuthenticatorAssertionResponseValidator::create(
            (new CeremonyStepManagerFactory)->requestCeremony(),
        );

        try {
            $record = $validator->check(
                $record,
                $response,
                $options,
                $this->rpId(),
                $record->userHandle,
            );
        } catch (CounterException $e) {
            // SIGN-COUNT REGRESSION — the classic cloned-credential signal. A genuine
            // authenticator's counter only ever increases; a counter that goes backwards means
            // two things are signing with the same key.
            //
            // Note the library ALREADY skips this check when both the stored and incoming
            // counters are 0 (see Webauthn\CeremonyStep\CheckCounter). That case is not an
            // anomaly: Apple Touch/Face ID and most Android platform authenticators always
            // report 0, and a naive "new <= stored" check would lock out every iPhone user on
            // their second check-in. Do not "tighten" this.
            $this->handleCloneSuspicion($device, $e);

            throw new RuntimeException('This device has been revoked for security reasons.', previous: $e);
        } catch (Throwable $e) {
            throw new RuntimeException('The device could not be verified: '.$e->getMessage(), previous: $e);
        }

        $device->forceFill([
            'public_key' => $this->serializer->serialize($record, 'json'),
            'sign_count' => $record->counter,
            'last_used_at' => now(),
            'last_used_ip' => $request?->ip(),
        ])->save();

        return $device;
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * A cloned credential is a security incident, not a validation error: revoke first, then
     * make sure a human finds out.
     */
    private function handleCloneSuspicion(UserDevice $device, Throwable $e): void
    {
        $device->revoke('Sign counter regression — possible cloned credential.');

        AuditLog::create([
            'user_id' => $device->user_id,
            'auditable_type' => UserDevice::class,
            'auditable_id' => $device->id,
            'action' => 'device.clone_suspected',
            'old_values' => ['status' => 'active'],
            'new_values' => ['status' => 'revoked', 'sign_count' => $device->sign_count],
            'note' => $e->getMessage(),
        ]);

        $admins = User::role(RoleName::Admin->value)->get();

        if ($admins->isNotEmpty()) {
            Notification::send($admins, SystemNotification::deviceCloneSuspected(
                $device->user?->name ?? 'Unknown user',
                $device->device_name,
                route('devices.index'),
            ));
        }
    }

    /**
     * WebAuthn caps the user handle at 64 bytes, so the entity carries the numeric user ID
     * rather than an email — and the ID is a stable, non-PII handle besides.
     */
    private function userEntity(User $user): PublicKeyCredentialUserEntity
    {
        return PublicKeyCredentialUserEntity::create(
            $user->email,
            (string) $user->id,
            $user->name,
        );
    }

    /**
     * Serialize options for the browser and record them in the session for the matching
     * verify call.
     *
     * @return array<string, mixed>
     */
    private function stash(string $key, PublicKeyCredentialCreationOptions|PublicKeyCredentialRequestOptions $options): array
    {
        $json = $this->serializer->serialize($options, 'json', [
            AbstractObjectNormalizer::SKIP_NULL_VALUES => true,
        ]);

        Session::put($key, $json);

        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Load the pending options and DELETE them in the same breath.
     *
     * Single-use is the entire replay defence: without the removal, one captured assertion
     * could be submitted repeatedly against a challenge that never expires.
     *
     * @template T of PublicKeyCredentialCreationOptions|PublicKeyCredentialRequestOptions
     *
     * @param  class-string<T>  $class
     * @return T
     */
    private function pull(string $key, string $class)
    {
        $json = Session::pull($key);

        if (! is_string($json) || $json === '') {
            throw new RuntimeException('No pending WebAuthn challenge. Start the ceremony again.');
        }

        return $this->serializer->deserialize($json, $class, 'json');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function deserializeCredential(array $payload): PublicKeyCredential
    {
        try {
            return $this->serializer->deserialize(
                json_encode($payload, JSON_THROW_ON_ERROR),
                PublicKeyCredential::class,
                'json',
            );
        } catch (Throwable $e) {
            throw new RuntimeException('Malformed credential payload.', previous: $e);
        }
    }

    /**
     * Credential IDs are raw bytes; base64url is how they are stored and compared.
     */
    private function encodeId(string $rawId): string
    {
        return rtrim(strtr(base64_encode($rawId), '+/', '-_'), '=');
    }
}
