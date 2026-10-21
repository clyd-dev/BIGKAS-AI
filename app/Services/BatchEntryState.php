<?php

namespace App\Services;

use App\Models\Assessment;
use App\Models\AssessmentResult;

/**
 * What one card on the batch board honestly says about one child.
 *
 * The seven words are the teacher's own: waiting, recorded, transcribing,
 * queued, needs verdict, done, failed. Nothing here reads the raw `status`
 * column to decide a chip, because `status` is not an honest answer to
 * "where is this child's reading?":
 *
 *  - RescoreQueue::rescore() calls markFailed() when the speech engine is
 *    down, deliberately leaving the attempt uncounted so an outage does not
 *    burn a recording's three attempts. Such an entry is still claimed by
 *    Assessment::awaitingRescore(), so it is `queued`, waiting for the engine
 *    - never `failed`. Only rescore_attempts >= RescoreQueue::MAX_ATTEMPTS
 *    with no result is `failed`.
 *  - A result the model could not classify has measurements but no weakness
 *    label. awaitingRescore() still claims it, so it reads `queued`, waiting
 *    for the model. There is no rule-based fallback anywhere in this system,
 *    so a reading without a model answer is incomplete, not complete, and it
 *    must never present as `done`.
 *
 * ── Derivation order (first match wins; do not reshuffle) ─────────────
 *
 *   no audio_file                                       -> waiting
 *   no submitted_for_analysis_at, no result              -> recorded
 *   rescore_attempts >= MAX_ATTEMPTS, no result          -> failed
 *   isRunning() (started, not finished)                  -> transcribing
 *   awaitingRescore() claims it                          -> queued
 *   result exists, verdict exists                        -> done
 *   result exists, no verdict                            -> needs verdict
 *
 * `failed` is checked before `queued` so an entry that has used up its
 * attempts does not read as still waiting its turn. `transcribing` is checked
 * before `queued` because an entry being scored right now also satisfies
 * awaitingRescore(); the timing columns are the narrower truth.
 */
class BatchEntryState
{
    /**
     * Every entry's state, keyed by entry id, with the rescore lane asked once
     * instead of once per card.
     *
     * @param  iterable<Assessment>  $entries
     * @return \Illuminate\Support\Collection<int, array>
     */
    public static function forAll($entries)
    {
        $entries = collect($entries);
        $claimed = self::claimedIds($entries);

        return $entries->mapWithKeys(fn (Assessment $entry) => [
            $entry->getKey() => self::for($entry, $claimed),
        ]);
    }

    /**
     * Which of these entries the rescore lane claims, in one query.
     *
     * Asked through Assessment::scopeAwaitingRescore() and nothing else: the
     * scope stays the single definition of "owed a score", because a copy of
     * its conditions in PHP is how the lane and the board would drift apart -
     * and that drift would read as exactly the dishonesty this class exists to
     * prevent.
     *
     * @param  iterable<Assessment>  $entries
     * @return int[]
     */
    public static function claimedIds($entries): array
    {
        $keys = collect($entries)->map(fn (Assessment $entry) => $entry->getKey())->filter()->values()->all();

        if ($keys === []) {
            return [];
        }

        // Never cached across calls: what the lane claims changes as entries are
        // saved, and a remembered answer would be the same stale-state lie this
        // class exists to prevent.
        return Assessment::awaitingRescore()->whereIn('id', $keys)->pluck('id')
            ->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Is a Whisper run under way on this recording?
     *
     * The one definition of "running" in this application. The `transcribing`
     * branch below is this predicate, and so is the controller's write-time
     * refusal on remove and un-send - shared rather than written out twice,
     * because two copies of one state is how the board and the refusal come to
     * disagree about whether a recording can still be taken out of the lane.
     *
     * AssessmentScorer::score() stamps analysis_started_at before it calls the
     * engine and analysis_duration_ms only when the call returns, so the pair
     * says "started and not finished" and nothing else does.
     */
    public static function isRunning(Assessment $entry): bool
    {
        return $entry->analysis_started_at !== null && $entry->analysis_duration_ms === null;
    }

    /**
     * Is that run still plausibly alive?
     *
     * isRunning() alone has no end. RescoreQueue::rescore() calls markFailed()
     * *after* score() has stamped analysis_started_at, so an engine outage or a
     * killed worker leaves an entry reading `transcribing` for ever - and a
     * card that is `transcribing` offers the teacher no door off the board at
     * all. Bounded here by the same number the lane uses to re-claim a stuck
     * run (Assessment::STALE_PROCESSING_MINUTES), read from that constant
     * rather than retyped: past it the lane has given up on the run, so this
     * must stop claiming one is in progress, or the board would be refusing on
     * behalf of a process that no longer exists.
     *
     * The chip stays `transcribing` either way - it was started and never
     * finished, which is the truth - but the card gains a way out.
     */
    public static function isRunningNow(Assessment $entry): bool
    {
        if (! self::isRunning($entry)) {
            return false;
        }

        return $entry->updated_at === null
            || $entry->updated_at->greaterThanOrEqualTo(now()->subMinutes(Assessment::STALE_PROCESSING_MINUTES));
    }

    /**
     * @param  int[]|null  $claimedIds  the pre-fetched claimed set from claimedIds(),
     *                                  or null to ask the lane about this one entry.
     * @return array{chip: string, label: string, tone: string, playable: bool, running: bool, action: ?string, result_url: ?string}
     */
    public static function for(Assessment $entry, ?array $claimedIds = null): array
    {
        $playable = $entry->hasAudio();
        $hasResult = $entry->result !== null;
        $resultUrl = $hasResult ? route('assessments.results', $entry) : null;

        // Whether anything is actually working on this recording right now, as
        // opposed to having started and stopped. The card reads it to decide
        // whether a `transcribing` chip may still offer a door off the board.
        $running = self::isRunningNow($entry);

        $state = function (string $chip, string $label, string $tone, ?string $action = null) use ($playable, $resultUrl, $running) {
            return [
                'chip' => $chip,
                'label' => $label,
                'tone' => $tone,
                'playable' => $playable,
                'running' => $running,
                'action' => $action,
                // Set the moment a result exists - at needs verdict and at done
                // alike - so a reading is only ever read in one place.
                'result_url' => $resultUrl,
            ];
        };

        if (! $entry->audio_file) {
            return $state('waiting', 'No recording yet.', 'none', 'Conduct here');
        }

        if ($entry->submitted_for_analysis_at === null && ! $hasResult) {
            // The Send gate: a comprehension test cannot be scored without its
            // answers, so the card names the blocker and offers no Send until
            // they exist. Offering it anyway would stall the lane silently.
            if ($entry->needsComprehensionTest() && ! $entry->hasComprehensionAnswers()) {
                return $state('recorded', 'Recording saved. The comprehension questions have not been answered yet.', 'ok');
            }

            return $state('recorded', 'Recording saved, not sent yet.', 'ok', 'Send for analysis');
        }

        if ((int) $entry->rescore_attempts >= RescoreQueue::MAX_ATTEMPTS && ! $hasResult) {
            return $state('failed', 'This recording could not be scored after ' . RescoreQueue::MAX_ATTEMPTS . ' tries.', 'help', 'Analyze');
        }

        if (self::isRunning($entry)) {
            return $state('transcribing', 'Listening to the recording now.', 'ok');
        }

        $claimed = $claimedIds === null
            ? Assessment::awaitingRescore()->whereKey($entry->getKey())->exists()
            : in_array($entry->getKey(), $claimedIds, true);

        if ($claimed) {
            // Both the engine-outage case and an unclassified result land here.
            return $hasResult && $entry->result->classification_status === AssessmentResult::STATUS_UNAVAILABLE
                ? $state('queued', 'Measured, waiting for the model to name the weakness.', 'ok')
                : $state('queued', 'In line for analysis.', 'ok');
        }

        if ($hasResult) {
            return $entry->verdict !== null
                ? $state('done', 'Done.', 'good')
                : $state('needs verdict', 'Analysed. It needs your verdict.', 'help');
        }

        // Audio saved and sent, nothing owes it a score and nothing scored it:
        // say so plainly rather than inventing a state for it.
        return $state('failed', 'This recording was not scored.', 'help', 'Analyze');
    }
}
