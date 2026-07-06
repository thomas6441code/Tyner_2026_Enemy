<?php

namespace Database\Seeders;

use App\Enums\AttendanceStatus;
use App\Enums\RiskLevel;
use App\Models\AiAnomaly;
use App\Models\AiPrediction;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class AiInsightSeeder extends Seeder
{
    /**
     * Derive AI decision-support data from the real seeded attendance history so the Admin
     * dashboard and AI Insights page show live figures without the Python ai-service running.
     * The shapes match what `ai:score-attendance` persists, so re-running that command against
     * a live service simply overwrites these rows with genuine model output.
     */
    public function run(): void
    {
        $windowStart = Carbon::today()->subDays(89)->toDateString();
        $today = Carbon::today()->toDateString();
        $recentStart = Carbon::today()->subDays(29)->toDateString();
        $now = Carbon::now();

        $employees = Employee::where('status', 'active')->orderBy('id')->get();

        AiPrediction::query()->delete();
        AiAnomaly::query()->delete();

        foreach ($employees as $employee) {
            $records = AttendanceRecord::where('employee_id', $employee->id)
                ->whereBetween('work_date', [$windowStart, $today])
                ->get(['work_date', 'status', 'late_minutes', 'early_leave_minutes']);

            $total = $records->count();

            if ($total === 0) {
                continue;
            }

            $absent = $records->where('status', AttendanceStatus::Absent)->count();
            $late = $records->where('status', AttendanceStatus::Late)->count();
            $earlyLeave = $records->where('early_leave_minutes', '>', 0)->count();
            $avgLate = $late > 0
                ? (int) round($records->where('status', AttendanceStatus::Late)->avg('late_minutes'))
                : 0;

            $absenteeismRate = $absent / $total;
            $latenessRate = $late / $total;
            $earlyLeaveRate = $earlyLeave / $total;

            // Weighted blend, clamped to [0,1]. Absences dominate; lateness and early leaves add.
            $risk = min(1.0, 0.70 * $absenteeismRate + 0.30 * $latenessRate + 0.10 * $earlyLeaveRate);
            $risk = round($risk, 3);

            // Thresholds sit at the natural breaks in the seeded population: the persistently
            // late/absent profiles land >= 0.14, the middle band in [0.07, 0.14), the reliable
            // majority below. Tuned so the roster always yields a handful of each level.
            $level = match (true) {
                $risk >= 0.14 => RiskLevel::High,
                $risk >= 0.07 => RiskLevel::Medium,
                default => RiskLevel::Low,
            };

            AiPrediction::create([
                'employee_id' => $employee->id,
                'risk_score' => $risk,
                'risk_level' => $level->value,
                'top_factors' => $this->topFactors($absenteeismRate, $latenessRate, $earlyLeaveRate, $avgLate),
                'model_version' => 'seed-demo-1.0',
                'computed_at' => $now,
            ]);

            $this->seedAnomalies($employee->id, $records, $recentStart);
        }
    }

    /**
     * Per-employee contributing factors, ranked by importance, matching the AI contract shape
     * ({feature, importance}) that AiInsightController reads.
     *
     * @return list<array{feature: string, importance: float}>
     */
    private function topFactors(float $absenteeism, float $lateness, float $earlyLeave, int $avgLate): array
    {
        $factors = [
            ['feature' => 'absenteeism_rate', 'importance' => round(0.70 * $absenteeism, 3)],
            ['feature' => 'lateness_rate', 'importance' => round(0.30 * $lateness, 3)],
            ['feature' => 'avg_late_minutes', 'importance' => round(min(1.0, $avgLate / 60) * 0.20, 3)],
            ['feature' => 'early_leave_rate', 'importance' => round(0.10 * $earlyLeave, 3)],
        ];

        usort($factors, fn ($a, $b) => $b['importance'] <=> $a['importance']);

        return array_slice($factors, 0, 4);
    }

    /**
     * Flag the most extreme recent days (last 30) as anomalies: unexpected absences and
     * severe lateness. Capped per employee so the totals stay realistic.
     *
     * @param  Collection<int, AttendanceRecord>  $records
     */
    private function seedAnomalies(int $employeeId, $records, string $recentStart): void
    {
        $recent = $records->filter(fn (AttendanceRecord $r) => $r->work_date->toDateString() >= $recentStart);
        $flagged = 0;

        foreach ($recent as $record) {
            if ($flagged >= 2) {
                break;
            }

            if ($record->status === AttendanceStatus::Absent) {
                AiAnomaly::create([
                    'employee_id' => $employeeId,
                    'work_date' => $record->work_date->toDateString(),
                    'method' => 'isolation_forest',
                    'score' => round(0.6 + mt_rand(0, 350) / 1000, 3),
                    'explanation' => 'Unscheduled absence deviating from this employee\'s usual pattern.',
                    'features' => ['status' => 'absent', 'late_minutes' => 0, 'early_leave_minutes' => 0],
                ]);
                $flagged++;

                continue;
            }

            if ($record->status === AttendanceStatus::Late && $record->late_minutes >= 45) {
                AiAnomaly::create([
                    'employee_id' => $employeeId,
                    'work_date' => $record->work_date->toDateString(),
                    'method' => 'zscore',
                    'score' => round(min(0.99, 0.5 + $record->late_minutes / 120), 3),
                    'explanation' => "Arrival {$record->late_minutes} min late — well beyond the grace period.",
                    'features' => ['status' => 'late', 'late_minutes' => $record->late_minutes],
                ]);
                $flagged++;
            }
        }
    }
}
