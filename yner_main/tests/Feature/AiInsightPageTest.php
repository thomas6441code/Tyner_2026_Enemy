<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AiInsightPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_admin_can_view_ai_insights(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RoleName::Admin->value);

        $this->actingAs($admin)->get('/ai-insights')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('ai/insights'));
    }

    public function test_hr_officer_can_view_ai_insights(): void
    {
        $hr = User::factory()->create();
        $hr->assignRole(RoleName::HrOfficer->value);

        $this->actingAs($hr)->get('/ai-insights')->assertOk();
    }

    public function test_employee_cannot_view_ai_insights(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RoleName::Employee->value);

        $this->actingAs($user)->get('/ai-insights')->assertForbidden();
    }
}
