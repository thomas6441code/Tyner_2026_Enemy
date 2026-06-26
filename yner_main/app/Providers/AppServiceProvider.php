<?php

namespace App\Providers;

use App\Events\PermissionRequestApproved;
use App\Listeners\SyncAttendanceForApprovedPermission;
use Illuminate\Support\Facades\Event;
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
    }
}
