<?php

namespace Tests\Feature;

use App\Enums\RegistrationStatus;
use App\Enums\RoleName;
use App\Models\AccountInvitation;
use App\Models\Department;
use App\Models\Employee;
use App\Models\RegistrationRequest;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Notifications\SystemNotification;
use App\Support\MailDomainResolver;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RegistrationRequestTest extends TestCase
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

    private function pendingRequest(array $overrides = []): RegistrationRequest
    {
        $request = new RegistrationRequest(array_merge([
            'first_name' => 'Thomas',
            'last_name' => 'Bonaventure',
            'phone' => '0700000000',
        ], $overrides));

        $request->email = $overrides['email'] ?? 'thomas@example.com';
        $request->status = RegistrationStatus::Pending;
        $request->save();

        return $request;
    }

    private function approvalPayload(array $overrides = []): array
    {
        // firstOrCreate so a test may build the payload more than once without colliding
        // on the unique department/schedule names.
        return array_merge([
            'department_id' => Department::firstOrCreate(['name' => 'ICT'])->id,
            'work_schedule_id' => WorkSchedule::firstOrCreate(
                ['name' => 'Day'],
                ['start_time' => '08:00', 'end_time' => '17:00', 'grace_period_minutes' => 15],
            )->id,
            'hire_date' => '2026-08-01',
        ], $overrides);
    }

    /**
     * Force the deliverability check on (it is off under testing) with a resolver whose
     * verdict the test controls, so no DNS query is made.
     */
    private function fakeMailDomains(bool $accepts): void
    {
        config(['auth.registration.verify_email_domain' => true]);

        $this->app->instance(MailDomainResolver::class, new class($accepts) extends MailDomainResolver
        {
            public function __construct(private readonly bool $accepts) {}

            public function acceptsMail(string $email): bool
            {
                return $this->accepts;
            }
        });
    }

    public function test_email_is_required(): void
    {
        $this->post('/register', [
            'first_name' => 'Thomas',
            'last_name' => 'Bonaventure',
        ])->assertSessionHasErrors('email');

        $this->assertDatabaseCount('registration_requests', 0);
    }

    public function test_request_is_refused_when_the_email_domain_accepts_no_mail(): void
    {
        $this->fakeMailDomains(false);

        $this->post('/register', [
            'first_name' => 'Thomas',
            'last_name' => 'Bonaventure',
            'email' => 'thomas@no-such-domain.invalid',
        ])->assertSessionHasErrors('email');

        // Nothing is filed: an unreachable address could never redeem an invitation.
        $this->assertDatabaseCount('registration_requests', 0);
    }

    public function test_request_is_filed_when_the_email_domain_accepts_mail(): void
    {
        $this->fakeMailDomains(true);

        $this->post('/register', [
            'first_name' => 'Thomas',
            'last_name' => 'Bonaventure',
            'email' => 'thomas@gmail.com',
        ])->assertRedirect(route('registration-request.submitted'));

        $this->assertDatabaseHas('registration_requests', ['email' => 'thomas@gmail.com']);
    }

    public function test_approval_emails_the_activation_link_to_the_applicant(): void
    {
        Notification::fake();

        $request = $this->pendingRequest(['email' => 'applicant@example.com']);

        $response = $this->actingAs($this->userWithRole(RoleName::Admin))
            ->put(route('registration-requests.approve', $request), $this->approvalPayload());

        $activationUrl = $response->getSession()->get('invitationUrl');
        $this->assertNotNull($activationUrl);

        Notification::assertSentOnDemand(
            SystemNotification::class,
            function (SystemNotification $notification, array $channels, AnonymousNotifiable $notifiable) use ($activationUrl) {
                return $notifiable->routes['mail'] === 'applicant@example.com'
                    && in_array('mail', $channels, true)
                    // The link itself must be in the mail, not merely announced by it.
                    && $notification->toMail($notifiable)->actionUrl === $activationUrl;
            },
        );

        // The reviewer is told where it went, and still gets the link for manual delivery.
        $response->assertSessionHas('invitationEmail', 'applicant@example.com');
    }

    public function test_duplicate_email_is_absorbed_without_revealing_it(): void
    {
        $this->pendingRequest(['email' => 'taken@example.com']);

        $response = $this->post('/register', [
            'first_name' => 'Someone',
            'last_name' => 'Else',
            'email' => 'taken@example.com',
        ]);

        // Identical response to a fresh submission — no enumeration oracle.
        $response->assertRedirect(route('registration-request.submitted'));
        $this->assertSame(1, RegistrationRequest::where('email', 'taken@example.com')->count());
    }

    public function test_existing_account_email_is_absorbed(): void
    {
        User::factory()->create(['email' => 'staff@eapms.test']);

        $response = $this->post('/register', [
            'first_name' => 'Impostor',
            'last_name' => 'Person',
            'email' => 'staff@eapms.test',
        ]);

        $response->assertRedirect(route('registration-request.submitted'));
        $this->assertDatabaseCount('registration_requests', 0);
    }

    public function test_admin_and_hr_can_view_the_queue(): void
    {
        foreach ([RoleName::Admin, RoleName::HrOfficer] as $role) {
            $this->actingAs($this->userWithRole($role))
                ->get(route('registration-requests.index'))
                ->assertOk();
        }
    }

    public function test_employee_cannot_view_the_queue(): void
    {
        $this->actingAs($this->userWithRole(RoleName::Employee))
            ->get(route('registration-requests.index'))
            ->assertForbidden();
    }

    public function test_approval_creates_an_inactive_employee_and_an_invitation(): void
    {
        $request = $this->pendingRequest();
        $admin = $this->userWithRole(RoleName::Admin);

        $response = $this->actingAs($admin)
            ->put(route('registration-requests.approve', $request), $this->approvalPayload());

        $response->assertRedirect(route('registration-requests.index'));
        $response->assertSessionHas('invitationUrl');

        $employee = Employee::sole();
        // Inactive until activation, so AttendanceCalculator generates no phantom Absents.
        $this->assertSame('inactive', $employee->status);
        $this->assertNull($employee->user_id);
        // Allocated by the server, not supplied by the reviewer: first employee, first code.
        $this->assertSame('EMP-0001', $employee->employee_code);

        $request->refresh();
        $this->assertSame(RegistrationStatus::Approved, $request->status);
        $this->assertSame($employee->id, $request->employee_id);

        $invitation = AccountInvitation::sole();
        $this->assertTrue($invitation->isUsable());
        // Only the hash is stored; the plaintext lives solely in the flashed URL.
        $this->assertNotSame($invitation->token, $request->email);

        $this->assertDatabaseHas('audit_logs', ['action' => 'registration.approved']);
    }

    public function test_approval_continues_the_sequence_and_ignores_any_supplied_code(): void
    {
        Employee::create([
            'employee_code' => 'EMP-0100', 'first_name' => 'Existing',
            'last_name' => 'Staff', 'status' => 'active',
        ]);

        $request = $this->pendingRequest();

        // A duplicate is no longer something a reviewer can even ask for. Sending one anyway —
        // a crafted request, or a stale client — must be ignored rather than honoured, which is
        // the whole reason the field left the validated payload.
        $this->actingAs($this->userWithRole(RoleName::Admin))
            ->put(route('registration-requests.approve', $request), $this->approvalPayload([
                'employee_code' => 'EMP-0100',
            ]))
            ->assertRedirect(route('registration-requests.index'));

        $employee = Employee::where('first_name', 'Thomas')->sole();
        $this->assertSame('EMP-0101', $employee->employee_code);
        $this->assertDatabaseCount('account_invitations', 1);
    }

    public function test_an_already_approved_request_cannot_be_approved_again(): void
    {
        $request = $this->pendingRequest();
        $admin = $this->userWithRole(RoleName::Admin);

        $this->actingAs($admin)
            ->put(route('registration-requests.approve', $request), $this->approvalPayload());

        $this->actingAs($admin)
            ->put(route('registration-requests.approve', $request), $this->approvalPayload())
            ->assertForbidden();

        $this->assertDatabaseCount('account_invitations', 1);
    }

    public function test_rejection_requires_a_note(): void
    {
        $request = $this->pendingRequest();

        $this->actingAs($this->userWithRole(RoleName::Admin))
            ->put(route('registration-requests.reject', $request), ['review_note' => ''])
            ->assertSessionHasErrors('review_note');

        $this->assertSame(RegistrationStatus::Pending, $request->refresh()->status);
    }

    public function test_rejection_records_the_reason(): void
    {
        $request = $this->pendingRequest();

        $this->actingAs($this->userWithRole(RoleName::Admin))
            ->put(route('registration-requests.reject', $request), [
                'review_note' => 'Not a staff member.',
            ]);

        $request->refresh();
        $this->assertSame(RegistrationStatus::Rejected, $request->status);
        $this->assertSame('Not a staff member.', $request->review_note);
        $this->assertDatabaseHas('audit_logs', ['action' => 'registration.rejected']);
    }

    public function test_employee_cannot_approve(): void
    {
        $request = $this->pendingRequest();

        $this->actingAs($this->userWithRole(RoleName::Employee))
            ->put(route('registration-requests.approve', $request), $this->approvalPayload())
            ->assertForbidden();

        $this->assertDatabaseCount('employees', 0);
    }
}
