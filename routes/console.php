<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Tell the admin when the engine stops, and score again what was saved meanwhile.
// Needs the cron entry that runs `php artisan schedule:run` every minute.
Schedule::command('health:check')->everyMinute()->withoutOverlapping(5);
Schedule::command('assessments:rescore --limit=20')->everyMinute()->withoutOverlapping(120);
