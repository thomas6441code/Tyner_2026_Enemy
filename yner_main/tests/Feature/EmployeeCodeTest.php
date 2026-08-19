<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Services\EmployeeCodeGenerator;
use Database\Seeders\OrgSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\WorkLocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Employee codes: allocated by the server, sequential, never reused, never typed.
 *
 * The code is the join key between an HR record, its biometric enrolments, and every exported
 * report — so the interesting cases are not "does it count up" but what happens around the
 * edges: existing data, foreign formats, gaps left by deletions, and the seeder, which used to
 * be the one place a hand-written code was still acceptable.
 */
class EmployeeCodeTest extends TestCase
{
    use RefreshDatabase;

    private function generator(): EmployeeCodeGenerator
    {
        return app(EmployeeCodeGenerator::class);
    }

    private function employee(array $overrides = []): Employee
    {
        return Employee::create($overrides + [
            'first_name' => 'Test',
            'last_name' => 'Person'.fake()->unique()->numberBetween(1, 100_000),
            'status' => 'active',
        ]);
    }

    public function test_the_first_employee_gets_the_first_code(): void
    {
        $this->assertSame('EMP-0001', $this->generator()->peek());
        $this->assertSame('EMP-0001', $this->employee()->employee_code);
    }

    public function test_codes_are_allocated_in_sequence(): void
    {
        $codes = collect(range(1, 5))->map(fn () => $this->employee()->employee_code)->all();

        $this->assertSame(['EMP-0001', 'EMP-0002', 'EMP-0003', 'EMP-0004', 'EMP-0005'], $codes);
    }

    public function test_the_sequence_continues_from_the_highest_existing_code(): void
    {
        $this->employee(['employee_code' => 'EMP-0042']);

        // Continues from the maximum, not from the row count — otherwise an imported roster
        // would silently start colliding from its second insert.
        $this->assertSame('EMP-0043', $this->employee()->employee_code);
    }

    public function test_a_deleted_employees_code_is_not_reissued(): void
    {
        $this->employee();
        $second = $this->employee();
        $second->delete();

        // Gaps are correct. Reusing EMP-0002 would attach the departed employee's historical
        // punches, permission requests and reports to whoever came next.
        $this->assertSame('EMP-0003', $this->employee()->employee_code);
    }

    public function test_codes_outside_the_prefix_are_ignored_by_the_sequence(): void
    {
        // Legacy or imported formats coexist rather than derailing the count.
        $this->employee(['employee_code' => 'LEGACY-9999']);
        $this->employee(['employee_code' => 'EMP-0007']);

        $this->assertSame('EMP-0008', $this->employee()->employee_code);
    }

    public function test_a_prefixed_code_with_no_number_does_not_derail_the_sequence(): void
    {
        $this->employee(['employee_code' => 'EMP-TEMP']);

        $this->assertSame('EMP-0001', $this->employee()->employee_code);
    }

    public function test_the_prefix_and_padding_are_configurable(): void
    {
        config()->set('employee.code_prefix', 'IFM/');
        config()->set('employee.code_padding', 6);

        $this->assertSame('IFM/000001', $this->employee()->employee_code);
    }

    public function test_changing_the_prefix_starts_a_new_sequence_without_renumbering(): void
    {
        $existing = $this->employee();

        config()->set('employee.code_prefix', 'IFM/');

        // Existing codes are never rewritten — device enrolments and historical reports refer
        // to them by value.
        $this->assertSame('EMP-0001', $existing->fresh()->employee_code);
        $this->assertSame('IFM/0001', $this->employee()->employee_code);
    }

    public function test_an_explicitly_supplied_code_is_honoured(): void
    {
        // Seeders and fixtures pin codes deliberately; only the user-facing paths are stripped.
        $this->assertSame('EMP-0500', $this->employee(['employee_code' => 'EMP-0500'])->employee_code);
    }

    public function test_the_generators_create_path_discards_a_supplied_code(): void
    {
        $this->employee();

        $employee = $this->generator()->create([
            'employee_code' => 'EMP-9999',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'status' => 'active',
        ]);

        $this->assertSame('EMP-0002', $employee->employee_code);
    }

    public function test_peek_does_not_reserve_anything(): void
    {
        // Purely a display value. Calling it repeatedly must not advance the sequence, or the
        // form would burn a code every time it was opened and closed.
        $this->assertSame('EMP-0001', $this->generator()->peek());
        $this->assertSame('EMP-0001', $this->generator()->peek());
        $this->assertSame('EMP-0001', $this->employee()->employee_code);
    }

    /*
    |--------------------------------------------------------------------------
    | The seeder
    |--------------------------------------------------------------------------
    */

    public function test_the_seeder_produces_the_documented_sequence(): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(OrgSeeder::class);

        // OrgSeeder no longer carries hard-coded codes, but PermissionSeeder and its own
        // work-location assignment look employees up by code. This pins the mapping those
        // depend on: array order in, EMP-0001..EMP-0024 out.
        $this->assertSame(24, Employee::count());
        $this->assertSame('EMP-0001', Employee::where('last_name', 'Mwakasege')->sole()->employee_code);
        $this->assertSame('EMP-0004', Employee::where('last_name', 'Mushi')->sole()->employee_code);
        $this->assertSame('EMP-0012', Employee::where('last_name', 'Nyerere')->sole()->employee_code);
        $this->assertSame('EMP-0024', Employee::where('last_name', 'Mrema')->sole()->employee_code);
    }

    public function test_reseeding_does_not_duplicate_or_renumber(): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(OrgSeeder::class);
        $this->seed(OrgSeeder::class);

        // The seeder matches on name now that the code is allocated rather than declared. If
        // that match ever broke, a re-seed would silently double the roster and hand out 24
        // fresh codes.
        $this->assertSame(24, Employee::count());
        $this->assertSame('EMP-0001', Employee::where('last_name', 'Mwakasege')->sole()->employee_code);
    }

    public function test_the_seeded_employees_keep_their_work_location_assignment(): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(WorkLocationSeeder::class);
        $this->seed(OrgSeeder::class);

        // EMP-0004 and EMP-0012 are assigned a location by code after insertion — the one place
        // the seeder still depends on knowing what the generator produced.
        $this->assertNotNull(Employee::where('employee_code', 'EMP-0004')->sole()->work_location_id);
        $this->assertNotNull(Employee::where('employee_code', 'EMP-0012')->sole()->work_location_id);
    }
}
