<?php

namespace App\Providers;

use App\Enums\RoleName;
use App\Events\PermissionRequestApproved;
use App\Listeners\SyncAttendanceForApprovedPermission;
use App\Models\User;
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
    }
}
