<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class AttendanceController extends Controller
{
    /**
     * Flagship "Employee Attendance" dashboard.
     *
     * Employee identity (name, department, initials) is real data pulled from the
     * `employees` table. The per-day attendance cells are deterministic placeholder
     * data — there is no `attendance_records` table yet (that arrives with the Phase 6
     * sync engine), so the grid is seeded from the employee id for a stable demo.
     */
    public function index(): Response
    {
        $weekStart = Carbon::now()->startOfWeek(Carbon::SUNDAY);

        $days = collect(range(0, 6))->map(fn (int $offset) => [
            'name' => $weekStart->copy()->addDays($offset)->format('l'),
            'date' => (int) $weekStart->copy()->addDays($offset)->format('j'),
        ])->all();

        $employees = Employee::with('department')->orderBy('first_name')->get();

        $roster = $employees->isNotEmpty()
            ? $employees->map(fn (Employee $employee) => $this->buildRow(
                $employee->id,
                $employee->fullName(),
                $employee->department?->name ?? 'Staff',
            ))
            : collect($this->fallbackRoster())->map(
                fn (array $person, int $index) => $this->buildRow($index + 1, $person['name'], $person['role']),
            );

        return Inertia::render('attendance/index', [
            'weekLabel' => $weekStart->format('d. F Y'),
            'days' => $days,
            'rows' => $roster->values()->all(),
            'stats' => $this->stats($employees->count(), $employees->where('status', 'active')->count()),
        ]);
    }

    /**
     * Build one employee row with a week of deterministic dummy attendance cells.
     *
     * @return array<string, mixed>
     */
    private function buildRow(int $id, string $name, string $role): array
    {
        $cells = [];

        for ($day = 0; $day < 7; $day++) {
            $cells[] = $this->cell($id, $day);
        }

        return [
            'id' => $id,
            'name' => $name,
            'role' => $role,
            'initials' => $this->initials($name),
            'cells' => $cells,
        ];
    }

    /**
     * One attendance cell. Friday/Saturday are left empty (weekend); Thursday reads as the
     * "today / currently active" column to mirror the reference design.
     *
     * @return array{type: string|null, label: string|null}
     */
    private function cell(int $id, int $day): array
    {
        if ($day >= 5) {
            return ['type' => null, 'label' => null];
        }

        if ($day === 4) {
            return ['type' => 'active', 'label' => 'Active'];
        }

        $r = ($id * 17 + $day * 13) % 10;

        return match (true) {
            $r < 5 => ['type' => 'hours', 'label' => '8 Hours'],
            $r < 7 => ['type' => 'partial', 'label' => sprintf('%dh %02dm', 3 + ($r % 5), ($r * 7) % 60)],
            $r === 7 => ['type' => 'leave', 'label' => 'Leave'],
            $r === 8 => ['type' => 'absent', 'label' => 'Absent'],
            default => ['type' => 'hours', 'label' => '8 Hours'],
        };
    }

    /**
     * KPI cards. Present/absent reflect real active/inactive counts; late and on-leave are
     * deterministic placeholders derived from the headcount.
     *
     * @return array<string, int>
     */
    private function stats(int $total, int $active): array
    {
        $late = (int) floor($total * 0.15);
        $onLeave = (int) floor($total * 0.08);

        return [
            'present' => $active,
            'presentRemaining' => max($total - $active, 0),
            'late' => $late,
            'onTime' => max($active - $late, 0),
            'onLeave' => $onLeave,
            'absent' => max($total - $active, 0),
        ];
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $first = $parts[0][0] ?? '';
        $last = count($parts) > 1 ? ($parts[count($parts) - 1][0] ?? '') : '';

        return strtoupper($first.$last);
    }

    /**
     * Fully dummy roster used only when no employees have been seeded yet.
     *
     * @return list<array{name: string, role: string}>
     */
    private function fallbackRoster(): array
    {
        return [
            ['name' => 'Dianne Russell', 'role' => 'UI/UX Designer'],
            ['name' => 'Bessie Cooper', 'role' => 'Product Designer'],
            ['name' => 'Brooklyn Jones', 'role' => 'Marketing Officer'],
            ['name' => 'Eleanor Pena', 'role' => 'Content Writer'],
            ['name' => 'Darlene Robertson', 'role' => 'UX Engineer'],
            ['name' => 'Cameron Williamson', 'role' => 'Frontend Developer'],
        ];
    }
}
