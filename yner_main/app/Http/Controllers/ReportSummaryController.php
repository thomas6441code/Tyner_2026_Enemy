<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\ReportSummary;
use App\Services\AiInsightsClient;
use App\Services\MonthlyReportAggregator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class ReportSummaryController extends Controller
{
    /**
     * AI-generated monthly narrative summaries (Phase 8, feature 6.6.5). Admin/HR only.
     * The report page (Phase 10) will link to these; this is the standalone surface.
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', ReportSummary::class);

        $summaries = ReportSummary::with('department:id,name')
            ->orderByDesc('period_month')
            ->orderBy('department_id')
            ->limit(60)
            ->get()
            ->map(fn (ReportSummary $s) => [
                'id' => $s->id,
                'period_month' => $s->period_month->toDateString(),
                'period_label' => $s->period_month->format('F Y'),
                'scope' => $s->department ? $s->department->name.' department' : 'Organization-wide',
                'narrative' => $s->narrative,
                'highlights' => $s->highlights ?? [],
                'recommendations' => $s->recommendations ?? [],
                'model' => $s->model,
                'fallback' => $s->fallback,
                'generated_at' => $s->updated_at?->toDateTimeString(),
            ]);

        return Inertia::render('reports/summaries', [
            'summaries' => $summaries->values(),
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'defaultMonth' => Carbon::now()->subMonthNoOverflow()->format('Y-m'),
        ]);
    }

    /**
     * Generate (or load from cache) a monthly summary. Each (month, department) is summarized
     * by Claude at most once unless `force` is checked. Degrades gracefully: an unreachable AI
     * service flashes an error rather than 500-ing.
     */
    public function store(Request $request, AiInsightsClient $client, MonthlyReportAggregator $aggregator): RedirectResponse
    {
        $this->authorize('create', ReportSummary::class);

        $validated = $request->validate([
            'period' => ['required', 'date_format:Y-m'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'force' => ['sometimes', 'boolean'],
        ]);

        $month = Carbon::createFromFormat('Y-m', $validated['period'])->startOfMonth();
        $departmentId = $validated['department_id'] ?? null;
        $force = (bool) ($validated['force'] ?? false);

        $existing = ReportSummary::where('period_month', $month->toDateString())
            ->where('department_id', $departmentId)
            ->first();

        if ($existing && ! $force) {
            return back()->with('status', 'Loaded the existing summary for this period (cached).');
        }

        $department = $departmentId ? Department::find($departmentId) : null;
        $stats = $aggregator->aggregate($month, $department);

        $result = $client->summarize($stats);

        if ($result === null) {
            return back()->with('status', 'The AI service is unavailable — no summary was generated. Please try again later.');
        }

        ReportSummary::updateOrCreate(
            ['period_month' => $month->toDateString(), 'department_id' => $departmentId],
            [
                'narrative' => $result['narrative'] ?? '',
                'highlights' => $result['highlights'] ?? [],
                'recommendations' => $result['recommendations'] ?? [],
                'stats' => $stats,
                'model' => $result['model'] ?? null,
                'fallback' => (bool) ($result['fallback'] ?? false),
                'generated_by' => $request->user()?->id,
            ],
        );

        $note = ($result['fallback'] ?? false)
            ? 'Summary generated with the offline template (Claude API unavailable).'
            : 'AI summary generated.';

        return back()->with('status', $note);
    }
}
