<?php

namespace App\Jobs;

use App\Services\AttendanceCalculator;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ComputeAttendanceForDate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  string  $date  Y-m-d date to recompute.
     */
    public function __construct(public string $date) {}

    /**
     * Recompute attendance for the date this job carries.
     */
    public function handle(AttendanceCalculator $calculator): void
    {
        $calculator->computeForDate(Carbon::parse($this->date));
    }
}
