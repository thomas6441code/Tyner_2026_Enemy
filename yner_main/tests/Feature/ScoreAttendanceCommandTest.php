<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Models\AiAnomaly;
use App\Models\AiPrediction;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\WorkSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ScoreAttendanceCommandTest extends TestCase
{
    use RefreshDatabase;

    private function seedEmployeeWithHistory(): Employee
    {
        $schedule = WorkSchedule::create([
            'name' => 'Standard', 'start_time' => '08:00', 'end_time' => '17:00', 'grace_period_minutes' => 15,
        ]);
        $employee = Employee::create([
            'employee_code' => 'EMP-0001', 'first_name' => 'Ada', 'last_name' => 'Lovelace',
            'status' => 'active', 'work_schedule_id' => $schedule->id,
        ]);

        AttendanceRecord::create([
            'employee_id' => $employee->id,
            'work_date' => Carbon::today()->subDays(2)->toDateString(),
            'status' => AttendanceStatus::Present->value,
            'worked_minutes' => 480,
            'late_minutes' => 0,
        ]);

        return $employee;
    }

    private function fakeAiResponses(Employee $employee, string $workDate): void
    {
        Http::fake([
            '*/api/analysis/anomalies' => Http::response([
                'anomalies' => [[
                    'employee_id' => $employee->id,
                    'work_date' => $workDate,
                    'method' => 'zscore',
                    'score' => 3.42,
                    'explanation' => 'Sign-in far from average.',
                    'features' => ['z_score' => 3.42],
                ]],
                'meta' => ['count' => 1, 'method' => 'isolation_forest+zscore', 'generated_at' => now()->toIso8601String()],
            ], 200),
            '*/api/analysis/predictions' => Http::response([
                'predictions' => [[
                    'employee_id' => $employee->id,
                    'risk_score' => 0.72,
                    'risk_level' => 'high',
                    'top_factors' => [['feature' => 'absence_rate', 'value' => 0.3, 'importance' => 0.55]],
                ]],
                'model' => ['version' => 'rf-1.0', 'feature_importances' => ['absence_rate' => 0.55], 'metrics' => [], 'fallback' => false],
            ], 200),
        ]);
    }

    public function test_persists_anomalies_and_predictions(): void
    {
        $employee = $this->seedEmployeeWithHistory();
        $workDate = Carbon::today()->subDays(2)->toDateString();
        $this->fakeAiResponses($employee, $workDate);

        $this->artisan('ai:score-attendance')->assertSuccessful();

        $this->assertDatabaseHas('ai_anomalies', [
            'employee_id' => $employee->id,
            'work_date' => $workDate,
            'method' => 'zscore',
        ]);
        $this->assertDatabaseHas('ai_predictions', [
            'employee_id' => $employee->id,
            'risk_level' => 'high',
            'model_version' => 'rf-1.0',
        ]);
    }

    public function test_rerun_is_idempotent(): void
    {
        $employee = $this->seedEmployeeWithHistory();
        $workDate = Carbon::today()->subDays(2)->toDateString();
        $this->fakeAiResponses($employee, $workDate);

        $this->artisan('ai:score-attendance')->assertSuccessful();
        $this->artisan('ai:score-attendance')->assertSuccessful();

        $this->assertSame(1, AiAnomaly::count());
        $this->assertSame(1, AiPrediction::count());
    }

    public function test_returns_failure_and_writes_nothing_when_service_down(): void
    {
        $this->seedEmployeeWithHistory();
        Http::fake([
            '*/api/analysis/*' => Http::response('boom', 500),
        ]);

        $this->artisan('ai:score-attendance')->assertFailed();

        $this->assertSame(0, AiAnomaly::count());
        $this->assertSame(0, AiPrediction::count());
    }
}
