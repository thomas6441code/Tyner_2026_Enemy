<?php

namespace Tests\Feature;

use App\Enums\PermissionStatus;
use App\Enums\PermissionType;
use App\Enums\RoleName;
use App\Models\Department;
use App\Models\Employee;
use App\Models\PermissionRequest;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The shared `search` / `sort` / `direction` contract every index table now speaks.
 */
class IndexSearchSortTest extends TestCase
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

    private function employee(string $first, string $last, string $code, ?Department $department = null): Employee
    {
        return Employee::create([
            'employee_code' => $code,
            'first_name' => $first,
            'last_name' => $last,
            'status' => 'active',
            'department_id' => $department?->id,
        ]);
    }

    public function test_employees_are_searchable_by_name_and_code(): void
    {
        $this->employee('Asha', 'Mwangi', 'EMP-0001');
        $this->employee('Boniface', 'Zuberi', 'EMP-0002');

        $this->actingAs($this->admin())
            ->get('/employees?search=Zuberi')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('employees/index')
                ->has('employees.data', 1)
                ->where('employees.data.0.employee_code', 'EMP-0002')
                ->where('filters.search', 'Zuberi'));

        $this->actingAs($this->admin())
            ->get('/employees?search=EMP-0001')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('employees.data', 1)
                ->where('employees.data.0.fullName', 'Asha Mwangi'));
    }

    public function test_employees_search_matches_the_department_name(): void
    {
        $finance = Department::create(['name' => 'Finance']);
        $this->employee('Asha', 'Mwangi', 'EMP-0001', $finance);
        $this->employee('Boniface', 'Zuberi', 'EMP-0002');

        $this->actingAs($this->admin())
            ->get('/employees?search=Finance')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('employees.data', 1)
                ->where('employees.data.0.employee_code', 'EMP-0001'));
    }

    public function test_employees_can_be_sorted_by_a_related_column(): void
    {
        $alpha = Department::create(['name' => 'Alpha']);
        $omega = Department::create(['name' => 'Omega']);
        $this->employee('Asha', 'Mwangi', 'EMP-0001', $omega);
        $this->employee('Boniface', 'Zuberi', 'EMP-0002', $alpha);

        $this->actingAs($this->admin())
            ->get('/employees?sort=department&direction=asc')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('employees.data.0.employee_code', 'EMP-0002')
                ->where('filters.sort', 'department')
                ->where('filters.direction', 'asc'));

        $this->actingAs($this->admin())
            ->get('/employees?sort=department&direction=desc')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('employees.data.0.employee_code', 'EMP-0001'));
    }

    public function test_an_unknown_sort_key_falls_back_to_the_default(): void
    {
        $this->employee('Asha', 'Mwangi', 'EMP-0001');

        // The sort key reaches an ORDER BY, so anything outside the whitelist must be discarded
        // rather than passed through.
        $this->actingAs($this->admin())
            ->get('/employees?sort=(select+1)&direction=sideways')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('filters.sort', 'name')
                ->where('filters.direction', 'asc'));
    }

    public function test_permission_requests_are_searchable_and_status_filtered(): void
    {
        $asha = $this->employee('Asha', 'Mwangi', 'EMP-0001');
        $boni = $this->employee('Boniface', 'Zuberi', 'EMP-0002');

        PermissionRequest::create([
            'employee_id' => $asha->id,
            'type' => PermissionType::cases()[0],
            'start_date' => '2026-01-05',
            'end_date' => '2026-01-05',
            'reason' => 'Hospital appointment downtown',
            'status' => PermissionStatus::Pending,
        ]);
        PermissionRequest::create([
            'employee_id' => $boni->id,
            'type' => PermissionType::cases()[0],
            'start_date' => '2026-01-06',
            'end_date' => '2026-01-06',
            'reason' => 'Family funeral upcountry',
            'status' => PermissionStatus::Approved,
        ]);

        $this->actingAs($this->admin())
            ->get('/permission-requests?search=funeral')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('requests.data', 1)
                ->where('requests.data.0.employee', 'Boniface Zuberi'));

        // Searching the requester, not the request text.
        $this->actingAs($this->admin())
            ->get('/permission-requests?search=Mwangi')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('requests.data', 1)
                ->where('requests.data.0.employee', 'Asha Mwangi'));

        $this->actingAs($this->admin())
            ->get('/permission-requests?status_filter=approved')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('requests.data', 1)
                ->where('requests.data.0.status', 'approved'));
    }

    public function test_an_employee_search_cannot_widen_their_own_permission_scope(): void
    {
        $mine = $this->employee('Asha', 'Mwangi', 'EMP-0001');
        $theirs = $this->employee('Boniface', 'Zuberi', 'EMP-0002');

        $user = User::factory()->create();
        $user->assignRole(RoleName::Employee->value);
        $mine->update(['user_id' => $user->id]);

        foreach ([$mine, $theirs] as $employee) {
            PermissionRequest::create([
                'employee_id' => $employee->id,
                'type' => PermissionType::cases()[0],
                'start_date' => '2026-01-05',
                'end_date' => '2026-01-05',
                'reason' => 'Shared reason text',
                'status' => PermissionStatus::Pending,
            ]);
        }

        // The OR-ed search group must stay nested inside the ownership scope.
        $this->actingAs($user)
            ->get('/permission-requests?search=Shared')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('requests.data', 1)
                ->where('requests.data.0.employee', 'Asha Mwangi'));
    }

    public function test_filters_survive_pagination(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            $suffix = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            $this->employee('Common', 'Surname'.$suffix, 'EMP-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT));
        }

        $this->actingAs($this->admin())
            ->get('/employees?search=Common&sort=name&direction=desc')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('employees.data.0.employee_code', 'EMP-0020')
                // withQueryString(): page two of a filtered list has to stay filtered.
                ->where('employees.links.2.url', fn (string $url) => str_contains($url, 'search=Common')
                    && str_contains($url, 'direction=desc')));
    }
}
