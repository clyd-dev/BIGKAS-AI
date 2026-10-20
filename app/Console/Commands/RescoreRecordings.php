<?php

namespace App\Console\Commands;

use App\Services\RescoreQueue;
use Illuminate\Console\Command;

/**
 * Score again the recordings saved while the analysis engine was down.
 * Scheduled every five minutes; safe to run by hand at any time.
 */
class RescoreRecordings extends Command
{
    protected $signature = 'assessments:rescore {--limit=5 : Most recordings to score in one run}';

    protected $description = 'Score recordings that were saved while the speech engine or model was down';

    public function handle(RescoreQueue $queue): int
    {
        $result = $queue->run(max(1, (int) $this->option('limit')));

        if ($result['skipped']) {
            $this->warn($result['skipped']);

            return self::SUCCESS;
        }

        $this->info("Scored {$result['scored']}, still waiting {$result['still_waiting']}, failed {$result['failed']}.");

        return self::SUCCESS;
    }
}
