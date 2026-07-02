<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Notifications\SystemNotification;
use Illuminate\Console\Command;

class RemindSignIn extends Command
{
    protected $signature = 'attendance:remind-sign-in
                            {--date= : Override the reminder date in Y-m-d format}';

    protected $description = 'Send sign-in reminders to active employees who have not punched in yet';

    public function handle(): int
    {
        $date = $this->option('date') ?: now()->toDateString();
        $employees = Employee::query()
            ->where('status', 'active')
            ->whereNotNull('user_id')
            ->with(['user', 'attendanceRecords' => fn ($query) => $query->whereDate('work_date', $date)])
            ->get()
            ->filter(fn (Employee $employee) => $employee->attendanceRecords->isEmpty() && $employee->user !== null);

        if ($employees->isEmpty()) {
            $this->info('No employees need a sign-in reminder.');

            return self::SUCCESS;
        }

        foreach ($employees as $employee) {
            $employee->user->notify(
                SystemNotification::signInReminder(
                    $employee->fullName(),
                    $date,
                    route('attendance.index'),
                ),
            );
        }

        $this->info('Sent '.$employees->count().' sign-in reminder(s).');

        return self::SUCCESS;
    }
}