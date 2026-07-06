<?php

namespace Database\Seeders;

use App\Models\BiometricDevice;
use App\Models\DeviceEnrollment;
use App\Models\Employee;
use App\Models\RawAttendanceLog;
use App\Services\AttendanceCalculator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class AttendanceSeeder extends Seeder
{
    /**
     * Seed a stub device, enrollments, and ~3 months of realistic raw punches for every
     * employee, then run the calculator over the whole window so `attendance_records` is
     * populated end-to-end. Every dashboard, report, and AI insight reads that table, so this
     * gives the whole app real data instead of empty/static placeholders.
     *
     * Behaviour is deterministic (fixed RNG seed) so re-seeding reproduces the same history.
     * Each employee gets a "profile" (reliable / average / at-risk) that shapes their daily
     * lateness, early-leave, and absence odds — producing genuine variety across the roster:
     * top attendants, a couple of high-risk absentees, and everything in between.
     */
    public function run(): void
    {
        $device = BiometricDevice::firstOrCreate(
            ['serial' => 'STUB-001'],
            ['name' => 'Main Entrance (Stub)', 'type' => 'stub', 'status' => 'active'],
        );

        $employees = Employee::where('status', 'active')->orderBy('id')->get();

        if ($employees->isEmpty()) {
            return;
        }

        foreach ($employees as $employee) {
            DeviceEnrollment::firstOrCreate(
                ['biometric_device_id' => $device->id, 'device_user_id' => $employee->employee_code],
                ['employee_id' => $employee->id],
            );
        }

        // Three months up to today, working weekdays only.
        $start = Carbon::today()->subMonthsNoOverflow(3)->startOfDay();
        $end = Carbon::today()->startOfDay();

        // Idempotency: clear any previously-seeded stub punches in the window before rebuilding.
        RawAttendanceLog::where('device_serial', $device->serial)
            ->whereBetween('punched_at', [$start->copy()->startOfDay(), $end->copy()->endOfDay()])
            ->delete();

        mt_srand(20260706);

        $rows = [];
        $now = now()->toDateTimeString();

        foreach ($employees as $index => $employee) {
            $profile = $this->profileFor($index);

            $cursor = $start->copy();
            while ($cursor->lessThanOrEqualTo($end)) {
                if ($cursor->isWeekend()) {
                    $cursor->addDay();

                    continue;
                }

                // Absent day → no punches at all; the calculator will mark it Absent.
                if ($this->chance($profile['absence'])) {
                    $cursor->addDay();

                    continue;
                }

                $inMinutes = $this->chance($profile['late'])
                    ? mt_rand(20, 75)      // arrives 08:20–09:15 → Late (past the 08:15 grace)
                    : mt_rand(-20, 12);    // arrives 07:40–08:12 → Present within grace

                $outMinutes = $this->chance($profile['earlyLeave'])
                    ? -mt_rand(30, 75)     // leaves 15:45–16:30 → early-leave minutes
                    : mt_rand(0, 20);      // leaves 17:00–17:20

                $in = $cursor->copy()->setTime(8, 0)->addMinutes($inMinutes);
                $out = $cursor->copy()->setTime(17, 0)->addMinutes($outMinutes);

                $rows[] = $this->row($device->serial, $employee->employee_code, $in, $now);
                $rows[] = $this->row($device->serial, $employee->employee_code, $out, $now);

                $cursor->addDay();
            }
        }

        // Bulk insert in chunks to keep the seed fast despite thousands of punches.
        foreach (array_chunk($rows, 500) as $chunk) {
            RawAttendanceLog::insert($chunk);
        }

        // Compute weekdays only — computing weekends would score everyone "Absent" on Sat/Sun
        // and skew the weekday-absence radar and attendance rates the dashboards render.
        $calculator = app(AttendanceCalculator::class);
        $cursor = $start->copy();
        while ($cursor->lessThanOrEqualTo($end)) {
            if (! $cursor->isWeekend()) {
                $calculator->computeForDate($cursor);
            }
            $cursor->addDay();
        }
    }

    /**
     * Daily behaviour odds (0–1) by roster position. A fixed spread guarantees a few standout
     * reliable staff, a middle band, and 2–3 genuinely at-risk employees for the AI signals.
     *
     * @return array{late: float, earlyLeave: float, absence: float}
     */
    private function profileFor(int $index): array
    {
        return match (true) {
            // At-risk: EMP-0008 / EMP-0016 / EMP-0024 — frequent lateness and absences.
            $index % 8 === 7 => ['late' => 0.38, 'earlyLeave' => 0.22, 'absence' => 0.16],
            // Average: noticeable but not alarming.
            $index % 3 === 0 => ['late' => 0.22, 'earlyLeave' => 0.12, 'absence' => 0.06],
            // Reliable: the majority, occasional slip.
            default => ['late' => 0.10, 'earlyLeave' => 0.06, 'absence' => 0.025],
        };
    }

    private function chance(float $probability): bool
    {
        return mt_rand(0, 999) < ($probability * 1000);
    }

    /**
     * @return array<string, string>
     */
    private function row(string $serial, string $deviceUserId, Carbon $at, string $now): array
    {
        return [
            'device_serial' => $serial,
            'device_user_id' => $deviceUserId,
            'punched_at' => $at->toDateTimeString(),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
