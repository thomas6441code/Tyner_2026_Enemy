<?php

namespace Tests\Feature;

use App\Enums\CheckInDirection;
use App\Enums\CheckInRejection;
use App\Enums\CheckInResult;
use App\Enums\RoleName;
use App\Models\Employee;
use App\Models\MobileCheckIn;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The Admin/HR mobile check-in log.
 *
 * The filters are the reason this is a table rather than JSON on the punch row, so they are
 * worth pinning: an auditor asking "who was refused at the geofence last Tuesday" must be able
 * to answer it from the UI.
 */
class MobileCheckInLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function employee(string $code = 'EMP-0001', string $first = 'Amina'): Employee
    {
        return Employee::create([
            'employee_code' => $code,
            'first_name' => $first,
            'last_name' => 'Juma',
            'status' => 'active',
        ]);
    }

    private function attempt(Employee $employee, array $overrides = []): MobileCheckIn
    {
        return MobileCheckIn::create($overrides + [
            'employee_id' => $employee->id,
            'work_date' => '2026-06-22',
            'direction' => CheckInDirection::In,
            'punched_at' => Carbon::parse('2026-06-22 08:05:00'),
            'latitude' => -6.7924,
            'longitude' => 39.2083,
            'accuracy_meters' => 10,
            'distance_meters' => 12,
            'within_geofence' => true,
            'webauthn_verified' => true,
            'result' => CheckInResult::Accepted,
        ]);
    }

    public function test_an_employee_cannot_open_the_log(): void
    {
        $this->actingAs($this->userWithRole(RoleName::Employee->value))
            ->get('/mobile-check-ins')
            ->assertForbidden();
    }

    public function test_hr_can_open_the_log(): void
    {
        $this->actingAs($this->userWithRole(RoleName::HrOfficer->value))
            ->get('/mobile-check-ins')
            ->assertOk();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/mobile-check-ins')->assertRedirect('/login');
    }

    public function test_rejected_attempts_appear_in_the_log_with_their_real_distance(): void
    {
        $employee = $this->employee();

        $this->attempt($employee, [
            'direction' => CheckInDirection::In,
            'distance_meters' => 1104,
            'within_geofence' => false,
            'result' => CheckInResult::Rejected,
            'rejection_reason' => CheckInRejection::OutsideGeofence,
        ]);

        // A refused attempt being visible, with how far outside it was, is the entire point of
        // persisting rejections rather than discarding them.
        $this->actingAs($this->userWithRole(RoleName::Admin->value))
            ->get('/mobile-check-ins')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('mobile-check-ins/index')
                ->where('checkIns.data.0.result', 'rejected')
                ->where('checkIns.data.0.rejection_reason', 'outside_geofence')
                ->where('checkIns.data.0.distance_meters', 1104)
                ->where('stats.rejected_today', 0));
    }

    public function test_the_log_filters_by_result(): void
    {
        $employee = $this->employee();

        $this->attempt($employee);
        $this->attempt($employee, [
            'direction' => CheckInDirection::Out,
            'result' => CheckInResult::Rejected,
            'rejection_reason' => CheckInRejection::OutsideGeofence,
        ]);

        $this->actingAs($this->userWithRole(RoleName::Admin->value))
            ->get('/mobile-check-ins?result=rejected')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('checkIns.data', 1)
                ->where('checkIns.data.0.result', 'rejected'));
    }

    public function test_the_log_filters_by_employee(): void
    {
        $amina = $this->employee();
        $baraka = $this->employee('EMP-0002', 'Baraka');

        $this->attempt($amina);
        $this->attempt($baraka);

        $this->actingAs($this->userWithRole(RoleName::Admin->value))
            ->get('/mobile-check-ins?employee='.$baraka->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('checkIns.data', 1)
                ->where('checkIns.data.0.employee_code', 'EMP-0002'));
    }

    public function test_the_log_filters_by_date_range(): void
    {
        $employee = $this->employee();

        $this->attempt($employee);
        $this->attempt($employee, [
            'work_date' => '2026-06-25',
            'punched_at' => Carbon::parse('2026-06-25 08:05:00'),
            'direction' => CheckInDirection::Out,
        ]);

        $this->actingAs($this->userWithRole(RoleName::Admin->value))
            ->get('/mobile-check-ins?from=2026-06-24&to=2026-06-26')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('checkIns.data', 1)
                ->where('checkIns.data.0.work_date', '2026-06-25'));
    }

    public function test_the_log_filters_to_flagged_attempts_only(): void
    {
        $employee = $this->employee();

        $this->attempt($employee);
        $this->attempt($employee, [
            'direction' => CheckInDirection::Out,
            'flagged' => true,
            'flag_reason' => 'Implied travel of 3000 km/h since the previous punch.',
        ]);

        $this->actingAs($this->userWithRole(RoleName::Admin->value))
            ->get('/mobile-check-ins?flagged=1')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('checkIns.data', 1)
                ->where('checkIns.data.0.flagged', true)
                ->where('stats.flagged', 1));
    }

    public function test_an_unknown_result_filter_is_ignored_rather_than_erroring(): void
    {
        $this->attempt($this->employee());

        $this->actingAs($this->userWithRole(RoleName::Admin->value))
            ->get('/mobile-check-ins?result=banana')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('checkIns.data', 1)->where('filters.result', null));
    }
}
