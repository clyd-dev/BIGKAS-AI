<?php

namespace App\Console\Commands;

use App\Services\ServiceHealthMonitor;
use Illuminate\Console\Command;

/** Probe the analysis engine, record any change and send any alert that is due. Scheduled every minute. */
class CheckServiceHealth extends Command
{
    protected $signature = 'health:check';

    protected $description = 'Check the speech engine and model, record changes, and alert the admin when they stay down';

    public function handle(ServiceHealthMonitor $monitor): int
    {
        $event = $monitor->check();

        $this->line("{$event->level}: {$event->message}");

        return self::SUCCESS;
    }
}
