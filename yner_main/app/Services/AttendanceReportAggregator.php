<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Builds the detailed, named, per-employee attendance report for a date range (Phase 10).
 *
 * Unlike {@see MonthlyReportAggregator} — which produces the anonymized org-level payload sent to
 * the LLM — this aggregator is management decision-support (Admin/HR only) and therefore carries
 * employee names/codes. It reads the same `attendance_records` the Phase 6 sync engine overwrites,
 * so approved permissions surface as their leave status and never as a false "Absent".
 */
class AttendanceReportAggregator
{
    /**
     * @return array{
     *     meta: array<string, mixed>,
     *     rows: list<array<string, mixed>>,
     *     totals: array<string, mixed>,
     *     trend: array{labels: list<string>, values: list<int>, highlight: int},
     *     statusDistribution: array{labels: list<string>, values: list<int>, highlight: int}
     * }
     */
    public function report(Carbon $from, Carbon $to, ?Department $department = null): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->startOfDay();

        $employees = $this->scopedEmployees($department);
        $records = $this->records($employees->pluck('id'), $from, $to);
        $byEmployee = $records->groupBy('employee_id');

        $rows = $employees
            ->map(fn (Employee $e) => $this->employeeRow($e, $byEmployee->get($e->id) ?? collect()))
            ->values()
            ->all();

        return [
            'meta' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'from_label' => $from->format('d M Y'),
                'to_label' => $to->format('d M Y'),
                'scope' => $department ? $department->name.' department' : 'Organization-wide',
                'department_id' => $department?->id,
                'employees' => $employees->count(),
                'working_days' => $records->pluck('work_date')->map->toDateString()->unique()->count(),
                'generated_at' => Carbon::now()->format('d M Y H:i'),
            ],
            'rows' => $rows,
            'totals' => $this->totals($records, $employees->count()),
            'trend' => $this->trend($records, $from, $to),
            'statusDistribution' => $this->statusDistribution($records),
        ];
    }

    /**
     * @return Collection<int, Employee>
     */
    private function scopedEmployees(?Department $department): Collection
    {
        $query = Employee::with('department:id,name')->where('status', 'active');

        if ($department) {
            $query->where('department_id', $department->id);
        }

        return $query->orderBy('first_name')->orderBy('last_name')->get();
    }

    /**
     * @param  Collection<int, int>  $employeeIds
     * @return Collection<int, AttendanceRecord>
     */
    private function records(Collection $employeeIds, Carbon $from, Carbon $to): Collection
    {
        if ($employeeIds->isEmpty()) {
            return collect();
        }

        return AttendanceRecord::whereIn('employee_id', $employeeIds)
            ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])
            ->get(['employee_id', 'work_date', 'status', 'worked_minutes', 'late_minutes']);
    }

    /**
     * @param  Collection<int, AttendanceRecord>  $records
     * @return array<string, mixed>
     */
    private function employeeRow(Employee $employee, Collection $records): array
    {
        $counts = $this->counts($records);
        $total = $records->count();
        $workedMinutes = (int) $records->sum('worked_minutes');
        $lateMinutes = (int) $records->sum('late_minutes');

        return [
            'id' => $employee->id,
            'employee_code' => $employee->employee_code,
            'name' => $employee->fullName(),
            'department' => $employee->department?->name ?? '—',
            'present' => $counts[AttendanceStatus::Present->value] ?? 0,
            'late' => $counts[AttendanceStatus::Late->value] ?? 0,
            'absent' => $counts[AttendanceStatus::Absent->value] ?? 0,
            'leave' => $this->leaveTotal($counts),
            'leave_breakdown' => $this->leaveBreakdown($counts),
            'worked_hours' => round($workedMinutes / 60, 1),
            'late_minutes' => $lateMinutes,
            'attendance_rate' => $this->rate($total - ($counts[AttendanceStatus::Absent->value] ?? 0), $total),
            'punctuality_rate' => $this->punctuality($counts),
        ];
    }

    /**
     * Status → count map for one record set.
     *
     * @param  Collection<int, AttendanceRecord>  $records
     * @return array<string, int>
     */
    private function counts(Collection $records): array
    {
        $counts = [];
        foreach ($records as $record) {
            $key = $record->status->value;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function leaveTotal(array $counts): int
    {
        $total = 0;
        foreach (AttendanceStatus::cases() as $status) {
            if ($status->isLeave()) {
                $total += $counts[$status->value] ?? 0;
            }
        }

        return $total;
    }

    /**
     * @param  array<string, int>  $counts
     * @return array<string, int>
     */
    private function leaveBreakdown(array $counts): array
    {
        $breakdown = [];
        foreach (AttendanceStatus::cases() as $status) {
            if ($status->isLeave() && ($counts[$status->value] ?? 0) > 0) {
                $breakdown[$status->label()] = $counts[$status->value];
            }
        }

        return $breakdown;
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function punctuality(array $counts): float
    {
        $present = $counts[AttendanceStatus::Present->value] ?? 0;
        $late = $counts[AttendanceStatus::Late->value] ?? 0;
        $worked = $present + $late;

        return $this->rate($present, $worked);
    }

    /**
     * Organization roll-up across every record in scope.
     *
     * @param  Collection<int, AttendanceRecord>  $records
     * @return array<string, mixed>
     */
    private function totals(Collection $records, int $employeeCount): array
    {
        $counts = $this->counts($records);
        $total = $records->count();
        $absent = $counts[AttendanceStatus::Absent->value] ?? 0;

        return [
            'employees' => $employeeCount,
            'records' => $total,
            'present' => $counts[AttendanceStatus::Present->value] ?? 0,
            'late' => $counts[AttendanceStatus::Late->value] ?? 0,
            'absent' => $absent,
            'leave' => $this->leaveTotal($counts),
            'worked_hours' => round((int) $records->sum('worked_minutes') / 60, 1),
            'late_minutes' => (int) $records->sum('late_minutes'),
            'attendance_rate' => $this->rate($total - $absent, $total),
            'punctuality_rate' => $this->punctuality($counts),
        ];
    }

    /**
     * Daily attendance-rate (%) series across the range, for the trend line chart. Days with no
     * records are held at the previous value so the line stays continuous.
     *
     * @param  Collection<int, AttendanceRecord>  $records
     * @return array{labels: list<string>, values: list<int>, highlight: int}
     */
    private function trend(Collection $records, Carbon $from, Carbon $to): array
    {
        // Cap the number of plotted points so long ranges stay readable (~14 buckets).
        $totalDays = (int) $from->diffInDays($to) + 1;
        $step = max(1, (int) ceil($totalDays / 14));

        $labels = [];
        $values = [];
        for ($cursor = $from->copy(); $cursor->lte($to); $cursor->addDays($step)) {
            $bucketEnd = $cursor->copy()->addDays($step - 1)->min($to);
            $slice = $records->filter(
                fn (AttendanceRecord $r) => $r->work_date->betweenIncluded($cursor, $bucketEnd)
            );
            $count = $slice->count();
            $absent = $slice->where('status', AttendanceStatus::Absent)->count();

            $labels[] = $cursor->format('M j');
            $values[] = $count > 0 ? (int) round((($count - $absent) / $count) * 100) : 0;
        }

        $highlight = $values === [] ? 0 : (int) array_keys($values, max($values))[0];

        return ['labels' => $labels, 'values' => $values, 'highlight' => $highlight];
    }

    /**
     * Totals per status for the distribution bar chart (only statuses that occur).
     *
     * @param  Collection<int, AttendanceRecord>  $records
     * @return array{labels: list<string>, values: list<int>, highlight: int}
     */
    private function statusDistribution(Collection $records): array
    {
        $counts = $this->counts($records);

        $labels = [];
        $values = [];
        foreach (AttendanceStatus::cases() as $status) {
            $count = $counts[$status->value] ?? 0;
            if ($count > 0) {
                $labels[] = $status->label();
                $values[] = $count;
            }
        }

        $highlight = $values === [] ? 0 : (int) array_keys($values, max($values))[0];

        return ['labels' => $labels, 'values' => $values, 'highlight' => $highlight];
    }

    private function rate(int $numerator, int $denominator): float
    {
        return $denominator > 0 ? round(($numerator / $denominator) * 100, 1) : 0.0;
    }
}
