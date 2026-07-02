<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\RoleName;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\ReportSummary;
use App\Models\User;
use App\Models\WorkSchedule;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ReportSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole(RoleName::Admin->value);

        return $admin;
    }

    private function period(): string
    {
        return Carbon::now()->subMonthNoOverflow()->format('Y-m');
    }

    private function seedMonthOfAttendance(): void
    {
        $schedule = WorkSchedule::create([
            'name' => 'Standard', 'start_time' => '08:00', 'end_time' => '17:00', 'grace_period_minutes' => 15,
        ]);
        $employee = Employee::create([
            'employee_code' => 'EMP-0001', 'first_name' => 'Ada', 'last_name' => 'Lovelace',
            'status' => 'active', 'work_schedule_id' => $schedule->id,
        ]);

        $month = Carbon::now()->subMonthNoOverflow()->startOfMonth();
        foreach ([AttendanceStatus::Present, AttendanceStatus::Late, AttendanceStatus::Absent] as $i => $status) {
            AttendanceRecord::create([
                'employee_id' => $employee->id,
                'work_date' => $month->copy()->addDays($i)->toDateString(),
                'status' => $status->value,
                'worked_minutes' => 480,
                'late_minutes' => $status === AttendanceStatus::Late ? 20 : 0,
            ]);
        }
    }

    private function fakeSummary(bool $fallback = false): void
    {
        Http::fake([
            '*/api/analysis/summary' => Http::response([
                'narrative' => 'Attendance was strong this month.',
                'highlights' => ['92% attendance', 'Punctuality up'],
                'recommendations' => ['Keep monitoring late arrivals'],
                'model' => $fallback ? 'template-fallback' : 'claude-sonnet-4-6',
                'fallback' => $fallback,
                'generated_at' => now()->toIso8601String(),
            ], 200),
        ]);
    }

    public function test_admin_can_view_page(): void
    {
        $this->actingAs($this->admin())->get('/report-summaries')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('reports/summaries'));
    }

    public function test_employee_cannot_view_or_generate(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RoleName::Employee->value);

        $this->actingAs($user)->get('/report-summaries')->assertForbidden();
        $this->actingAs($user)->post('/report-summaries', ['period' => $this->period()])->assertForbidden();
    }

    public function test_generates_and_persists_a_summary(): void
    {
        $this->seedMonthOfAttendance();
        $this->fakeSummary();

        $this->actingAs($this->admin())
            ->post('/report-summaries', ['period' => $this->period(), 'department_id' => null])
            ->assertRedirect();

        $this->assertSame(1, ReportSummary::count());
        $this->assertDatabaseHas('report_summaries', [
            'narrative' => 'Attendance was strong this month.',
            'model' => 'claude-sonnet-4-6',
            'department_id' => null,
        ]);
    }

    public function test_second_request_is_served_from_cache(): void
    {
        $this->seedMonthOfAttendance();
        $this->fakeSummary();
        $admin = $this->admin();

        $this->actingAs($admin)->post('/report-summaries', ['period' => $this->period()])->assertRedirect();
        $this->actingAs($admin)->post('/report-summaries', ['period' => $this->period()])->assertRedirect();

        $this->assertSame(1, ReportSummary::count());
        // Only the first (uncached) request hit the AI service.
        Http::assertSentCount(1);
    }

    public function test_force_regenerates_and_calls_service_again(): void
    {
        $this->seedMonthOfAttendance();
        $this->fakeSummary();
        $admin = $this->admin();

        $this->actingAs($admin)->post('/report-summaries', ['period' => $this->period()])->assertRedirect();
        $this->actingAs($admin)->post('/report-summaries', ['period' => $this->period(), 'force' => true])->assertRedirect();

        $this->assertSame(1, ReportSummary::count());
        Http::assertSentCount(2);
    }

    public function test_service_unavailable_writes_nothing(): void
    {
        $this->seedMonthOfAttendance();
        Http::fake(['*/api/analysis/summary' => Http::response('boom', 500)]);

        $this->actingAs($this->admin())
            ->post('/report-summaries', ['period' => $this->period()])
            ->assertRedirect();

        $this->assertSame(0, ReportSummary::count());
    }
}
