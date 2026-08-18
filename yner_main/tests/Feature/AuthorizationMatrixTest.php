<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * One readable role × route authorization proof (Phase 11 security check).
 *
 * Rather than re-testing each feature's happy path (already covered elsewhere), this locks the
 * *access-control matrix* in a single place: for every protected GET surface, which roles get
 * 200, which get 403, and that guests are always bounced to login. The expected column mirrors
 * the policies in app/Policies and the `viewReports` gate.
 */
class AuthorizationMatrixTest extends TestCase
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

    /**
     * path => list of roles allowed to load it (200). Any role not listed must get 403.
     *
     * @return array<string, array{0: string, 1: array<int, string>}>
     */
    public static function routeMatrix(): array
    {
        $admin = RoleName::Admin->value;
        $hr = RoleName::HrOfficer->value;
        $employee = RoleName::Employee->value;

        return [
            'departments' => ['/departments', [$admin, $hr]],
            'employees' => ['/employees', [$admin, $hr]],
            'work-schedules' => ['/work-schedules', [$admin, $hr]],
            'ai-insights' => ['/ai-insights', [$admin, $hr]],
            'reports' => ['/reports', [$admin, $hr]],
            'report-summaries' => ['/report-summaries', [$admin, $hr]],
            // The access queues hold personal details of people who are not employees yet.
            'registration-requests' => ['/registration-requests', [$admin, $hr]],
            'account-invitations' => ['/account-invitations', [$admin, $hr]],
            'biometric-devices' => ['/biometric-devices', [$admin]],
            'device-enrollments' => ['/device-enrollments', [$admin]],
            // Every authenticated role may see their attendance view.
            'attendance' => ['/attendance', [$admin, $hr, $employee]],
        ];
    }

    #[DataProvider('routeMatrix')]
    public function test_route_enforces_role_matrix(string $path, array $allowed): void
    {
        $roles = [RoleName::Admin->value, RoleName::HrOfficer->value, RoleName::Employee->value];

        foreach ($roles as $role) {
            $response = $this->actingAs($this->userWithRole($role))->get($path);

            if (in_array($role, $allowed, true)) {
                $response->assertOk();
            } else {
                $response->assertForbidden();
            }
        }
    }

    #[DataProvider('routeMatrix')]
    public function test_guests_are_redirected_to_login(string $path, array $allowed): void
    {
        $this->get($path)->assertRedirect('/login');
    }
}
