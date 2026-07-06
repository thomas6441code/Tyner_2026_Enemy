<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\AiSetting;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AiSettingTest extends TestCase
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

    public function test_admin_can_view_and_update_settings(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/settings/ai')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/ai')
                ->where('provider', 'openrouter')
                ->where('has_api_key', false)
            );

        $this->actingAs($admin)->put('/settings/ai', [
            'provider' => 'openrouter',
            'base_url' => 'https://openrouter.ai/api/v1',
            'model' => 'openai/gpt-4o',
            'api_key' => 'sk-or-secret-value',
        ])->assertRedirect();

        $setting = AiSetting::current();
        $this->assertSame('openai/gpt-4o', $setting->model);
        $this->assertSame('sk-or-secret-value', $setting->api_key);
        $this->assertSame($admin->id, $setting->updated_by);
    }

    public function test_blank_api_key_on_update_keeps_the_existing_key(): void
    {
        $admin = $this->admin();
        AiSetting::current()->update(['api_key' => 'sk-or-existing-value']);

        $this->actingAs($admin)->put('/settings/ai', [
            'provider' => 'openrouter',
            'base_url' => 'https://openrouter.ai/api/v1',
            'model' => 'anthropic/claude-sonnet-4.5',
            'api_key' => '',
        ])->assertRedirect();

        $this->assertSame('sk-or-existing-value', AiSetting::current()->api_key);
    }

    public function test_employee_cannot_view_or_update_settings(): void
    {
        $employee = User::factory()->create();
        $employee->assignRole(RoleName::Employee->value);

        $this->actingAs($employee)->get('/settings/ai')->assertForbidden();

        $this->actingAs($employee)->put('/settings/ai', [
            'provider' => 'openrouter',
            'base_url' => 'https://openrouter.ai/api/v1',
            'model' => 'anthropic/claude-sonnet-4.5',
        ])->assertForbidden();
    }
}
