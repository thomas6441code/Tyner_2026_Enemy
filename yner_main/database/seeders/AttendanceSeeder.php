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
     * Seed a stub device, enrollments, and a current week of raw punches, then run the
     * calculator so /attendance shows real data and the full pipeline is exercised.
     */
    public function run(): void
    {
        $device = BiometricDevice::firstOrCreate(
            ['serial' => 'STUB-001'],
            ['name' => 'Main Entrance (Stub)', 'type' => 'stub', 'status' => 'active'],
        );

        $employees = Employee::orderBy('id')->get();

        if ($employees->isEmpty()) {
            return;
        }

        // Enroll every employee on the stub device, keyed by their employee_code.
        foreach ($employees as $employee) {
            DeviceEnrollment::firstOrCreate(
                ['biometric_device_id' => $device->id, 'device_user_id' => $employee->employee_code],
                ['employee_id' => $employee->id],
            );
        }

        $weekStart = Carbon::now()->startOfWeek(Carbon::MONDAY);

        // Five working days; the index in $employees decides each person's daily pattern so
        // the grid shows every pill type (on-time, late, short day, and an absentee).
        foreach (range(0, 4) as $dayOffset) {
            $day = $weekStart->copy()->addDays($dayOffset);

            foreach ($employees as $index => $employee) {
                // The last employee is absent on Wednesday to demonstrate an Absent cell.
                if ($index === $employees->count() - 1 && $dayOffset === 2) {
                    continue;
                }

                // Every third employee arrives late; the second arrives a touch early.
                $inHour = $index % 3 === 0 ? 8 : 7;
                $inMinute = $index % 3 === 0 ? 35 : 50;
                $outHour = $index % 4 === 0 ? 16 : 17; // some leave early → partial/early-leave

                $this->punch($device->serial, $employee->employee_code, $day->copy()->setTime($inHour, $inMinute));
                $this->punch($device->serial, $employee->employee_code, $day->copy()->setTime($outHour, 5));
            }
        }

        app(AttendanceCalculator::class)->computeForRange($weekStart, $weekStart->copy()->addDays(4));
    }

    private function punch(string $serial, string $deviceUserId, Carbon $at): void
    {
        RawAttendanceLog::firstOrCreate([
            'device_serial' => $serial,
            'device_user_id' => $deviceUserId,
            'punched_at' => $at,
        ]);
    }
}
