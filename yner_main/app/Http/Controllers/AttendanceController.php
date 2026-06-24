<?php

namespace App\Http\Controllers;

use App\Enums\AttendanceStatus;
use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\Employee;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class AttendanceController extends Controller
{
    /**
     * Flagship "Employee Attendance" dashboard — a real weekly grid built from
     * computed `attendance_records`. Admin/HR see every active employee; an Employee
     * sees only their own linked record.
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', AttendanceRecord::class);

        $user = $request->user();
        $isManager = $user->can('update', new AttendanceRecord);

        $weekStart = Carbon::now()->startOfWeek(Carbon::SUNDAY);
        $weekEnd = $weekStart->copy()->addDays(6);
        $today = Carbon::today()->toDateString();

        $days = collect(range(0, 6))->map(fn (int $offset) => [
            'name' => $weekStart->copy()->addDays($offset)->format('l'),
            'date' => (int) $weekStart->copy()->addDays($offset)->format('j'),
            'iso' => $weekStart->copy()->addDays($offset)->toDateString(),
        ])->all();

        $employeesQuery = Employee::with('department')->where('status', 'active');

        if (! $isManager) {
            $employeesQuery->where('user_id', $user->id);
        }

        $employees = $employeesQuery->orderBy('first_name')->get();

        // Records for the week, keyed [employee_id][Y-m-d] for O(1) cell lookup.
        $records = AttendanceRecord::whereIn('employee_id', $employees->pluck('id'))
            ->whereBetween('work_date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->get()
            ->groupBy('employee_id')
            ->map(fn (Collection $group) => $group->keyBy(fn (AttendanceRecord $r) => $r->work_date->toDateString()));

        $rows = $employees->map(function (Employee $employee) use ($days, $records, $today) {
            $byDate = $records->get($employee->id) ?? collect();

            return [
                'id' => $employee->id,
                'name' => $employee->fullName(),
                'role' => $employee->department?->name ?? 'Staff',
                'initials' => $this->initials($employee->fullName()),
                'cells' => collect($days)->map(
                    fn (array $day) => $this->cell($byDate->get($day['iso']), $day['iso'] === $today),
                )->all(),
            ];
        })->values()->all();

        return Inertia::render('attendance/index', [
            'weekLabel' => $weekStart->format('d. F Y'),
            'days' => $days,
            'rows' => $rows,
            'stats' => $this->stats($employees, $records, $today),
            'statusOptions' => collect(AttendanceStatus::cases())
                ->map(fn (AttendanceStatus $s) => ['value' => $s->value, 'label' => $s->label()])
                ->all(),
            'canCorrect' => $isManager,
            'status' => session('status'),
        ]);
    }

    /**
     * Apply a manual HR/Admin correction to a computed record and log it to the audit trail.
     */
    public function update(Request $request, AttendanceRecord $attendanceRecord): RedirectResponse
    {
        $this->authorize('update', $attendanceRecord);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', AttendanceStatus::values())],
            'first_in' => ['nullable', 'date_format:H:i'],
            'last_out' => ['nullable', 'date_format:H:i'],
            'remarks' => ['required', 'string', 'max:1000'],
        ]);

        $date = $attendanceRecord->work_date->toDateString();
        $firstIn = ! empty($validated['first_in']) ? Carbon::parse($date.' '.$validated['first_in']) : null;
        $lastOut = ! empty($validated['last_out']) ? Carbon::parse($date.' '.$validated['last_out']) : null;

        $old = $attendanceRecord->only(['status', 'first_in', 'last_out', 'worked_minutes', 'remarks', 'is_manual']);

        $attendanceRecord->update([
            'status' => $validated['status'],
            'first_in' => $firstIn,
            'last_out' => $lastOut,
            'worked_minutes' => ($firstIn && $lastOut)
                ? (int) round(($lastOut->getTimestamp() - $firstIn->getTimestamp()) / 60)
                : null,
            'remarks' => $validated['remarks'],
            'is_manual' => true,
        ]);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'auditable_type' => AttendanceRecord::class,
            'auditable_id' => $attendanceRecord->id,
            'action' => 'attendance.corrected',
            'old_values' => $this->auditValues($old),
            'new_values' => $this->auditValues($attendanceRecord->only(array_keys($old))),
            'note' => $validated['remarks'],
        ]);

        return redirect()->route('attendance.index')->with('status', 'Attendance record corrected.');
    }

    /**
     * Normalise an attribute snapshot for JSON audit storage (enums/dates → scalars).
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function auditValues(array $values): array
    {
        return collect($values)->map(function ($value) {
            if ($value instanceof AttendanceStatus) {
                return $value->value;
            }

            if ($value instanceof Carbon) {
                return $value->toDateTimeString();
            }

            return $value;
        })->all();
    }

    /**
     * Map one record (or null) to the front-end cell shape, carrying the record payload
     * the correction modal needs.
     *
     * @return array<string, mixed>
     */
    private function cell(?AttendanceRecord $record, bool $isToday): array
    {
        if (! $record) {
            return ['type' => null, 'label' => null, 'record' => null];
        }

        $payload = [
            'id' => $record->id,
            'status' => $record->status->value,
            'first_in' => $record->first_in?->format('H:i'),
            'last_out' => $record->last_out?->format('H:i'),
            'remarks' => $record->remarks,
        ];

        [$type, $label] = $this->cellDisplay($record, $isToday);

        return ['type' => $type, 'label' => $label, 'record' => $payload];
    }

    /**
     * Derive the colored-pill type + label for a record.
     *
     * @return array{0: string, 1: string}
     */
    private function cellDisplay(AttendanceRecord $record, bool $isToday): array
    {
        if ($record->status->isLeave()) {
            return ['leave', $record->status->label()];
        }

        if ($record->status === AttendanceStatus::Absent) {
            return ['absent', 'Absent'];
        }

        // Present / Late: an open punch (in but not out) on today reads as "Active".
        if ($isToday && $record->first_in && ! $record->last_out) {
            return ['active', 'Active'];
        }

        $label = $record->worked_minutes !== null
            ? $this->hoursLabel($record->worked_minutes)
            : $record->status->label();

        // Late or short days render amber (partial); clean full days render green (hours).
        $type = $record->status === AttendanceStatus::Late || $record->early_leave_minutes > 0
            ? 'partial'
            : 'hours';

        return [$type, $label];
    }

    private function hoursLabel(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $mins = $minutes % 60;

        return $mins === 0 ? "{$hours} Hours" : sprintf('%dh %02dm', $hours, $mins);
    }

    /**
     * KPI cards from today's real records.
     *
     * @param  Collection<int, Employee>  $employees
     * @param  Collection<int, Collection<string, AttendanceRecord>>  $records
     * @return array<string, int>
     */
    private function stats(Collection $employees, Collection $records, string $today): array
    {
        $todays = $records
            ->map(fn (Collection $byDate) => $byDate->get($today))
            ->filter()
            ->values();

        $present = $todays->filter(fn (AttendanceRecord $r) => in_array($r->status, [AttendanceStatus::Present, AttendanceStatus::Late], true))->count();
        $late = $todays->filter(fn (AttendanceRecord $r) => $r->status === AttendanceStatus::Late)->count();
        $onLeave = $todays->filter(fn (AttendanceRecord $r) => $r->status->isLeave())->count();
        $absent = $todays->filter(fn (AttendanceRecord $r) => $r->status === AttendanceStatus::Absent)->count();

        return [
            'present' => $present,
            'presentRemaining' => max($employees->count() - $present, 0),
            'late' => $late,
            'onTime' => max($present - $late, 0),
            'onLeave' => $onLeave,
            'absent' => $absent,
        ];
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $first = $parts[0][0] ?? '';
        $last = count($parts) > 1 ? ($parts[count($parts) - 1][0] ?? '') : '';

        return strtoupper($first.$last);
    }
}
