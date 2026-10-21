<?php

namespace App\Services;

use App\Models\Assessment;
use App\Models\AssessmentBatch;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

/**
 * An honest "about N minutes" for a teacher's batch, from how long scoring
 * really takes (Assessment::analysis_duration_ms, stamped by AssessmentScorer).
 *
 * The lane is serial and oldest-id-first (RescoreQueue::run), so a batch waits
 * for everything due ahead of it, then for its own entries, then for the next
 * scheduler tick to pick the work up. Nothing here is stored; it is derived
 * from the database each time it is asked.
 */
class AnalysisQueueEstimator
{
    /** Typical seconds per recording until there is data to measure. */
    public const SEED_SECONDS = 150.0;

    /** How many of the most recent timed runs the typical figure is taken from. */
    public const WINDOW = 20;

    /** Tick used when the schedule cannot be read (the web process does not load it). */
    private const FALLBACK_TICK_SECONDS = 60;

    public function __construct(private RescoreQueue $queue)
    {
    }

    /** Median of the last WINDOW timed runs, so one slow outlier does not move it. */
    public function typicalSeconds(): float
    {
        $ms = Assessment::whereNotNull('analysis_duration_ms')
            ->orderByDesc('analysis_started_at')
            ->limit(self::WINDOW)
            ->pluck('analysis_duration_ms')
            ->sort()
            ->values();

        if ($ms->isEmpty()) {
            return self::SEED_SECONDS;
        }

        $mid = intdiv($ms->count(), 2);
        $median = $ms->count() % 2 ? $ms[$mid] : ($ms[$mid - 1] + $ms[$mid]) / 2;

        return $median / 1000;
    }

    /**
     * @return array{remaining: int, ahead: int, seconds: int}
     *   remaining: this batch's entries still owed a score (including the one being scored now);
     *   ahead: other work owed a score before this batch's oldest due entry;
     *   seconds: estimated wait until this batch is done (0 when nothing is owed).
     */
    public function forBatch(AssessmentBatch $batch): array
    {
        // waiting(), not due(): rescore() stamps last_rescore_at before scoring,
        // so due() drops the entry in flight and the wait would read as zero.
        $mine = $this->queue->waiting()->where('batch_id', $batch->id);

        $remaining = (clone $mine)->count();
        $oldest = (clone $mine)->min('id');

        if ($remaining === 0 || $oldest === null) {
            return ['remaining' => 0, 'ahead' => 0, 'seconds' => 0];
        }

        $ahead = $this->queue->waiting()
            ->where('id', '<', $oldest)
            ->where(fn ($q) => $q->whereNull('batch_id')->orWhere('batch_id', '!=', $batch->id))
            ->count();

        return [
            'remaining' => $remaining,
            'ahead' => $ahead,
            'seconds' => (int) ceil(($ahead + $remaining) * $this->typicalSeconds() + $this->tickSeconds()),
        ];
    }

    /**
     * Seconds between runs of assessments:rescore, read from the schedule so
     * changing the schedule changes the estimate. Handles "every minute" and
     * "every N minutes"; anything else, or an unloaded schedule, falls back.
     */
    public function tickSeconds(): int
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $e) => str_contains((string) $e->command, 'assessments:rescore'));

        $minutes = explode(' ', (string) $event?->expression)[0] ?? '';

        if ($minutes === '*') {
            return 60;
        }

        if (preg_match('#^\*/(\d+)$#', $minutes, $m) && (int) $m[1] > 0) {
            return (int) $m[1] * 60;
        }

        return self::FALLBACK_TICK_SECONDS;
    }
}
