<?php

namespace Tests\Feature;

use App\Models\AccountInvitation;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class PruneInvitationsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function employee(string $code = 'EMP-0001'): Employee
    {
        return Employee::create([
            'employee_code' => $code,
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'status' => 'active',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function invitation(array $attributes = []): AccountInvitation
    {
        static $n = 0;
        $n++;

        return AccountInvitation::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'employee_id' => $this->employee("EMP-000{$n}")->id,
            'email' => "applicant{$n}@example.test",
            'token' => Hash::make(Str::random(64)),
            'expires_at' => now()->addDays(3),
        ], $attributes));
    }

    public function test_it_deletes_invitations_that_expired_unused_long_ago(): void
    {
        $stale = $this->invitation(['expires_at' => now()->subDays(120)]);

        $this->artisan('invitations:prune')->assertSuccessful();

        $this->assertDatabaseMissing('account_invitations', ['id' => $stale->id]);
    }

    public function test_it_deletes_invitations_revoked_unused_long_ago(): void
    {
        $revoked = $this->invitation([
            'expires_at' => now()->addDays(3),
            'revoked_at' => now()->subDays(120),
        ]);

        $this->artisan('invitations:prune')->assertSuccessful();

        $this->assertDatabaseMissing('account_invitations', ['id' => $revoked->id]);
    }

    public function test_it_keeps_a_used_invitation_however_old(): void
    {
        // A used invitation is the evidence that a specific account was activated from a
        // specific approval — it is never housekeeping.
        $used = $this->invitation([
            'expires_at' => now()->subYears(3),
            'used_at' => now()->subYears(3),
        ]);

        $this->artisan('invitations:prune')->assertSuccessful();

        $this->assertDatabaseHas('account_invitations', ['id' => $used->id]);
    }

    public function test_it_keeps_a_live_invitation(): void
    {
        $live = $this->invitation(['expires_at' => now()->addDays(2)]);

        $this->artisan('invitations:prune')->assertSuccessful();

        $this->assertDatabaseHas('account_invitations', ['id' => $live->id]);
    }

    public function test_it_keeps_invitations_dead_inside_the_retention_window(): void
    {
        $recentlyExpired = $this->invitation(['expires_at' => now()->subDays(10)]);

        $this->artisan('invitations:prune')->assertSuccessful();

        $this->assertDatabaseHas('account_invitations', ['id' => $recentlyExpired->id]);

        // ...and a shorter window does reach it.
        $this->artisan('invitations:prune', ['--days' => 5])->assertSuccessful();

        $this->assertDatabaseMissing('account_invitations', ['id' => $recentlyExpired->id]);
    }

    public function test_dry_run_deletes_nothing(): void
    {
        $stale = $this->invitation(['expires_at' => now()->subDays(120)]);

        $this->artisan('invitations:prune', ['--dry-run' => true])->assertSuccessful();

        $this->assertDatabaseHas('account_invitations', ['id' => $stale->id]);
    }

    public function test_it_rejects_a_nonsensical_window(): void
    {
        $stale = $this->invitation(['expires_at' => now()->subDays(120)]);

        $this->artisan('invitations:prune', ['--days' => 0])->assertFailed();

        $this->assertDatabaseHas('account_invitations', ['id' => $stale->id]);
    }
}
