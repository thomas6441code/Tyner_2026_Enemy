<?php

namespace App\Http\Controllers;

use App\Enums\AttendanceStatus;
use App\Enums\RoleName;
use App\Models\AiAnomaly;
use App\Models\AiPrediction;
use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
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
            $total = Employee::count();
            $linked = Employee::whereNotNull('user_id')->count();
            $today = Carbon::today()->toDateString();

            $todayCounts = $this->statusCounts(
                AttendanceRecord::whereDate('work_date', $today)->get(['status'])
            );

            return Inertia::render('dashboard/admin', [
                'stats' => [
                    'total' => $total,
                    'present' => ($todayCounts[AttendanceStatus::Present->value] ?? 0)
                        + ($todayCounts[AttendanceStatus::Late->value] ?? 0),
                    'absent' => $todayCounts[AttendanceStatus::Absent->value] ?? 0,
                    'late' => $todayCounts[AttendanceStatus::Late->value] ?? 0,
                ],
                'attendanceReport' => $this->attendanceReport(),
                'byDepartment' => $this->byDepartment(),
                'byLogin' => [
                    'linked' => $linked,
                    'unlinked' => max($total - $linked, 0),
                ],
                'topAttendants' => $this->topAttendants(),
                'weeklyAbsent' => $this->weeklyAbsent(),
                'decisionSupport' => $this->decisionSupport(),
            ]);
        }

        if ($user->hasRole(RoleName::HrOfficer->value)) {
            return Inertia::render('dashboard/hr', [
                'employeeCount' => Employee::count(),
                'activeEmployeeCount' => Employee::where('status', 'active')->count(),
                'departments' => Department::withCount('employees')->orderBy('name')->get(),
            ]);
        }

        return Inertia::render('dashboard/employee', [
            'employee' => $user->employee()->with(['department', 'workSchedule'])->first(),
        ]);
    }

    /**
     * Real daily attendance-rate (%) over the last ~30 days, bucketed into 10 points.
     *
     * @return array{labels: list<string>, values: list<int>, highlight: int}
     */
    private function attendanceReport(): array
    {
        $start = Carbon::today()->subDays(29);
        $records = AttendanceRecord::whereBetween('work_date', [$start->toDateString(), Carbon::today()->toDateString()])
            ->get(['status', 'work_date']);

        $labels = [];
        $values = [];
        for ($i = 0; $i < 10; $i++) {
            $bucketStart = $start->copy()->addDays($i * 3);
            $bucketEnd = $bucketStart->copy()->addDays(2);
            $slice = $records->filter(
                fn (AttendanceRecord $r) => $r->work_date->betweenIncluded($bucketStart, $bucketEnd)
            );
            $count = $slice->count();
            $absent = $slice->where('status', AttendanceStatus::Absent)->count();

            $labels[] = $bucketStart->format('M j');
            $values[] = $count > 0 ? (int) round((($count - $absent) / $count) * 100) : 0;
        }

        $highlight = array_keys($values, max($values))[0] ?? 0;

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
     * Top employees by real attendance rate over the last 30 days.
     *
     * @return list<array{name: string, initials: string, percent: int, days: int}>
     */
    private function topAttendants(): array
    {
        $start = Carbon::today()->subDays(29)->toDateString();
        $today = Carbon::today()->toDateString();

        $byEmployee = AttendanceRecord::whereBetween('work_date', [$start, $today])
            ->get(['employee_id', 'status'])
            ->groupBy('employee_id');

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
     * Real absences per weekday over the last 30 days for the radar chart.
     *
     * @return list<array{label: string, value: int}>
     */
    private function weeklyAbsent(): array
    {
        $start = Carbon::today()->subDays(29)->toDateString();
        $today = Carbon::today()->toDateString();

        $records = AttendanceRecord::whereBetween('work_date', [$start, $today])
            ->where('status', AttendanceStatus::Absent->value)
            ->get(['work_date']);

        $byWeekday = $records->groupBy(fn (AttendanceRecord $r) => $r->work_date->format('D'));

        return collect(['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'])
            ->map(fn (string $label) => ['label' => $label, 'value' => ($byWeekday->get($label)?->count() ?? 0)])
            ->all();
    }

    /**
     * AI decision-support: highest-risk employees (latest scoring) + open anomaly count.
     *
     * @return array{highRisk: list<array{name: string, initials: string, risk: int}>, anomalies: int}
     */
    private function decisionSupport(): array
    {
        $highRisk = AiPrediction::with('employee:id,first_name,last_name')
            ->where('risk_level', 'high')
            ->orderByDesc('risk_score')
            ->limit(5)
            ->get()
            ->map(fn (AiPrediction $p) => [
                'name' => $p->employee?->fullName() ?? "#{$p->employee_id}",
                'initials' => $this->initials($p->employee?->fullName() ?? '#'),
                'risk' => (int) round($p->risk_score * 100),
            ])
            ->values()
            ->all();

        return [
            'highRisk' => $highRisk,
            'anomalies' => AiAnomaly::whereBetween('work_date', [
                Carbon::today()->subDays(29)->toDateString(),
                Carbon::today()->toDateString(),
            ])->count(),
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
