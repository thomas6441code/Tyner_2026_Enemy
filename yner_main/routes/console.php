<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Recompute the previous day's attendance once the day has closed.
Schedule::command('attendance:compute')->dailyAt('01:00');

// Score attendance for anomalies + absenteeism risk once the recompute has settled.
Schedule::command('ai:score-attendance')->dailyAt('02:00');

// Remind employees to sign in if they have no attendance record yet.
Schedule::command('attendance:remind-sign-in')->dailyAt('08:15');
