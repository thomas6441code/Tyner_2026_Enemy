<?php

namespace Tests\Feature;

use App\Enums\DeviceResetStatus;
use App\Enums\DeviceStatus;
use App\Enums\RoleName;
use App\Models\DeviceResetRequest;
use App\Models\Employee;
use App\Models\User;
use App\Models\UserDevice;
use App\Notifications\SystemNotification;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The only sanctioned route from one linked device to the next.
 *
 * Employees cannot unlink their own phone — that would make the one-account-one-device rule a
 * two-click formality — so everything here is about the review gate: who may ask, who may
 * decide, what approval actually grants, and how quickly it stops granting it.
 */
class DeviceResetRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        config()->set('webauthn.rp_id', 'eapms.test');
    }

    private function userWithRole(string $role, string $status = 'active'): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        Employee::create([
            'user_id' => $user->id,
            'employee_code' => 'E'.fake()->unique()->numberBetween(1, 100_000),
            'first_name' => 'Amina',
            'last_name' => 'Juma',
            'status' => $status,
        ]);

        return $user->fresh();
    }

    private function employee(string $status = 'active'): User
    {
        return $this->userWithRole(RoleName::Employee->value, $status);
    }

    private function device(User $user): UserDevice
    {
        return UserDevice::create([
            'user_id' => $user->id,
            'credential_id' => 'cred-'.fake()->unique()->numberBetween(1, 100_000),
            'public_key' => '{}',
            'rp_id' => 'eapms.test',
            'device_name' => 'Old Phone',
        ]);
    }

    private function pendingRequest(User $user): DeviceResetRequest
    {
        $reset = new DeviceResetRequest(['reason' => 'My phone was stolen last night.']);
        $reset->user_id = $user->id;
        $reset->employee_id = $user->employee?->id;
        $reset->user_device_id = UserDevice::boundTo($user->id)?->id;
        $reset->save();

        return $reset;
    }

    /*
    |--------------------------------------------------------------------------
    | Asking
    |--------------------------------------------------------------------------
    */

    public function test_an_employee_can_request_a_device_reset(): void
    {
        Notification::fake();

        $user = $this->employee();
        $device = $this->device($user);
        $this->userWithRole(RoleName::Admin->value);

        $this->actingAs($user)
            ->post('/device-reset-requests', ['reason' => 'My phone was stolen last night.'])
            ->assertRedirect();

        $this->assertDatabaseHas('device_reset_requests', [
            'user_id' => $user->id,
            'user_device_id' => $device->id,
            'status' => DeviceResetStatus::Pending->value,
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'device_reset.requested']);

        // Reviewers hear about it immediately — a request nobody knows about is a person who
        // cannot check in tomorrow.
        Notification::assertSentTo(User::role(RoleName::Admin->value)->get(), SystemNotification::class);
    }

    public function test_a_second_pending_request_is_refused(): void
    {
        $user = $this->employee();
        $this->device($user);
        $this->pendingRequest($user);

        $this->actingAs($user)
            ->post('/device-reset-requests', ['reason' => 'Asking again just in case.'])
            ->assertRedirect();

        // Banking approvals is exactly how an employee would re-link repeatedly without the
        // pattern ever being visible to a reviewer.
        $this->assertSame(1, DeviceResetRequest::where('user_id', $user->id)->count());
    }

    public function test_a_reason_is_required(): void
    {
        $user = $this->employee();
        $this->device($user);

        $this->actingAs($user)
            ->post('/device-reset-requests', ['reason' => 'lost'])
            ->assertSessionHasErrors('reason');
    }

    public function test_an_employee_whose_device_was_revoked_can_still_request_a_reset(): void
    {
        $user = $this->employee();
        $this->device($user)->revoke('Revoked by an administrator.');

        // The stranded case: no device to unlink and no approval to register with. Without a
        // way to ask, an Admin revoking a suspicious phone would permanently lock its owner out
        // of mobile check-in.
        $this->assertFalse($user->fresh()->can('create', UserDevice::class));

        $this->actingAs($user)
            ->get('/devices')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('actions.requestReset', true));

        $this->actingAs($user)
            ->post('/device-reset-requests', ['reason' => 'My old phone was revoked and I need a new one.'])
            ->assertRedirect();

        $this->assertDatabaseHas('device_reset_requests', ['user_id' => $user->id]);
    }

    public function test_an_employee_with_a_working_device_is_not_offered_registration(): void
    {
        $user = $this->employee();
        $this->device($user);

        $this->actingAs($user)
            ->get('/devices')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('actions.register', false)
                ->where('actions.requestReset', true)
                ->where('binding.has_device', true));
    }

    public function test_a_user_with_no_employee_record_cannot_request(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RoleName::Employee->value);

        $this->actingAs($user)
            ->post('/device-reset-requests', ['reason' => 'My phone was stolen last night.'])
            ->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | Reviewing
    |--------------------------------------------------------------------------
    */

    public function test_the_queue_is_admin_and_hr_only(): void
    {
        $this->actingAs($this->employee())->get('/device-reset-requests')->assertForbidden();
        $this->actingAs($this->userWithRole(RoleName::HrOfficer->value))->get('/device-reset-requests')->assertOk();
        $this->actingAs($this->userWithRole(RoleName::Admin->value))->get('/device-reset-requests')->assertOk();
    }

    public function test_an_employee_cannot_approve_their_own_request(): void
    {
        $user = $this->employee();
        $this->device($user);
        $reset = $this->pendingRequest($user);

        $this->actingAs($user)
            ->put("/device-reset-requests/{$reset->id}/approve")
            ->assertForbidden();

        $this->assertSame(DeviceResetStatus::Pending, $reset->fresh()->status);
    }

    public function test_approval_revokes_the_old_device_and_opens_the_window(): void
    {
        Notification::fake();

        $user = $this->employee();
        $device = $this->device($user);
        $reset = $this->pendingRequest($user);
        $admin = $this->userWithRole(RoleName::Admin->value);

        $this->actingAs($admin)
            ->put("/device-reset-requests/{$reset->id}/approve", ['review_note' => 'Confirmed by phone.'])
            ->assertRedirect('/device-reset-requests');

        // Both halves, or neither: an approval that opened the window without revoking would
        // momentarily allow two active devices, which the unique index would then refuse —
        // leaving the employee approved and still unable to register.
        $this->assertSame(DeviceStatus::Revoked, $device->fresh()->status);
        $this->assertNull($device->fresh()->active_user_id);

        $reset->refresh();
        $this->assertSame(DeviceResetStatus::Approved, $reset->status);
        $this->assertTrue($reset->isUsable());
        $this->assertSame($admin->id, $reset->reviewed_by);

        $this->assertTrue($user->fresh()->can('create', UserDevice::class));
        $this->assertDatabaseHas('audit_logs', ['action' => 'device_reset.approved']);
        Notification::assertSentTo($user, SystemNotification::class);
    }

    public function test_an_hr_officer_can_approve(): void
    {
        $user = $this->employee();
        $this->device($user);
        $reset = $this->pendingRequest($user);

        $this->actingAs($this->userWithRole(RoleName::HrOfficer->value))
            ->put("/device-reset-requests/{$reset->id}/approve")
            ->assertRedirect('/device-reset-requests');

        $this->assertSame(DeviceResetStatus::Approved, $reset->fresh()->status);
    }

    public function test_an_already_reviewed_request_cannot_be_reviewed_again(): void
    {
        $user = $this->employee();
        $this->device($user);
        $reset = $this->pendingRequest($user);
        $admin = $this->userWithRole(RoleName::Admin->value);

        $this->actingAs($admin)->put("/device-reset-requests/{$reset->id}/approve")->assertRedirect();

        // A second approval would revoke a device that has already been replaced and re-open a
        // window that was meant to be spent.
        $this->actingAs($admin)->put("/device-reset-requests/{$reset->id}/approve")->assertForbidden();
    }

    public function test_rejection_requires_a_reason_and_leaves_the_device_linked(): void
    {
        Notification::fake();

        $user = $this->employee();
        $device = $this->device($user);
        $reset = $this->pendingRequest($user);
        $admin = $this->userWithRole(RoleName::Admin->value);

        $this->actingAs($admin)
            ->put("/device-reset-requests/{$reset->id}/reject")
            ->assertSessionHasErrors('review_note');

        $this->actingAs($admin)
            ->put("/device-reset-requests/{$reset->id}/reject", ['review_note' => 'Phone was found.'])
            ->assertRedirect('/device-reset-requests');

        $this->assertSame(DeviceResetStatus::Rejected, $reset->fresh()->status);
        $this->assertSame(DeviceStatus::Active, $device->fresh()->status);
        $this->assertFalse($user->fresh()->can('create', UserDevice::class));
        Notification::assertSentTo($user, SystemNotification::class);
    }

    /*
    |--------------------------------------------------------------------------
    | What approval actually grants
    |--------------------------------------------------------------------------
    */

    public function test_an_approval_is_not_usable_once_spent(): void
    {
        $user = $this->employee();
        $this->device($user);
        $reset = $this->pendingRequest($user);

        $this->actingAs($this->userWithRole(RoleName::Admin->value))
            ->put("/device-reset-requests/{$reset->id}/approve");

        $reset->refresh()->forceFill(['used_at' => now()])->save();

        $this->assertFalse($reset->fresh()->isUsable());
        $this->assertFalse($user->fresh()->can('create', UserDevice::class));
    }

    public function test_an_approval_expires(): void
    {
        config()->set('device.reset_window_hours', 1);

        $user = $this->employee();
        $this->device($user);
        $reset = $this->pendingRequest($user);

        $this->actingAs($this->userWithRole(RoleName::Admin->value))
            ->put("/device-reset-requests/{$reset->id}/approve");

        $this->assertTrue($reset->fresh()->isUsable());

        $this->travel(2)->hours();

        $this->assertFalse($reset->fresh()->isUsable());
        $this->assertFalse($user->fresh()->can('create', UserDevice::class));
    }
}
