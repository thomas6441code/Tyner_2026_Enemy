<?php

namespace App\Http\Controllers;

use App\Models\AiAnomaly;
use App\Models\AiPrediction;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AiInsightController extends Controller
{
    /**
     * Read-only decision-support view (Admin/HR): the latest AI anomaly detections and
     * absenteeism-risk scores produced by `ai:score-attendance`. Rich charts land in Phase 10;
     * this is the tabular surface for the Phase 7 deliverable.
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', AiAnomaly::class);

        $anomalies = AiAnomaly::with('employee:id,first_name,last_name')
            ->orderByDesc('work_date')
            ->orderByDesc('score')
            ->limit(100)
            ->get()
            ->map(fn (AiAnomaly $a) => [
                'id' => $a->id,
                'employee' => $a->employee?->fullName() ?? "#{$a->employee_id}",
                'work_date' => $a->work_date->toDateString(),
                'method' => $a->method,
                'score' => round($a->score, 3),
                'explanation' => $a->explanation,
            ]);

        $predictions = AiPrediction::with('employee:id,first_name,last_name')
            ->orderByDesc('risk_score')
            ->get()
            ->map(fn (AiPrediction $p) => [
                'id' => $p->id,
                'employee' => $p->employee?->fullName() ?? "#{$p->employee_id}",
                'risk_score' => round($p->risk_score, 3),
                'risk_level' => $p->risk_level->value,
                'top_factors' => $p->top_factors ?? [],
                'computed_at' => $p->computed_at?->toDateTimeString(),
            ]);

        return Inertia::render('ai/insights', [
            'anomalies' => $anomalies->values(),
            'predictions' => $predictions->values(),
            'featureImportances' => $this->featureImportances(),
            'modelVersion' => AiPrediction::query()->value('model_version'),
        ]);
    }

    /**
     * The prediction model's global feature importances are the same across employees within a
     * run, so we reconstruct the display list from the persisted `top_factors` rather than
     * storing a separate copy. Highest importance per feature wins.
     *
     * @return array<int, array{feature: string, importance: float}>
     */
    private function featureImportances(): array
    {
        $importances = [];

        foreach (AiPrediction::query()->pluck('top_factors') as $factors) {
            foreach ((array) $factors as $factor) {
                $feature = $factor['feature'] ?? null;
                if ($feature === null) {
                    continue;
                }
                $value = (float) ($factor['importance'] ?? 0);
                $importances[$feature] = max($importances[$feature] ?? 0, $value);
            }
        }

        arsort($importances);

        return array_map(
            fn (string $feature, float $importance) => ['feature' => $feature, 'importance' => round($importance, 3)],
            array_keys($importances),
            array_values($importances),
        );
    }
}
