<?php

namespace Tests\Feature;

use App\Models\RawAttendanceLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BiometricIngestTest extends TestCase
{
    use RefreshDatabase;

    private function validLog(array $overrides = []): array
    {
        return array_merge([
            'device_user_id' => 'U001',
            'punched_at' => '2026-01-01T08:00:00Z',
            'device_serial' => 'STUB-001',
        ], $overrides);
    }

    public function test_valid_secret_stores_logs(): void
    {
        $response = $this->withHeaders(['X-Internal-Secret' => 'testing-secret'])
            ->postJson('/api/biometric/ingest', ['logs' => [$this->validLog()]]);

        $response->assertOk()->assertJson(['status' => 'ok', 'stored' => 1, 'duplicates' => 0]);
        $this->assertDatabaseHas('raw_attendance_logs', ['device_user_id' => 'U001']);
    }

    public function test_missing_secret_is_forbidden(): void
    {
        $this->postJson('/api/biometric/ingest', ['logs' => [$this->validLog()]])->assertForbidden();
    }

    public function test_wrong_secret_is_forbidden(): void
    {
        $this->withHeaders(['X-Internal-Secret' => 'wrong-secret'])
            ->postJson('/api/biometric/ingest', ['logs' => [$this->validLog()]])
            ->assertForbidden();
    }

    public function test_duplicate_log_is_deduped(): void
    {
        $log = $this->validLog();

        $this->withHeaders(['X-Internal-Secret' => 'testing-secret'])
            ->postJson('/api/biometric/ingest', ['logs' => [$log]])
            ->assertJson(['stored' => 1, 'duplicates' => 0]);

        $this->withHeaders(['X-Internal-Secret' => 'testing-secret'])
            ->postJson('/api/biometric/ingest', ['logs' => [$log]])
            ->assertJson(['stored' => 0, 'duplicates' => 1]);

        $this->assertSame(1, RawAttendanceLog::count());
    }

    public function test_malformed_payload_is_rejected(): void
    {
        $this->withHeaders(['X-Internal-Secret' => 'testing-secret'])
            ->postJson('/api/biometric/ingest', ['logs' => [['device_user_id' => 'U001']]])
            ->assertStatus(422);
    }
}
