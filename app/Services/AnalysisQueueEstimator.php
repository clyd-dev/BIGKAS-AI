<?php

namespace App\Services;

use App\Models\Assessment;
use App\Models\AssessmentBatch;
use Carbon\Carbon;
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

    /**
     * Memoised for the life of this instance - one request.
     *
     * The median of the last twenty runs cannot change while a single page is
     * being rendered, and the board asks for it twice (once for the banner's
     * estimate, once for every card's own wait). Nothing here is cached across
     * requests: a remembered figure would be the same stale answer the board
     * exists to avoid.
     */
    private ?float $typical = null;

    /** Median of the last WINDOW timed runs, so one slow outlier does not move it. */
    public function typicalSeconds(): float
    {
        return $this->typical ??= $this->measureTypicalSeconds();
    }

    private function measureTypicalSeconds(): float
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
     * Where these particular recordings really stand in the one serial lane,
     * and whether anything is driving that lane at all.
     *
     * Two queries for the whole board, never one per card (R39): the lane's
     * waiting set is read once and the places are counted in PHP from it.
     *
     * The place is the place in the WHOLE lane, not within one batch. The lane
     * is shared - another teacher's one-off reading sits in the same queue, and
     * RescoreQueue::run() works strictly oldest id first - so counting only a
     * batch's own entries would print "next up" over a reading that is actually
     * fourth. A number a teacher waits on has to be the real one.
     *
     * Counted from waiting() rather than due(), for the reason given on
     * forBatch(): rescore() stamps last_rescore_at before scoring, so due()
     * drops the recording being scored right now and every place behind it
     * would read one too low. An entry inside its retry cooldown is counted as
     * ahead though it is not yet pickable, which overstates by one slot - the
     * safe direction for a wait.
     *
     * @param  iterable<Assessment>  $entries
     * @return array{positions: array<int,int>, typical: float, tick: int, stalled_minutes: ?int}
     */
    public function lane($entries): array
    {
        $keys = collect($entries)->map(fn ($e) => (int) $e->getKey())->filter()->values()->all();

        $lane = $keys === []
            ? collect()
            : $this->queue->waiting()->orderBy('id')->get(['id', 'submitted_for_analysis_at', 'updated_at']);

        $positions = [];
        $place = 0;

        foreach ($lane as $row) {
            $place++;

            if (in_array((int) $row->id, $keys, true)) {
                $positions[(int) $row->id] = $place;
            }
        }

        return [
            'positions' => $positions,
            'typical' => $this->typicalSeconds(),
            'tick' => $this->tickSeconds(),
            // Only ever reported for a board that has work of its own in the
            // lane: a batch with nothing waiting is not being kept waiting.
            'stalled_minutes' => $positions === [] ? null : $this->stalledMinutes($lane),
        ];
    }

    /**
     * How long nothing at all has happened in the lane, when that is longer
     * than it could honestly take - otherwise null.
     *
     * Why this exists: with no `schedule:run` driving the scheduler (no crontab
     * line, or a killed run still holding withoutOverlapping(120)) nothing is
     * ever picked up, and every card sits at `queued` for ever with no error
     * anywhere. The board said "in line" indefinitely and a teacher reasonably
     * concluded the feature was broken. This is the one fact that distinguishes
     * a slow lane from a dead one.
     *
     * The evidence is deliberately global, not per batch. A batch legitimately
     * waits behind another teacher's reading, and while that reading is being
     * scored the lane stamps analysis_started_at and then last_rescore_at - so
     * the newest of those two timestamps across every assessment is the lane's
     * pulse. If it is recent the lane is alive, whoever's work it is busy with,
     * which is what keeps a merely-busy lane from raising this.
     *
     * The threshold is made of numbers that already exist, not a new one:
     * one scheduler tick (tickSeconds(), read from the real cron expression)
     * plus the longest a single run can plausibly still be alive - the lane's
     * own staleness rule (Assessment::STALE_PROCESSING_MINUTES), or twice the
     * measured typical duration when runs are genuinely slower than that.
     *
     * When the lane has never scored anything at all - a brand-new install,
     * which is exactly the local case that produced this - there is no pulse to
     * measure from, so the clock runs from the moment the oldest waiting
     * recording was handed over instead.
     */
    private function stalledMinutes($lane): ?int
    {
        $pulse = Assessment::selectRaw('max(analysis_started_at) as started, max(last_rescore_at) as rescored')->first();

        // The moment the lane first owed any of this work. Taken as a floor on
        // the measurement, so a batch sent thirty seconds ago never accuses the
        // server of being asleep just because the last run was yesterday - the
        // lane cannot be behind on work it has only just been given.
        $arrived = $lane->map(fn ($row) => $row->submitted_for_analysis_at ?? $row->updated_at)->filter()->min();

        $since = collect([$pulse?->started, $pulse?->rescored, $arrived])
            ->filter()
            ->map(fn ($value) => Carbon::parse($value)->getTimestamp())
            ->max();

        if ($since === null) {
            return null;
        }

        $quiet = (int) abs(Carbon::createFromTimestamp($since)->diffInSeconds(now()));
        $threshold = $this->tickSeconds() + (int) max(Assessment::STALE_PROCESSING_MINUTES * 60, 2 * $this->typicalSeconds());

        return $quiet >= $threshold ? max(1, (int) round($quiet / 60)) : null;
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
