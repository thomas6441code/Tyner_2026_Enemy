<?php

namespace App\Http\Controllers;

use App\Models\AiPrediction;
use App\Models\Department;
use App\Models\ReportSummary;
use App\Services\AttendanceReportAggregator;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    /**
     * Management attendance report (Phase 10). Permission-aware by construction — it reads the same
     * `attendance_records` the Phase 6 sync engine overwrites, so approved leave never shows Absent.
     * Renders the interactive page with real analytics + AI decision-support context.
     */
    public function index(Request $request, AttendanceReportAggregator $aggregator)
    {
        Gate::authorize('viewReports');

        [$from, $to, $department] = $this->filters($request);
        $report = $aggregator->report($from, $to, $department);

        return inertia('reports/attendance', [
            'report' => $report,
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'department_id' => $department?->id,
            ],
            'decisionSupport' => $this->decisionSupport($from, $department),
            'exportUrls' => [
                'csv' => route('reports.export.csv', $this->queryParams($from, $to, $department)),
                'pdf' => route('reports.export.pdf', $this->queryParams($from, $to, $department)),
            ],
        ]);
    }

    /**
     * Stream the report as a CSV (opens natively in Excel/Sheets) — dependency-free export.
     */
    public function exportCsv(Request $request, AttendanceReportAggregator $aggregator): StreamedResponse
    {
        Gate::authorize('viewReports');

        [$from, $to, $department] = $this->filters($request);
        $report = $aggregator->report($from, $to, $department);

        $filename = "attendance-report-{$from->toDateString()}_to_{$to->toDateString()}.csv";

        return response()->streamDownload(function () use ($report) {
            $handle = fopen('php://output', 'wb');

            fputcsv($handle, [
                'Employee Code',
                'Name',
                'Department',
                'Present',
                'Late',
                'Absent',
                'Leave',
                'Worked Hours',
                'Late Minutes',
                'Attendance %',
                'Punctuality %',
            ]);

            foreach ($report['rows'] as $row) {
                fputcsv($handle, [
                    $row['employee_code'],
                    $row['name'],
                    $row['department'],
                    $row['present'],
                    $row['late'],
                    $row['absent'],
                    $row['leave'],
                    $row['worked_hours'],
                    $row['late_minutes'],
                    $row['attendance_rate'],
                    $row['punctuality_rate'],
                ]);
            }

            $totals = $report['totals'];
            fputcsv($handle, [
                'TOTAL',
                "{$totals['employees']} employees",
                '',
                $totals['present'],
                $totals['late'],
                $totals['absent'],
                $totals['leave'],
                $totals['worked_hours'],
                $totals['late_minutes'],
                $totals['attendance_rate'],
                $totals['punctuality_rate'],
            ]);

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * Render the report as a downloadable PDF (dompdf, landscape A4).
     */
    public function exportPdf(Request $request, AttendanceReportAggregator $aggregator)
    {
        Gate::authorize('viewReports');

        [$from, $to, $department] = $this->filters($request);
        $report = $aggregator->report($from, $to, $department);

        $filename = "attendance-report-{$from->toDateString()}_to_{$to->toDateString()}.pdf";

        return Pdf::loadView('reports.attendance-pdf', ['report' => $report])
            ->setPaper('a4', 'portrait')
            ->download($filename);
    }

    /**
     * Resolve and validate the shared filter inputs.
     *
     * @return array{0: Carbon, 1: Carbon, 2: ?Department}
     */
    private function filters(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
        ]);

        $from = ! empty($validated['from'])
            ? Carbon::parse($validated['from'])->startOfDay()
            : Carbon::now()->startOfMonth();
        $to = ! empty($validated['to'])
            ? Carbon::parse($validated['to'])->startOfDay()
            : Carbon::now()->endOfMonth()->startOfDay();

        // Guard against inverted or unbounded ranges.
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }
        if ($from->diffInDays($to) > 366) {
            $to = $from->copy()->addDays(366);
        }

        $department = ! empty($validated['department_id'])
            ? Department::find($validated['department_id'])
            : null;

        return [$from, $to, $department];
    }

    /**
     * AI decision-support context: the highest-risk employees (latest scoring snapshot) and the
     * latest narrative summary matching the report's month/scope, if one has been generated.
     *
     * @return array<string, mixed>
     */
    private function decisionSupport(Carbon $from, ?Department $department): array
    {
        $employeeIds = null;
        if ($department) {
            $employeeIds = $department->employees()->pluck('id');
        }

        $highRisk = AiPrediction::with('employee:id,first_name,last_name')
            ->where('risk_level', 'high')
            ->when($employeeIds !== null, fn($q) => $q->whereIn('employee_id', $employeeIds))
            ->orderByDesc('risk_score')
            ->limit(5)
            ->get()
            ->map(fn(AiPrediction $p) => [
                'employee' => $p->employee?->fullName() ?? "#{$p->employee_id}",
                'risk_score' => round($p->risk_score, 3),
            ])
            ->values();

        $summary = ReportSummary::where('period_month', $from->copy()->startOfMonth()->toDateString())
            ->where('department_id', $department?->id)
            ->first();

        return [
            'highRisk' => $highRisk,
            'summary' => $summary ? [
                'period_label' => $summary->period_month->format('F Y'),
                'narrative' => $summary->narrative,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function queryParams(Carbon $from, Carbon $to, ?Department $department): array
    {
        return array_filter([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'department_id' => $department?->id,
        ], fn($value) => $value !== null);
    }
}
