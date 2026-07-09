<?php

namespace App\Http\Controllers;

use App\Enums\AttendanceStatus;
use App\Enums\PermissionStatus;
use App\Enums\RoleName;
use App\Models\AiAnomaly;
use App\Models\AiPrediction;
use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Models\PermissionRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        if ($user->hasRole(RoleName::Admin->value)) {
            return $this->adminDashboard($request);
        }

        if ($user->hasRole(RoleName::HrOfficer->value)) {
            return $this->hrDashboard();
        }

        return $this->employeeDashboard($user);
    }

    /**
     * Executive dashboard for an Admin. Two filters, applied via query string and echoed back so
     * the UI can reflect the active selection:
     *   - `range`      — trailing window in days (7/30/90, default 30) for every trend aggregate.
     *   - `department` — scope every metric to one department's employees (null/absent = all).
     */
    private function adminDashboard(Request $request): Response
    {
        $days = (int) $request->integer('range', 30);
        if (! in_array($days, [7, 30, 90], true)) {
            $days = 30;
        }

        $departmentId = $request->integer('department') ?: null;
        if ($departmentId !== null && ! Department::whereKey($departmentId)->exists()) {
            $departmentId = null;
        }

        // Employee IDs in scope; null means "all employees" (skip the whereIn entirely).
        $employeeIds = $departmentId !== null
            ? Employee::where('department_id', $departmentId)->pluck('id')
            : null;

        $total = $employeeIds !== null ? $employeeIds->count() : Employee::count();
        $linked = $employeeIds !== null
            ? Employee::where('department_id', $departmentId)->whereNotNull('user_id')->count()
            : Employee::whereNotNull('user_id')->count();

        $todayQuery = AttendanceRecord::whereDate('work_date', Carbon::today()->toDateString());
        if ($employeeIds !== null) {
            $todayQuery->whereIn('employee_id', $employeeIds);
        }
        $todayCounts = $this->statusCounts($todayQuery->get(['status']));

        return Inertia::render('dashboard/admin', [
            'stats' => [
                'total' => $total,
                'present' => ($todayCounts[AttendanceStatus::Present->value] ?? 0)
                    + ($todayCounts[AttendanceStatus::Late->value] ?? 0),
                'absent' => $todayCounts[AttendanceStatus::Absent->value] ?? 0,
                'late' => $todayCounts[AttendanceStatus::Late->value] ?? 0,
            ],
            'attendanceReport' => $this->attendanceReport($days, $employeeIds),
            'byDepartment' => $this->byDepartment(),
            'byLogin' => [
                'linked' => $linked,
                'unlinked' => max($total - $linked, 0),
            ],
            'topAttendants' => $this->topAttendants($days, $employeeIds),
            'weeklyAbsent' => $this->weeklyAbsent($days, $employeeIds),
            'decisionSupport' => $this->decisionSupport($days, $employeeIds),
            'filters' => ['range' => $days, 'department' => $departmentId],
            'departments' => Department::orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * Workforce analytics dashboard for an HR Officer — org-wide (HR sees everything): today's
     * attendance composition, 30-day trend, department distribution, weekly absence pattern,
     * this-month permission-request throughput with an actionable pending queue, and exception
     * counts. Reuses the same aggregate builders the Admin dashboard uses.
     */
    private function hrDashboard(): Response
    {
        $today = Carbon::today();
        $monthStart = Carbon::now()->startOfMonth();

        $totalEmployees = Employee::count();
        $activeEmployees = Employee::where('status', 'active')->count();

        // Today's attendance composition across the whole workforce.
        $todayRecords = AttendanceRecord::whereDate('work_date', $today->toDateString())->get();
        $onTime = $todayRecords->where('status', AttendanceStatus::Present)->count();
        $lateToday = $todayRecords->where('status', AttendanceStatus::Late)->count();
        $leaveToday = $todayRecords->filter(fn (AttendanceRecord $r) => $r->status->isLeave())->count();
        $absentToday = $todayRecords->where('status', AttendanceStatus::Absent)->count();
        $checkedIn = $onTime + $lateToday;
        $notCheckedIn = max($totalEmployees - $checkedIn - $leaveToday - $absentToday, 0);

        // This month's exception totals.
        $monthRecords = AttendanceRecord::whereBetween('work_date', [$monthStart->toDateString(), $today->toDateString()])
            ->get(['late_minutes', 'early_leave_minutes']);
        $lateComing = $monthRecords->where('late_minutes', '>', 0)->count();
        $earlyGoing = $monthRecords->where('early_leave_minutes', '>', 0)->count();

        $pending = PermissionRequest::where('status', PermissionStatus::Pending)->count();

        $recentPending = PermissionRequest::with('employee:id,first_name,last_name')
            ->where('status', PermissionStatus::Pending)
            ->latest()
            ->limit(6)
            ->get()
            ->map(fn (PermissionRequest $r) => [
                'id' => $r->id,
                'employee' => $r->employee?->fullName() ?? 'Unknown',
                'type_label' => $r->type->label(),
                'start_date' => $r->start_date->toDateString(),
                'end_date' => $r->end_date->toDateString(),
            ])
            ->values();

        return Inertia::render('dashboard/hr', [
            'monthLabel' => $monthStart->format('F Y'),
            'stats' => [
                'total' => $totalEmployees,
                'active' => $activeEmployees,
                'inactive' => max($totalEmployees - $activeEmployees, 0),
                'unlinked' => Employee::whereNull('user_id')->count(),
                'presentToday' => $checkedIn,
                'pending' => $pending,
            ],
            'today' => [
                'checkedIn' => $checkedIn,
                'notCheckedIn' => $notCheckedIn,
                'onLeave' => $leaveToday,
                'late' => $lateToday,
                'absent' => $absentToday,
            ],
            'donut' => [
                'onTime' => $onTime,
                'late' => $lateToday,
                'leave' => $leaveToday,
                'absent' => $absentToday,
                'notCheckedIn' => $notCheckedIn,
            ],
            'attendanceTrend' => $this->attendanceReport(),
            'byDepartment' => $this->byDepartment(),
            'weeklyAbsent' => $this->weeklyAbsent(),
            'requests' => [
                'pending' => $pending,
                'approved' => PermissionRequest::where('status', PermissionStatus::Approved)
                    ->where('reviewed_at', '>=', $monthStart)->count(),
                'rejected' => PermissionRequest::where('status', PermissionStatus::Rejected)
                    ->where('reviewed_at', '>=', $monthStart)->count(),
                'recent' => $recentPending,
            ],
            'exceptions' => ['lateComing' => $lateComing, 'earlyGoing' => $earlyGoing],
            'departments' => Department::withCount('employees')->orderBy('name')->get(),
        ]);
    }

    /**
     * Personal analytics dashboard for an Employee — scoped entirely to their own record:
     * this month's attendance breakdown, today's status, the current week's worked-hours and
     * late-minutes bars, exception counts, and their pending/approved permission requests.
     */
    private function employeeDashboard(User $user): Response
    {
        $employee = $user->employee()->with(['department', 'workSchedule'])->first();

        if ($employee === null) {
            return Inertia::render('dashboard/employee', ['employee' => null]);
        }

        $monthStart = Carbon::now()->startOfMonth();
        $today = Carbon::today();

        $records = AttendanceRecord::where('employee_id', $employee->id)
            ->whereBetween('work_date', [$monthStart->toDateString(), $today->toDateString()])
            ->get();

        $present = $records->where('status', AttendanceStatus::Present)->count();
        $late = $records->where('status', AttendanceStatus::Late)->count();
        $absent = $records->where('status', AttendanceStatus::Absent)->count();
        $leave = $records->filter(fn (AttendanceRecord $r) => $r->status->isLeave())->count();
        $workedMinutes = (int) $records->sum('worked_minutes');
        $lateComing = $records->where('late_minutes', '>', 0)->count();
        $earlyGoing = $records->where('early_leave_minutes', '>', 0)->count();

        [$todayStatus, $todayLabel] = $this->todayStatus(
            $records->first(fn (AttendanceRecord $r) => $r->work_date->isSameDay($today))
        );

        // Current week (Sun–Sat): worked hours + late minutes per day for the two bar charts.
        $weekStart = Carbon::now()->startOfWeek(Carbon::SUNDAY);
        $weekRecords = AttendanceRecord::where('employee_id', $employee->id)
            ->whereBetween('work_date', [$weekStart->toDateString(), $weekStart->copy()->addDays(6)->toDateString()])
            ->get()
            ->keyBy(fn (AttendanceRecord $r) => $r->work_date->toDateString());

        $weekLabels = [];
        $weekHours = [];
        $weekLate = [];
        for ($i = 0; $i < 7; $i++) {
            $day = $weekStart->copy()->addDays($i);
            $record = $weekRecords->get($day->toDateString());
            $weekLabels[] = $day->format('D');
            $weekHours[] = $record && $record->worked_minutes ? (int) round($record->worked_minutes / 60) : 0;
            $weekLate[] = $record ? (int) $record->late_minutes : 0;
        }

        return Inertia::render('dashboard/employee', [
            'employee' => [
                'name' => $employee->fullName(),
                'employee_code' => $employee->employee_code,
                'status' => $employee->status,
                'department' => $employee->department ? ['name' => $employee->department->name] : null,
                'workSchedule' => $employee->workSchedule ? ['name' => $employee->workSchedule->name] : null,
            ],
            'monthLabel' => $monthStart->format('F Y'),
            'summary' => [
                'present' => $present,
                'late' => $late,
                'absent' => $absent,
                'leave' => $leave,
                'workedHours' => (int) round($workedMinutes / 60),
                'totalDays' => $records->count(),
            ],
            'today' => ['status' => $todayStatus, 'label' => $todayLabel],
            'week' => [
                'labels' => $weekLabels,
                'hours' => $weekHours,
                'late' => $weekLate,
                'todayIndex' => (int) round($weekStart->diffInDays($today)),
            ],
            'exceptions' => ['lateComing' => $lateComing, 'earlyGoing' => $earlyGoing],
            'requests' => [
                'pending' => $employee->permissionRequests()->where('status', PermissionStatus::Pending)->count(),
                'thisMonth' => $employee->permissionRequests()->where('created_at', '>=', $monthStart)->count(),
                'approved' => $employee->permissionRequests()
                    ->where('status', PermissionStatus::Approved)
                    ->where('reviewed_at', '>=', $monthStart)
                    ->count(),
            ],
        ]);
    }

    /**
     * Derive today's presence state from the employee's record for today (if any).
     *
     * @return array{0: string, 1: string}
     */
    private function todayStatus(?AttendanceRecord $record): array
    {
        if ($record === null) {
            return ['none', 'Not Checked In'];
        }

        if ($record->status->isLeave()) {
            return ['on_leave', $record->status->label()];
        }

        if ($record->first_in && ! $record->last_out) {
            return ['checked_in', 'Checked In'];
        }

        if ($record->first_in && $record->last_out) {
            return ['checked_out', 'Checked Out'];
        }

        if ($record->status === AttendanceStatus::Absent) {
            return ['absent', 'Absent'];
        }

        return ['none', 'Not Checked In'];
    }

    /**
     * Real daily attendance-rate (%) over the trailing window, bucketed into ~10 points.
     *
     * @param  Collection<int, int>|null  $employeeIds  Restrict to these employees; null = all.
     * @return array{labels: list<string>, values: list<int>, highlight: int}
     */
    private function attendanceReport(int $days = 30, ?Collection $employeeIds = null): array
    {
        $start = Carbon::today()->subDays($days - 1);
        $query = AttendanceRecord::whereBetween('work_date', [$start->toDateString(), Carbon::today()->toDateString()]);
        if ($employeeIds !== null) {
            $query->whereIn('employee_id', $employeeIds);
        }
        $records = $query->get(['status', 'work_date']);

        // Keep the chart to ~10 points regardless of window: daily for short ranges, wider buckets otherwise.
        $bucketSize = max(1, (int) ceil($days / 10));
        $bucketCount = (int) ceil($days / $bucketSize);

        $labels = [];
        $values = [];
        for ($i = 0; $i < $bucketCount; $i++) {
            $bucketStart = $start->copy()->addDays($i * $bucketSize);
            $bucketEnd = $bucketStart->copy()->addDays($bucketSize - 1);
            $slice = $records->filter(
                fn (AttendanceRecord $r) => $r->work_date->betweenIncluded($bucketStart, $bucketEnd)
            );
            $count = $slice->count();
            $absent = $slice->where('status', AttendanceStatus::Absent)->count();

            $labels[] = $bucketStart->format('M j');
            $values[] = $count > 0 ? (int) round((($count - $absent) / $count) * 100) : 0;
        }

        $highlight = $values === [] ? 0 : array_keys($values, max($values))[0];

        return ['labels' => $labels, 'values' => $values, 'highlight' => $highlight];
    }

    /**
     * Real employee headcount per department for the bar chart.
     *
     * @return array{labels: list<string>, values: list<int>, highlight: int}
     */
    private function byDepartment(): array
    {
        $departments = Department::withCount('employees')->orderBy('name')->get();

        $labels = $departments->map(fn (Department $d) => strtoupper(substr($d->name, 0, 3)))->all();
        $values = $departments->map(fn (Department $d) => $d->employees_count)->all();

        $highlight = $values === [] ? 0 : array_keys($values, max($values))[0];

        return ['labels' => $labels, 'values' => $values, 'highlight' => $highlight];
    }

    /**
     * Top employees by real attendance rate over the trailing window.
     *
     * @param  Collection<int, int>|null  $employeeIds  Restrict to these employees; null = all.
     * @return list<array{name: string, initials: string, percent: int, days: int}>
     */
    private function topAttendants(int $days = 30, ?Collection $employeeIds = null): array
    {
        $start = Carbon::today()->subDays($days - 1)->toDateString();
        $today = Carbon::today()->toDateString();

        $query = AttendanceRecord::whereBetween('work_date', [$start, $today]);
        if ($employeeIds !== null) {
            $query->whereIn('employee_id', $employeeIds);
        }
        $byEmployee = $query->get(['employee_id', 'status'])->groupBy('employee_id');

        return Employee::whereIn('id', $byEmployee->keys())
            ->orderBy('first_name')
            ->get()
            ->map(function (Employee $employee) use ($byEmployee) {
                $records = $byEmployee->get($employee->id) ?? collect();
                $total = $records->count();
                $present = $records->whereIn('status', [AttendanceStatus::Present, AttendanceStatus::Late])->count();
                $percent = $total > 0 ? (int) round(($present / $total) * 100) : 0;

                return [
                    'name' => $employee->fullName(),
                    'initials' => $this->initials($employee->fullName()),
                    'percent' => $percent,
                    'days' => $present,
                ];
            })
            ->sortByDesc('percent')
            ->take(6)
            ->values()
            ->all();
    }

    /**
     * Real absences per weekday over the trailing window for the radar chart.
     *
     * @param  Collection<int, int>|null  $employeeIds  Restrict to these employees; null = all.
     * @return list<array{label: string, value: int}>
     */
    private function weeklyAbsent(int $days = 30, ?Collection $employeeIds = null): array
    {
        $start = Carbon::today()->subDays($days - 1)->toDateString();
        $today = Carbon::today()->toDateString();

        $query = AttendanceRecord::whereBetween('work_date', [$start, $today])
            ->where('status', AttendanceStatus::Absent->value);
        if ($employeeIds !== null) {
            $query->whereIn('employee_id', $employeeIds);
        }
        $records = $query->get(['work_date']);

        $byWeekday = $records->groupBy(fn (AttendanceRecord $r) => $r->work_date->format('D'));

        return collect(['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'])
            ->map(fn (string $label) => ['label' => $label, 'value' => ($byWeekday->get($label)?->count() ?? 0)])
            ->all();
    }

    /**
     * AI decision-support: highest-risk employees (latest scoring) + open anomaly count.
     *
     * @param  Collection<int, int>|null  $employeeIds  Restrict to these employees; null = all.
     * @return array{highRisk: list<array{name: string, initials: string, risk: int}>, anomalies: int}
     */
    private function decisionSupport(int $days = 30, ?Collection $employeeIds = null): array
    {
        $highRiskQuery = AiPrediction::with('employee:id,first_name,last_name')
            ->where('risk_level', 'high')
            ->orderByDesc('risk_score')
            ->limit(5);
        if ($employeeIds !== null) {
            $highRiskQuery->whereIn('employee_id', $employeeIds);
        }

        $highRisk = $highRiskQuery->get()
            ->map(fn (AiPrediction $p) => [
                'name' => $p->employee?->fullName() ?? "#{$p->employee_id}",
                'initials' => $this->initials($p->employee?->fullName() ?? '#'),
                'risk' => (int) round($p->risk_score * 100),
            ])
            ->values()
            ->all();

        $anomalyQuery = AiAnomaly::whereBetween('work_date', [
            Carbon::today()->subDays($days - 1)->toDateString(),
            Carbon::today()->toDateString(),
        ]);
        if ($employeeIds !== null) {
            $anomalyQuery->whereIn('employee_id', $employeeIds);
        }

        return [
            'highRisk' => $highRisk,
            'anomalies' => $anomalyQuery->count(),
        ];
    }

    /**
     * Status → count map for a record set.
     *
     * @param  Collection<int, AttendanceRecord>  $records
     * @return array<string, int>
     */
    private function statusCounts(Collection $records): array
    {
        $counts = [];
        foreach ($records as $record) {
            $key = $record->status->value;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $first = $parts[0][0] ?? '';
        $last = count($parts) > 1 ? ($parts[count($parts) - 1][0] ?? '') : '';

        return strtoupper($first.$last);
    }
}
