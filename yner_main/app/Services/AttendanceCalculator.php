<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\RawAttendanceLog;
use App\Models\WorkSchedule;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AttendanceCalculator
{
    /**
     * Compute daily attendance records for every active employee on a single date.
     *
     * Idempotent: re-running for the same date overwrites the computed record via
     * updateOrCreate on (employee_id, work_date). Records flagged `is_manual` (an HR
     * correction) are left untouched and counted as skipped.
     *
     * @return array{computed: int, skipped: int}
     */
    public function computeForDate(CarbonInterface $date): array
    {
        $date = $date->copy()->startOfDay();

        $punchesByEmployee = $this->punchesByEmployee($date);

        $employees = Employee::where('status', 'active')->with('workSchedule')->get();

        $computed = 0;
        $skipped = 0;

        foreach ($employees as $employee) {
            $existing = AttendanceRecord::where('employee_id', $employee->id)
                ->where('work_date', $date->toDateString())
                ->first();

            if ($existing && $existing->is_manual) {
                $skipped++;

                continue;
            }

            $punches = ($punchesByEmployee[$employee->id] ?? collect())->sort()->values();

            // A permission-backed leave day with no punch is owned by the sync engine —
            // preserve its leave status instead of recomputing it back to Absent.
            if ($existing && $existing->permission_request_id !== null && $punches->isEmpty()) {
                $skipped++;

                continue;
            }

            $attributes = $this->deriveAttributes($punches, $employee->workSchedule, $date);

            // A punch on a previously permission-backed day means the employee actually showed
            // up — reality wins: recompute normally and drop the stale permission link.
            if ($existing && $existing->permission_request_id !== null && $punches->isNotEmpty()) {
                $attributes['permission_request_id'] = null;
            }

            AttendanceRecord::updateOrCreate(
                ['employee_id' => $employee->id, 'work_date' => $date->toDateString()],
                $attributes,
            );

            $computed++;
        }

        $this->markProcessed($date);

        return ['computed' => $computed, 'skipped' => $skipped];
    }

    /**
     * Compute attendance for every day in an inclusive date range.
     *
     * @return array{computed: int, skipped: int}
     */
    public function computeForRange(CarbonInterface $from, CarbonInterface $to): array
    {
        $cursor = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();

        $totals = ['computed' => 0, 'skipped' => 0];

        while ($cursor->lessThanOrEqualTo($end)) {
            $result = $this->computeForDate($cursor);
            $totals['computed'] += $result['computed'];
            $totals['skipped'] += $result['skipped'];
            $cursor->addDay();
        }

        return $totals;
    }

    /**
     * Resolve the date's raw punches to employees and group their punch times.
     *
     * Raw logs key on `device_serial`; enrollments key on `biometric_device_id`, so we
     * build a "{serial}|{device_user_id}" => employee_id lookup once.
     *
     * @return array<int, Collection<int, Carbon>>
     */
    private function punchesByEmployee(CarbonInterface $date): array
    {
        $lookup = DB::table('device_enrollments')
            ->join('biometric_devices', 'device_enrollments.biometric_device_id', '=', 'biometric_devices.id')
            ->get(['biometric_devices.serial', 'device_enrollments.device_user_id', 'device_enrollments.employee_id'])
            ->keyBy(fn ($row) => $row->serial.'|'.$row->device_user_id);

        $logs = RawAttendanceLog::whereDate('punched_at', $date)->get();

        $grouped = [];

        foreach ($logs as $log) {
            $key = $log->device_serial.'|'.$log->device_user_id;
            $employeeId = $lookup->get($key)?->employee_id;

            if ($employeeId === null) {
                continue;
            }

            $grouped[$employeeId] ??= collect();
            $grouped[$employeeId]->push($log->punched_at);
        }

        return $grouped;
    }

    /**
     * Derive the computed attendance attributes from a day's punches and schedule.
     *
     * @param  Collection<int, Carbon>  $punches
     * @return array<string, mixed>
     */
    private function deriveAttributes(Collection $punches, ?WorkSchedule $schedule, CarbonInterface $date): array
    {
        if ($punches->isEmpty()) {
            return [
                'status' => AttendanceStatus::Absent,
                'first_in' => null,
                'last_out' => null,
                'worked_minutes' => null,
                'late_minutes' => 0,
                'early_leave_minutes' => 0,
            ];
        }

        $firstIn = $punches->first();
        $lastOut = $punches->count() > 1 ? $punches->last() : null;

        $workedMinutes = $lastOut
            ? (int) round(($lastOut->getTimestamp() - $firstIn->getTimestamp()) / 60)
            : null;

        $lateMinutes = 0;
        $earlyLeaveMinutes = 0;

        if ($schedule) {
            $expectedStart = Carbon::parse($date->toDateString().' '.$schedule->start_time)
                ->addMinutes((int) $schedule->grace_period_minutes);
            $lateMinutes = max(0, (int) round(($firstIn->getTimestamp() - $expectedStart->getTimestamp()) / 60));

            if ($lastOut) {
                $expectedEnd = Carbon::parse($date->toDateString().' '.$schedule->end_time);
                $earlyLeaveMinutes = max(0, (int) round(($expectedEnd->getTimestamp() - $lastOut->getTimestamp()) / 60));
            }
        }

        return [
            'status' => $lateMinutes > 0 ? AttendanceStatus::Late : AttendanceStatus::Present,
            'first_in' => $firstIn,
            'last_out' => $lastOut,
            'worked_minutes' => $workedMinutes,
            'late_minutes' => $lateMinutes,
            'early_leave_minutes' => $earlyLeaveMinutes,
        ];
    }

    /**
     * Stamp processed_at on the date's consumed raw logs for traceability. Recompute
     * re-reads all logs for the date regardless, so this never affects idempotency.
     */
    private function markProcessed(CarbonInterface $date): void
    {
        RawAttendanceLog::whereDate('punched_at', $date)
            ->whereNull('processed_at')
            ->update(['processed_at' => now()]);
    }
}
