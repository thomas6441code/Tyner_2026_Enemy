<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Models\AiAnomaly;
use App\Models\AiPrediction;
use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Builds the aggregated, anonymized monthly statistics that Laravel sends to the AI service for
 * LLM narration (Phase 8, feature 6.6.5).
 *
 * Privacy guardrail: the returned payload contains only counts, rates and a department label —
 * never employee names or per-person rows. This is the *exact* structure persisted in
 * `report_summaries.stats`, so the team can prove what left the system for the defense.
 */
class MonthlyReportAggregator
{
    /**
     * @return array<string, mixed>
     */
    public function aggregate(Carbon $month, ?Department $department = null): array
    {
        $month = $month->copy()->startOfMonth();
        $employeeIds = $this->scopeEmployeeIds($department);

        $current = $this->windowStats($month, $employeeIds);
        $previous = $this->windowStats($month->copy()->subMonthNoOverflow(), $employeeIds);

        return [
            'period_label' => $month->format('F Y'),
            'scope' => $department ? $department->name.' department' : 'Organization-wide',
            'headcount' => $employeeIds->count(),
            'working_days' => $current['working_days'],
            'status_counts' => $current['status_counts'],
            'leave_breakdown' => $current['leave_breakdown'],
            'attendance_rate' => $current['attendance_rate'],
            'punctuality_rate' => $current['punctuality_rate'],
            'absence_rate' => $current['absence_rate'],
            'late_incidents' => $current['late_incidents'],
            'avg_late_minutes' => $current['avg_late_minutes'],
            'anomalies_count' => $this->anomaliesCount($month, $employeeIds),
            'high_risk_count' => $this->highRiskCount($employeeIds),
            'prev_attendance_rate' => $previous['has_data'] ? $previous['attendance_rate'] : null,
        ];
    }

    /**
     * @return Collection<int, int>
     */
    private function scopeEmployeeIds(?Department $department): Collection
    {
        $query = Employee::query()->where('status', 'active');

        if ($department) {
            $query->where('department_id', $department->id);
        }

        return $query->pluck('id');
    }

    /**
     * Core attendance rates for one month window. Reused for the previous month (trend).
     *
     * @param  Collection<int, int>  $employeeIds
     * @return array<string, mixed>
     */
    private function windowStats(Carbon $month, Collection $employeeIds): array
    {
        $start = $month->copy()->startOfMonth()->toDateString();
        $end = $month->copy()->endOfMonth()->toDateString();

        $records = AttendanceRecord::whereIn('employee_id', $employeeIds)
            ->whereBetween('work_date', [$start, $end])
            ->get(['status', 'work_date', 'late_minutes']);

        $total = $records->count();

        $statusCounts = [];
        $leaveBreakdown = [];
        foreach (AttendanceStatus::cases() as $status) {
            $count = $records->where('status', $status)->count();
            if ($count > 0) {
                $statusCounts[$status->value] = $count;
                if ($status->isLeave()) {
                    $leaveBreakdown[$status->value] = $count;
                }
            }
        }

        $absent = $statusCounts[AttendanceStatus::Absent->value] ?? 0;
        $present = $statusCounts[AttendanceStatus::Present->value] ?? 0;
        $late = $statusCounts[AttendanceStatus::Late->value] ?? 0;
        $worked = $present + $late;

        $lateRecords = $records->where('late_minutes', '>', 0);
        $lateIncidents = $lateRecords->count();

        return [
            'has_data' => $total > 0,
            'working_days' => $records->pluck('work_date')->unique()->count(),
            'status_counts' => $statusCounts,
            'leave_breakdown' => $leaveBreakdown,
            'attendance_rate' => $total > 0 ? round(($total - $absent) / $total, 4) : 0.0,
            'punctuality_rate' => $worked > 0 ? round($present / $worked, 4) : 0.0,
            'absence_rate' => $total > 0 ? round($absent / $total, 4) : 0.0,
            'late_incidents' => $lateIncidents,
            'avg_late_minutes' => $lateIncidents > 0
                ? round((float) $lateRecords->avg('late_minutes'), 1)
                : 0.0,
        ];
    }

    /**
     * @param  Collection<int, int>  $employeeIds
     */
    private function anomaliesCount(Carbon $month, Collection $employeeIds): int
    {
        return AiAnomaly::whereIn('employee_id', $employeeIds)
            ->whereBetween('work_date', [
                $month->copy()->startOfMonth()->toDateString(),
                $month->copy()->endOfMonth()->toDateString(),
            ])
            ->count();
    }

    /**
     * Employees currently flagged high absenteeism-risk (latest snapshot from Phase 7 scoring).
     *
     * @param  Collection<int, int>  $employeeIds
     */
    private function highRiskCount(Collection $employeeIds): int
    {
        return AiPrediction::whereIn('employee_id', $employeeIds)
            ->where('risk_level', 'high')
            ->count();
    }
}
