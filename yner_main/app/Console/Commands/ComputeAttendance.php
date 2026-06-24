<?php

namespace App\Console\Commands;

use App\Services\AttendanceCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class ComputeAttendance extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'attendance:compute
                            {date? : Single date (Y-m-d) to compute; defaults to yesterday}
                            {--from= : Start of an inclusive date range (Y-m-d)}
                            {--to= : End of an inclusive date range (Y-m-d)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Compute daily attendance records from raw biometric punches';

    /**
     * Execute the console command.
     */
    public function handle(AttendanceCalculator $calculator): int
    {
        if ($this->option('from') || $this->option('to')) {
            $from = Carbon::parse($this->option('from') ?? $this->option('to'));
            $to = Carbon::parse($this->option('to') ?? $this->option('from'));

            if ($from->greaterThan($to)) {
                $this->error('--from must be on or before --to.');

                return self::FAILURE;
            }

            $result = $calculator->computeForRange($from, $to);
            $this->info("Computed {$result['computed']} record(s), skipped {$result['skipped']} manual record(s) from {$from->toDateString()} to {$to->toDateString()}.");

            return self::SUCCESS;
        }

        $date = $this->argument('date')
            ? Carbon::parse($this->argument('date'))
            : Carbon::yesterday();

        $result = $calculator->computeForDate($date);
        $this->info("Computed {$result['computed']} record(s), skipped {$result['skipped']} manual record(s) for {$date->toDateString()}.");

        return self::SUCCESS;
    }
}
