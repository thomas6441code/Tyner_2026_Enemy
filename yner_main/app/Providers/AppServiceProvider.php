<?php

namespace App\Providers;

use App\Enums\RoleName;
use App\Events\PermissionRequestApproved;
use App\Listeners\SyncAttendanceForApprovedPermission;
use App\Models\User;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
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
