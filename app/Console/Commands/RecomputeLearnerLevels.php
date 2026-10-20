<?php

namespace App\Console\Commands;

use App\Models\Learner;
use Illuminate\Console\Command;

/**
 * Re-derive every learner's reading level from the readings that count.
 *
 * Needed once after upgrading from a version that scored a placeholder
 * transcript: its flawless "independent" result may have set a child's level,
 * and a level a bad reading set does not correct itself until something else
 * triggers a recompute. Safe to run again; it only moves a level to what the
 * learner's usable readings support.
 */
class RecomputeLearnerLevels extends Command
{
    protected $signature = 'learners:recompute-levels {--dry-run : List what would change without changing it}';

    protected $description = "Recompute learners' reading levels, ignoring readings that were never measured or were marked invalid";

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $changed = 0;

        Learner::query()
            ->whereHas('assessments.result')
            ->orWhereNotNull('reading_level')
            ->orderBy('id')
            ->each(function (Learner $learner) use ($dry, &$changed) {
                $current = $learner->reading_level;
                $should = $learner->readingLevelFromAssessments();

                if ($current === $should) {
                    return;
                }

                $changed++;
                $this->line(sprintf(
                    '  learner #%d: %s -> %s',
                    $learner->id,
                    $current ?? '(none)',
                    $should ?? '(none)'
                ));

                if (! $dry) {
                    $learner->update(['reading_level' => $should]);
                }
            });

        $this->info(($dry ? 'Would change ' : 'Changed ') . $changed . ' learner(s).');

        return self::SUCCESS;
    }
}
