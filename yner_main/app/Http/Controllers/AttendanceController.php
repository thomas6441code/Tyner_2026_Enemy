<?php

namespace App\Http\Controllers;

use App\Enums\AttendanceStatus;
use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttendanceController extends Controller
{
    /**
     * Flagship "Employee Attendance" dashboard — a real weekly grid built from
     * computed `attendance_records`. Admin/HR see every active employee; an Employee
     * sees only their own linked record.
     */
    /**
     * Longest date range (in days) the grid/exports will render — guards against
     * unbounded queries and an unusably wide grid.
     */
    private const MAX_RANGE_DAYS = 92;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', AttendanceRecord::class);

        $user = $request->user();
        $isManager = $user->can('update', new AttendanceRecord);

        [$from, $to, $employee] = $this->resolveFilters($request, $user, $isManager);
        $today = Carbon::today()->toDateString();

        $days = collect(range(0, (int) $from->diffInDays($to)))->map(fn (int $offset) => [
            'name' => $from->copy()->addDays($offset)->format('D'),
            'date' => (int) $from->copy()->addDays($offset)->format('j'),
            'iso' => $from->copy()->addDays($offset)->toDateString(),
        ])->all();

        $employees = $this->scopedEmployees($employee, $user, $isManager);

        // Records for the range, keyed [employee_id][Y-m-d] for O(1) cell lookup.
        $records = AttendanceRecord::whereIn('employee_id', $employees->pluck('id'))
            ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])
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

        // KPI cards always reflect *today* regardless of the selected range, so fetch
        // today's records for the scoped employees independently.
        $todayRecords = AttendanceRecord::whereIn('employee_id', $employees->pluck('id'))
            ->where('work_date', $today)
            ->get()
            ->groupBy('employee_id')
            ->map(fn (Collection $group) => $group->keyBy(fn (AttendanceRecord $r) => $r->work_date->toDateString()));

        $exportParams = $this->queryParams($from, $to, $employee);

        return Inertia::render('attendance/index', [
            'weekLabel' => $from->format('d M').' – '.$to->format('d M Y'),
            'days' => $days,
            'rows' => $rows,
            'stats' => $this->stats($employees, $todayRecords, $today),
            'statusOptions' => collect(AttendanceStatus::cases())
                ->map(fn (AttendanceStatus $s) => ['value' => $s->value, 'label' => $s->label()])
                ->all(),
            'canCorrect' => $isManager,
            'canFilterEmployee' => $isManager,
            'employees' => $isManager
                ? Employee::where('status', 'active')
                    ->orderBy('first_name')->orderBy('last_name')
                    ->get(['id', 'first_name', 'last_name'])
                    ->map(fn (Employee $e) => ['id' => $e->id, 'name' => $e->fullName()])
                    ->all()
                : [],
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'employee_id' => $employee?->id,
            ],
            'exportUrls' => [
                'excel' => route('attendance.export.excel', $exportParams),
                'pdf' => route('attendance.export.pdf', $exportParams),
            ],
            'status' => session('status'),
        ]);
    }

    /**
     * Stream the filtered attendance detail as CSV (opens natively in Excel/Sheets).
     */
    public function exportExcel(Request $request): StreamedResponse
    {
        $this->authorize('viewAny', AttendanceRecord::class);

        $user = $request->user();
        $isManager = $user->can('update', new AttendanceRecord);

        [$from, $to, $employee] = $this->resolveFilters($request, $user, $isManager);
        $employees = $this->scopedEmployees($employee, $user, $isManager);
        $rows = $this->detailRows($employees, $from, $to);

        $filename = $this->exportFilename($employee, $from, $to, 'csv');

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'wb');

            fputcsv($handle, [
                'Employee Code', 'Employee', 'Department', 'Date', 'Status',
                'First In', 'Last Out', 'Worked Hours', 'Late Minutes', 'Remarks',
            ]);

            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row['employee_code'], $row['name'], $row['department'], $row['date'],
                    $row['status'], $row['first_in'], $row['last_out'],
                    $row['worked_hours'], $row['late_minutes'], $row['remarks'],
                ]);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * Render the filtered attendance detail as a downloadable PDF (dompdf, A4 portrait).
     */
    public function exportPdf(Request $request)
    {
        $this->authorize('viewAny', AttendanceRecord::class);

        $user = $request->user();
        $isManager = $user->can('update', new AttendanceRecord);

        [$from, $to, $employee] = $this->resolveFilters($request, $user, $isManager);
        $employees = $this->scopedEmployees($employee, $user, $isManager);
        $rows = $this->detailRows($employees, $from, $to);

        $filename = $this->exportFilename($employee, $from, $to, 'pdf');

        return Pdf::loadView('attendance.detail-pdf', [
            'rows' => $rows,
            'meta' => [
                'from_label' => $from->format('d M Y'),
                'to_label' => $to->format('d M Y'),
                'scope' => $employee
                    ? $employee->fullName().' ('.$employee->employee_code.')'
                    : 'All active employees',
                'employees' => $employees->count(),
                'records' => count($rows),
                'generated_at' => Carbon::now()->format('d M Y H:i'),
            ],
            'totals' => $this->detailTotals($rows),
        ])
            // The letterhead's last-page footer is drawn from the layout's inline
            // page_script, which dompdf only evaluates when PHP is enabled.
            ->setOption('isPhpEnabled', true)
            ->setPaper('a4', 'portrait')
            ->download($filename);
    }

    /**
     * Resolve and validate the shared date-range + employee filters. Employees may only
     * ever see their own linked record, whatever they pass.
     *
     * @return array{0: Carbon, 1: Carbon, 2: ?Employee}
     */
    private function resolveFilters(Request $request, User $user, bool $isManager): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
        ]);

        $from = ! empty($validated['from'])
            ? Carbon::parse($validated['from'])->startOfDay()
            : Carbon::now()->startOfWeek(Carbon::SUNDAY);
        $to = ! empty($validated['to'])
            ? Carbon::parse($validated['to'])->startOfDay()
            : $from->copy()->addDays(6);

        // Guard against inverted or unbounded ranges.
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }
        if ($from->diffInDays($to) > self::MAX_RANGE_DAYS) {
            $to = $from->copy()->addDays(self::MAX_RANGE_DAYS);
        }

        if (! $isManager) {
            // Force the scope to the employee's own record; ignore any employee_id passed.
            return [$from, $to, Employee::where('user_id', $user->id)->first()];
        }

        $employee = ! empty($validated['employee_id'])
            ? Employee::find($validated['employee_id'])
            : null;

        return [$from, $to, $employee];
    }

    /**
     * Active employees in scope for the current user + optional employee filter.
     *
     * @return Collection<int, Employee>
     */
    private function scopedEmployees(?Employee $employee, User $user, bool $isManager): Collection
    {
        $query = Employee::with('department:id,name')->where('status', 'active');

        if (! $isManager) {
            $query->where('user_id', $user->id);
        } elseif ($employee) {
            $query->where('id', $employee->id);
        }

        return $query->orderBy('first_name')->orderBy('last_name')->get();
    }

    /**
     * One row per attendance record in the range, ready for CSV/PDF export.
     *
     * @param  Collection<int, Employee>  $employees
     * @return list<array<string, mixed>>
     */
    private function detailRows(Collection $employees, Carbon $from, Carbon $to): array
    {
        if ($employees->isEmpty()) {
            return [];
        }

        $byId = $employees->keyBy('id');

        return AttendanceRecord::whereIn('employee_id', $employees->pluck('id'))
            ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('employee_id')
            ->orderBy('work_date')
            ->get()
            ->map(function (AttendanceRecord $record) use ($byId) {
                $employee = $byId->get($record->employee_id);

                return [
                    'employee_code' => $employee?->employee_code ?? '',
                    'name' => $employee?->fullName() ?? '',
                    'department' => $employee?->department?->name ?? '—',
                    'date' => $record->work_date->format('D, d M Y'),
                    'status' => $record->status->label(),
                    'first_in' => $record->first_in?->format('H:i') ?? '—',
                    'last_out' => $record->last_out?->format('H:i') ?? '—',
                    'worked_hours' => $record->worked_minutes !== null
                        ? round($record->worked_minutes / 60, 1)
                        : '—',
                    'late_minutes' => (int) $record->late_minutes,
                    'remarks' => $record->remarks ?? '',
                ];
            })
            ->all();
    }

    /**
     * Roll-up counters for the PDF header.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int|float>
     */
    private function detailTotals(array $rows): array
    {
        $tally = fn (string $label) => count(array_filter($rows, fn ($r) => $r['status'] === $label));
        $workedHours = array_sum(array_map(
            fn ($r) => is_numeric($r['worked_hours']) ? $r['worked_hours'] : 0,
            $rows,
        ));

        return [
            'present' => $tally(AttendanceStatus::Present->label()),
            'late' => $tally(AttendanceStatus::Late->label()),
            'absent' => $tally(AttendanceStatus::Absent->label()),
            'leave' => count(array_filter(
                $rows,
                fn ($r) => in_array($r['status'], $this->leaveLabels(), true),
            )),
            'worked_hours' => round($workedHours, 1),
            'late_minutes' => array_sum(array_column($rows, 'late_minutes')),
        ];
    }

    /**
     * @return list<string>
     */
    private function leaveLabels(): array
    {
        return collect(AttendanceStatus::cases())
            ->filter(fn (AttendanceStatus $s) => $s->isLeave())
            ->map(fn (AttendanceStatus $s) => $s->label())
            ->values()
            ->all();
    }

    private function exportFilename(?Employee $employee, Carbon $from, Carbon $to, string $extension): string
    {
        $who = $employee
            ? preg_replace('/[^A-Za-z0-9]+/', '-', strtolower($employee->fullName()))
            : 'all-employees';

        return "attendance-{$who}-{$from->toDateString()}_to_{$to->toDateString()}.{$extension}";
    }

    /**
     * @return array<string, mixed>
     */
    private function queryParams(Carbon $from, Carbon $to, ?Employee $employee): array
    {
        return array_filter([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'employee_id' => $employee?->id,
        ], fn ($value) => $value !== null);
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
