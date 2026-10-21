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
 *   analysis_started_at set, analysis_duration_ms null   -> transcribing
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
     * @return array{chip: string, label: string, tone: string, playable: bool, action: ?string, result_url: ?string}
     */
    public static function for(Assessment $entry): array
    {
        $playable = $entry->hasAudio();
        $hasResult = $entry->result !== null;
        $resultUrl = $hasResult ? route('assessments.results', $entry) : null;

        $state = function (string $chip, string $label, string $tone, ?string $action = null) use ($playable, $resultUrl) {
            return [
                'chip' => $chip,
                'label' => $label,
                'tone' => $tone,
                'playable' => $playable,
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

        if ($entry->analysis_started_at !== null && $entry->analysis_duration_ms === null) {
            return $state('transcribing', 'Listening to the recording now.', 'ok');
        }

        if (Assessment::awaitingRescore()->whereKey($entry->getKey())->exists()) {
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
