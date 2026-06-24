<?php

namespace Tests\Feature;

use App\Jobs\ComputeAttendanceForDate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class BiometricIngestComputeTest extends TestCase
{
    use RefreshDatabase;

    public function test_ingest_dispatches_compute_job_per_distinct_date(): void
    {
        Bus::fake();

        $this->withHeaders(['X-Internal-Secret' => 'testing-secret'])
            ->postJson('/api/biometric/ingest', ['logs' => [
                ['device_user_id' => 'U001', 'punched_at' => '2026-06-22T08:00:00Z', 'device_serial' => 'STUB-001'],
                ['device_user_id' => 'U001', 'punched_at' => '2026-06-22T17:00:00Z', 'device_serial' => 'STUB-001'],
                ['device_user_id' => 'U002', 'punched_at' => '2026-06-23T08:00:00Z', 'device_serial' => 'STUB-001'],
            ]])
            ->assertOk();

        // Two distinct dates → exactly two jobs.
        Bus::assertDispatchedTimes(ComputeAttendanceForDate::class, 2);
        Bus::assertDispatched(ComputeAttendanceForDate::class, fn (ComputeAttendanceForDate $job) => $job->date === '2026-06-22');
        Bus::assertDispatched(ComputeAttendanceForDate::class, fn (ComputeAttendanceForDate $job) => $job->date === '2026-06-23');
    }
}
