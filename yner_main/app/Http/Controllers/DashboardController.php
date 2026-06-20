<?php

namespace App\Http\Controllers;

use App\Enums\RoleName;
use App\Models\Department;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        if ($user->hasRole(RoleName::Admin->value)) {
            $total = Employee::count();
            $active = Employee::where('status', 'active')->count();
            $linked = Employee::whereNotNull('user_id')->count();

            return Inertia::render('dashboard/admin', [
                'stats' => [
                    'total' => $total,
                    'present' => $active,
                    'absent' => max($total - $active, 0),
                    'late' => (int) floor($total * 0.08),
                ],
                'attendanceReport' => $this->attendanceReport(),
                'byDepartment' => $this->byDepartment(),
                'byLogin' => [
                    'linked' => $linked,
                    'unlinked' => max($total - $linked, 0),
                ],
                'topAttendants' => $this->topAttendants(),
                'weeklyAbsent' => $this->weeklyAbsent(),
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
     * Daily attendance line series over the last ~4 weeks. Deterministic placeholder data
     * (no attendance_records table yet) anchored around the real headcount.
     *
     * @return array{labels: list<string>, values: list<int>, highlight: int}
     */
    private function attendanceReport(): array
    {
        $base = max(Employee::count(), 10);
        $start = Carbon::now()->subDays(27);

        $labels = [];
        $values = [];

        for ($i = 0; $i < 10; $i++) {
            $day = $start->copy()->addDays($i * 3);
            $labels[] = $day->format('M j');
            $wave = sin($i / 1.6) * ($base * 0.08) + cos($i / 3) * ($base * 0.04);
            $values[] = (int) round($base * 0.9 + $wave + (($i * 7) % 5));
        }

        $highlight = array_keys($values, max($values))[0];

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
     * Top employees by attendance rate (real names, deterministic placeholder rates).
     *
     * @return list<array{name: string, initials: string, percent: int, days: int}>
     */
    private function topAttendants(): array
    {
        return Employee::orderBy('first_name')
            ->take(6)
            ->get()
            ->map(function (Employee $employee) {
                $percent = 100 - (($employee->id * 7) % 16);

                return [
                    'name' => $employee->fullName(),
                    'initials' => $this->initials($employee->fullName()),
                    'percent' => $percent,
                    'days' => 22 + (($employee->id * 3) % 9),
                ];
            })
            ->sortByDesc('percent')
            ->values()
            ->all();
    }

    /**
     * Absences per weekday for the radar chart (deterministic placeholder data).
     *
     * @return list<array{label: string, value: int}>
     */
    private function weeklyAbsent(): array
    {
        $pattern = [5, 6, 4, 7, 8, 3, 2];

        return collect(['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'])
            ->map(fn (string $label, int $i) => ['label' => $label, 'value' => $pattern[$i]])
            ->all();
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $first = $parts[0][0] ?? '';
        $last = count($parts) > 1 ? ($parts[count($parts) - 1][0] ?? '') : '';

        return strtoupper($first.$last);
    }
}
