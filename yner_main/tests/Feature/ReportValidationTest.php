<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Input-validation coverage for the report date filters (Phase 11 security check).
 *
 * ReportController::filters() has guard logic — inverted-range swap, a 366-day span cap, and
 * `exists` validation on department_id — that ReportTest does not exercise. This locks it so a
 * hostile or fat-fingered query string can neither error nor pull an unbounded range.
 */
class ReportValidationTest extends TestCase
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

    public function test_inverted_range_is_swapped(): void
    {
        $this->actingAs($this->admin())
            ->get('/reports?from=2026-06-30&to=2026-06-01')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.from', '2026-06-01')
                ->where('filters.to', '2026-06-30')
            );
    }

    public function test_span_is_capped_at_366_days(): void
    {
        $this->actingAs($this->admin())
            ->get('/reports?from=2026-01-01&to=2027-12-31')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.from', '2026-01-01')
                ->where('filters.to', '2027-01-02') // from + 366 days (2026 is not a leap year)
            );
    }

    public function test_nonexistent_department_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->get('/reports?department_id=999999')
            ->assertSessionHasErrors('department_id');
    }

    public function test_malformed_date_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->get('/reports?from=not-a-date')
            ->assertSessionHasErrors('from');
    }
}
