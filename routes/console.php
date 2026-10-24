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
// 20 recordings a run clears a full 15-child batch in one go; the lock must
// outlast the worst case (20 x 3 min x 1.5 margin = 90 min), so 120.
// The cost of that lock: it releases on normal exit, but a SIGKILL (OOM,
// reboot, `systemctl stop` mid-run) leaves it held for up to two hours, and
// until it expires NOTHING is rescored - single-child outage recovery
// included. Symptom: recordings stuck at `queued` with a healthy engine and
// no "Rescored assessment" lines in the log. Unstick: `schedule:clear-cache`.
Schedule::command('assessments:rescore --limit=20')->everyMinute()->withoutOverlapping(120);
