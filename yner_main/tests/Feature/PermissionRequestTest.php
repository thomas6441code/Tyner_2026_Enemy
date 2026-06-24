<?php

namespace Tests\Feature;

use App\Enums\PermissionStatus;
use App\Enums\PermissionType;
use App\Enums\RoleName;
use App\Events\PermissionRequestApproved;
use App\Models\Employee;
use App\Models\PermissionRequest;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PermissionRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function userWithRole(RoleName $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }

    private function employeeUser(string $code = 'EMP-0001'): array
    {
        $user = $this->userWithRole(RoleName::Employee);
        $employee = Employee::create([
            'employee_code' => $code, 'first_name' => 'Test', 'last_name' => 'Person',
            'status' => 'active', 'user_id' => $user->id,
        ]);

        return [$user, $employee];
    }

    private function pending(Employee $employee, array $overrides = []): PermissionRequest
    {
        return PermissionRequest::create(array_merge([
            'employee_id' => $employee->id,
            'type' => PermissionType::SickLeave,
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-02',
            'reason' => 'Unwell.',
            'status' => PermissionStatus::Pending,
        ], $overrides));
    }

    public function test_employee_can_submit_a_request(): void
    {
        [$user] = $this->employeeUser();

        $this->actingAs($user)->post('/permission-requests', [
            'type' => PermissionType::SickLeave->value,
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-02',
            'reason' => 'Flu — two days rest.',
        ])->assertRedirect('/permission-requests');

        $this->assertDatabaseHas('permission_requests', [
            'type' => PermissionType::SickLeave->value,
            'status' => PermissionStatus::Pending->value,
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'permission.submitted']);
    }

    public function test_user_without_employee_cannot_submit(): void
    {
        $user = $this->userWithRole(RoleName::Employee);

        $this->actingAs($user)->post('/permission-requests', [
            'type' => PermissionType::SickLeave->value,
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-02',
            'reason' => 'No linked employee record.',
        ])->assertForbidden();
    }

    public function test_validation_rejects_bad_input(): void
    {
        [$user] = $this->employeeUser();

        // end before start
        $this->actingAs($user)->post('/permission-requests', [
            'type' => PermissionType::SickLeave->value,
            'start_date' => '2026-07-05',
            'end_date' => '2026-07-01',
            'reason' => 'Backwards range.',
        ])->assertSessionHasErrors('end_date');

        // missing reason
        $this->actingAs($user)->post('/permission-requests', [
            'type' => PermissionType::SickLeave->value,
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-02',
        ])->assertSessionHasErrors('reason');

        // bad type
        $this->actingAs($user)->post('/permission-requests', [
            'type' => 'vacation',
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-02',
            'reason' => 'Unknown type.',
        ])->assertSessionHasErrors('type');
    }

    public function test_employee_sees_only_own_requests(): void
    {
        [$user, $employee] = $this->employeeUser('EMP-0001');
        $this->pending($employee);

        [, $other] = $this->employeeUser('EMP-0002');
        $this->pending($other);

        $response = $this->actingAs($user)->get('/permission-requests');
        $response->assertInertia(fn ($page) => $page->component('permission-requests/index')
            ->where('requests.total', 1));
    }

    public function test_admin_and_hr_see_all_requests(): void
    {
        [, $employee] = $this->employeeUser('EMP-0001');
        $this->pending($employee);
        [, $other] = $this->employeeUser('EMP-0002');
        $this->pending($other);

        $hr = $this->userWithRole(RoleName::HrOfficer);

        $this->actingAs($hr)->get('/permission-requests')
            ->assertInertia(fn ($page) => $page->where('requests.total', 2));
    }

    public function test_hr_can_approve_and_event_is_dispatched(): void
    {
        Event::fake([PermissionRequestApproved::class]);

        [, $employee] = $this->employeeUser();
        $request = $this->pending($employee);
        $hr = $this->userWithRole(RoleName::HrOfficer);

        $this->actingAs($hr)->put("/permission-requests/{$request->id}/review", [
            'decision' => 'approved',
            'review_note' => 'Approved.',
        ])->assertRedirect('/permission-requests');

        $request->refresh();
        $this->assertSame(PermissionStatus::Approved, $request->status);
        $this->assertSame($hr->id, $request->reviewed_by);
        $this->assertNotNull($request->reviewed_at);

        $this->assertDatabaseHas('audit_logs', ['action' => 'permission.reviewed']);
        Event::assertDispatched(PermissionRequestApproved::class);
    }

    public function test_rejection_requires_a_note(): void
    {
        [, $employee] = $this->employeeUser();
        $request = $this->pending($employee);
        $hr = $this->userWithRole(RoleName::HrOfficer);

        $this->actingAs($hr)->put("/permission-requests/{$request->id}/review", [
            'decision' => 'rejected',
        ])->assertSessionHasErrors('review_note');

        $this->actingAs($hr)->put("/permission-requests/{$request->id}/review", [
            'decision' => 'rejected',
            'review_note' => 'Insufficient notice.',
        ])->assertRedirect('/permission-requests');

        $this->assertSame(PermissionStatus::Rejected, $request->refresh()->status);
    }

    public function test_employee_cannot_review(): void
    {
        [$user, $employee] = $this->employeeUser();
        $request = $this->pending($employee);

        $this->actingAs($user)->put("/permission-requests/{$request->id}/review", [
            'decision' => 'approved',
        ])->assertForbidden();
    }

    public function test_cannot_review_an_already_reviewed_request(): void
    {
        [, $employee] = $this->employeeUser();
        $request = $this->pending($employee, ['status' => PermissionStatus::Approved]);
        $hr = $this->userWithRole(RoleName::HrOfficer);

        $this->actingAs($hr)->put("/permission-requests/{$request->id}/review", [
            'decision' => 'rejected',
            'review_note' => 'Changed my mind.',
        ])->assertForbidden();
    }

    public function test_owner_can_cancel_pending_but_not_after_approval(): void
    {
        [$user, $employee] = $this->employeeUser();
        $request = $this->pending($employee);

        $this->actingAs($user)->delete("/permission-requests/{$request->id}")
            ->assertRedirect('/permission-requests');
        $this->assertSame(PermissionStatus::Cancelled, $request->refresh()->status);

        $approved = $this->pending($employee, ['status' => PermissionStatus::Approved, 'start_date' => '2026-08-01', 'end_date' => '2026-08-01']);
        $this->actingAs($user)->delete("/permission-requests/{$approved->id}")->assertForbidden();
    }

    public function test_attachment_is_stored_and_download_is_gated(): void
    {
        Storage::fake('local');

        [$user, $employee] = $this->employeeUser('EMP-0001');

        $this->actingAs($user)->post('/permission-requests', [
            'type' => PermissionType::SickLeave->value,
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-02',
            'reason' => 'Medical note attached.',
            'attachment' => UploadedFile::fake()->create('note.pdf', 100, 'application/pdf'),
        ])->assertRedirect('/permission-requests');

        $request = PermissionRequest::first();
        $this->assertNotNull($request->attachment_path);
        Storage::disk('local')->assertExists($request->attachment_path);

        // Owner can download.
        $this->actingAs($user)->get("/permission-requests/{$request->id}/attachment")->assertOk();

        // An unrelated employee cannot.
        [$stranger] = $this->employeeUser('EMP-0009');
        $this->actingAs($stranger)->get("/permission-requests/{$request->id}/attachment")->assertForbidden();
    }
}
