<?php

namespace App\Console\Commands;

use App\Enums\RoleName;
use App\Models\AiAnomaly;
use App\Models\AiPrediction;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Services\AiInsightsClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class ScoreAttendance extends Command
{
    protected $signature = 'ai:score-attendance
                            {--days=90 : How many days of attendance history to score}';

    protected $description = 'Send attendance history to the AI service and persist anomaly + risk scores';

    public function handle(AiInsightsClient $client): int
    {
        $days = max(1, (int) $this->option('days'));
        $from = Carbon::today()->subDays($days - 1)->startOfDay();
        $to = Carbon::today()->endOfDay();

        $records = $this->buildRecords($from, $to);

        if (empty($records)) {
            $this->info('No attendance records in the scoring window — nothing to score.');

            return self::SUCCESS;
        }

        $anomalyResult = $client->detectAnomalies($records);
        $predictionResult = $client->predictRisk($records, $to->toDateString());

        if ($anomalyResult === null && $predictionResult === null) {
            $this->warn('AI service unavailable — no scores were written (see logs).');

            return self::FAILURE;
        }

        $anomalies = $this->persistAnomalies($anomalyResult, $from, $to);
        $predictions = $this->persistPredictions($predictionResult);

        $this->notifyManagement($anomalies, $predictions);

        Log::info('ai.scored', [
            'window' => [$from->toDateString(), $to->toDateString()],
            'records' => count($records),
            'anomalies' => $anomalies,
            'predictions' => $predictions,
        ]);

        $this->info("Scored {$anomalies} anomaly record(s) and {$predictions} risk score(s) over ".count($records).' day-records.');

        return self::SUCCESS;
    }

    /**
     * Flatten the window's attendance into the AI contract shape. Only internal ids and
     * numeric/date features leave Laravel — never employee names (privacy guardrail).
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildRecords(Carbon $from, Carbon $to): array
    {
        $employees = Employee::where('status', 'active')->with('workSchedule')->get()->keyBy('id');

        return AttendanceRecord::whereIn('employee_id', $employees->keys())
            ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('employee_id')
            ->orderBy('work_date')
            ->get()
            ->map(function (AttendanceRecord $record) use ($employees) {
                $schedule = $employees->get($record->employee_id)?->workSchedule;

                return [
                    'employee_id' => $record->employee_id,
                    'work_date' => $record->work_date->toDateString(),
                    'status' => $record->status->value,
                    'first_in' => $record->first_in?->toIso8601String(),
                    'last_out' => $record->last_out?->toIso8601String(),
                    'worked_minutes' => $record->worked_minutes,
                    'late_minutes' => $record->late_minutes,
                    'early_leave_minutes' => $record->early_leave_minutes,
                    'is_leave' => $record->status->isLeave(),
                    'schedule_start' => $schedule ? substr((string) $schedule->start_time, 0, 5) : null,
                    'schedule_end' => $schedule ? substr((string) $schedule->end_time, 0, 5) : null,
                ];
            })
            ->all();
    }

    /**
     * Replace the window's anomalies for scored employees, then insert the fresh set. This
     * keeps re-runs idempotent (identical input → identical table state) and clears anomalies
     * that a newer batch no longer flags.
     *
     * @param  array<string, mixed>|null  $result
     */
    private function persistAnomalies(?array $result, Carbon $from, Carbon $to): int
    {
        if ($result === null) {
            return 0;
        }

        $anomalies = $result['anomalies'] ?? [];

        return DB::transaction(function () use ($anomalies, $from, $to) {
            AiAnomaly::whereBetween('work_date', [$from->toDateString(), $to->toDateString()])->delete();

            foreach ($anomalies as $item) {
                AiAnomaly::create([
                    'employee_id' => $item['employee_id'],
                    'work_date' => $item['work_date'],
                    'method' => $item['method'],
                    'score' => $item['score'],
                    'explanation' => $item['explanation'],
                    'features' => $item['features'] ?? [],
                ]);
            }

            return count($anomalies);
        });
    }

    /**
     * Upsert the latest risk score per employee.
     *
     * @param  array<string, mixed>|null  $result
     */
    private function persistPredictions(?array $result): int
    {
        if ($result === null) {
            return 0;
        }

        $predictions = $result['predictions'] ?? [];
        $version = $result['model']['version'] ?? null;
        $now = Carbon::now();

        foreach ($predictions as $item) {
            AiPrediction::updateOrCreate(
                ['employee_id' => $item['employee_id']],
                [
                    'risk_score' => $item['risk_score'],
                    'risk_level' => $item['risk_level'],
                    'top_factors' => $item['top_factors'] ?? [],
                    'model_version' => $version,
                    'computed_at' => $now,
                ],
            );
        }

        return count($predictions);
    }

    private function notifyManagement(int $anomalies, int $predictions): void
    {
        if ($anomalies === 0 && $predictions === 0) {
            return;
        }

        $recipients = User::role([RoleName::Admin->value, RoleName::HrOfficer->value])->get();

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, SystemNotification::attendanceAlert(
            'Attendance scoring completed',
            sprintf(
                'Latest AI scoring produced %d %s and %d risk score%s.',
                $anomalies,
                $anomalies === 1 ? 'anomaly' : 'anomalies',
                $predictions,
                $predictions === 1 ? '' : 's',
            ),
            route('ai-insights.index'),
            [
                'anomalies' => $anomalies,
                'predictions' => $predictions,
            ],
        ));
    }
}
