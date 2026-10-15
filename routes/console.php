<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Phase 14: delete expired guest clubs. Needs the scheduler (`schedule:work` locally, cron in production).
Schedule::command('pickleq:purge-guest-clubs')->everyFiveMinutes()->withoutOverlapping();
