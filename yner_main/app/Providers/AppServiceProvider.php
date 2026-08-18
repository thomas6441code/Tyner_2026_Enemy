<?php

namespace App\Providers;

use App\Enums\RoleName;
use App\Events\PermissionRequestApproved;
use App\Listeners\SyncAttendanceForApprovedPermission;
use App\Models\User;
use App\Services\WebAuthn\CredentialSourceRepository;
use App\Services\WebAuthnService;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\Denormalizer\WebauthnSerializerFactory;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The credential repository needs the library's serializer and the RP ID in force.
        // Both are resolved here so no call site constructs its own and drifts.
        $this->app->singleton(CredentialSourceRepository::class, function () {
            $serializer = (new WebauthnSerializerFactory(
                new AttestationStatementSupportManager([new NoneAttestationStatementSupport])
            ))->create();

            $rpId = config('webauthn.rp_id')
                ?: (parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost');

            return new CredentialSourceRepository($serializer, $rpId);
        });

        $this->app->singleton(WebAuthnService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Phase 6: approving a permission resyncs the employee's attendance for its dates.
        Event::listen(PermissionRequestApproved::class, SyncAttendanceForApprovedPermission::class);

        // Phase 10: attendance reports & exports are management decision-support (Admin + HR).
        Gate::define('viewReports', fn (User $user) => $user->hasAnyRole([
            RoleName::Admin->value,
            RoleName::HrOfficer->value,
        ]));

        // AI Settings stores the OpenRouter/LLM provider, model, and API key used for report
        // summarization — Admin only, since it controls AI spend and holds a secret key.
        Gate::define('manageAiSettings', fn (User $user) => $user->hasRole(RoleName::Admin->value));

        // Scramble: every documented /api route is guarded by the shared internal secret, so apply
        // it as a global API-key security scheme in the generated OpenAPI document (/docs/api).
        Scramble::configure()
            ->withDocumentTransformers(function (OpenApi $openApi) {
                $openApi->secure(
                    SecurityScheme::apiKey('header', 'X-Internal-Secret')
                );
            });
    }
}
