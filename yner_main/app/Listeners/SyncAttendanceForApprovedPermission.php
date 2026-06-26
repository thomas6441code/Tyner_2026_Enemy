<?php

namespace App\Listeners;

use App\Events\PermissionRequestApproved;
use App\Services\AttendanceSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Phase 6 sync seam: when HR approves a permission, overlay the approved leave status onto
 * the employee's attendance for the request's date range.
 *
 * Queued so the approval HTTP response stays fast and the sync is retriable. Under the
 * `sync` queue driver (tests, local dev) it runs inline, so an approval flips attendance
 * within the same request.
 */
class SyncAttendanceForApprovedPermission implements ShouldQueue
{
    public function __construct(private AttendanceSyncService $sync) {}

    public function handle(PermissionRequestApproved $event): void
    {
        $this->sync->syncForApproval($event->permissionRequest);
    }
}
