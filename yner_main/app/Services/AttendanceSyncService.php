<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\PermissionRequest;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The core innovation: synchronize HR-approved permissions onto computed attendance so an
 * approved absence never shows as "Absent"/null.
 *
 * On approval, for each date in the request's range, this overlays the leave status the
 * request maps to (via PermissionType::toAttendanceStatus) onto attendance_records, subject
 * to the conflict rules below. It is idempotent, retroactive, and fully audit-logged.
 *
 * Conflict resolution (per date):
 *   1. is_manual record         → skip (HR's explicit correction is final).
 *   2. real punch present       → skip (employee was physically there; never erase reality).
 *   3. Absent/null/no record    → overlay the leave status + link the permission.
 *   4. already synced identically → no-op skip (idempotency, no audit spam).
 */
class AttendanceSyncService
{
    /**
     * Overlay an approved permission onto attendance for every date it covers.
     *
     * @return array{created: int, updated: int, skipped: int}
     */
    public function syncForApproval(PermissionRequest $request): array
    {
        $employeeId = $request->employee_id;
        $status = $request->type->toAttendanceStatus();

        $totals = ['created' => 0, 'updated' => 0, 'skipped' => 0];

        DB::transaction(function () use ($request, $employeeId, $status, &$totals) {
            $cursor = $request->start_date->copy()->startOfDay();
            $end = $request->end_date->copy()->startOfDay();

            while ($cursor->lessThanOrEqualTo($end)) {
                $outcome = $this->applyForDate($employeeId, $cursor, $status, $request);
                $totals[$outcome]++;
                $cursor->addDay();
            }
        });

        return $totals;
    }

    /**
     * Apply the conflict rules for a single date and persist the overlay if warranted.
     *
     * @return 'created'|'updated'|'skipped'
     */
    private function applyForDate(int $employeeId, CarbonInterface $date, AttendanceStatus $status, PermissionRequest $request): string
    {
        $record = AttendanceRecord::firstOrNew([
            'employee_id' => $employeeId,
            'work_date' => $date->toDateString(),
        ]);

        // Rule 1: HR's explicit manual correction outranks an automated overlay.
        if ($record->exists && $record->is_manual) {
            return 'skipped';
        }

        // Rule 2: a real punch means the employee was physically present — keep reality.
        if ($record->exists && $record->first_in !== null
            && in_array($record->status, [AttendanceStatus::Present, AttendanceStatus::Late], true)) {
            return 'skipped';
        }

        // Rule 4: already synced to this exact status + request — nothing to do.
        if ($record->exists
            && $record->status === $status
            && $record->permission_request_id === $request->id) {
            return 'skipped';
        }

        $wasNew = ! $record->exists;
        $old = $wasNew ? null : [
            'status' => $record->status->value,
            'permission_request_id' => $record->permission_request_id,
        ];

        $record->fill([
            'status' => $status,
            'permission_request_id' => $request->id,
            'is_manual' => false,
            'remarks' => "Synced from approved {$request->type->label()} (request #{$request->id}).",
        ]);
        $record->save();

        AuditLog::create([
            'user_id' => $request->reviewed_by,
            'auditable_type' => AttendanceRecord::class,
            'auditable_id' => $record->id,
            'action' => 'attendance.synced',
            'old_values' => $old,
            'new_values' => [
                'status' => $status->value,
                'permission_request_id' => $request->id,
            ],
            'note' => "Approved {$request->type->label()} for {$date->toDateString()}.",
        ]);

        return $wasNew ? 'created' : 'updated';
    }
}
