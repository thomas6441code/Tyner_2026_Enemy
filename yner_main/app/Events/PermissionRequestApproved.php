<?php

namespace App\Events;

use App\Models\PermissionRequest;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when HR/Admin approves a permission request.
 *
 * Phase 5 dispatches this but registers no listener. The Phase 6 sync engine attaches a
 * queued listener here to overwrite the matching `attendance_records` (Absent/null → the
 * approved leave status) for the request's date range.
 */
class PermissionRequestApproved
{
    use Dispatchable, SerializesModels;

    public function __construct(public PermissionRequest $permissionRequest)
    {
    }
}
