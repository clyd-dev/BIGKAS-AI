<?php

namespace App\Services;

use App\Exceptions\SpeechServiceUnavailable;
use App\Models\Assessment;
use App\Models\AssessmentResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Scores again the recordings that were saved while the engine was down.
 *
 * The queue is the database: a recording is "owed a score" when it is saved
 * with no result, or its result could not be classified (see
 * Assessment::scopeAwaitingRescore). Nothing is held anywhere else, so nothing
 * can be lost from a queue that was never written.
 *
 * Only what the engine can do right now is attempted, and a failure caused by
 * the engine being unavailable does not use up a recording's attempts - only a
 * recording that fails while the engine is up does, so one bad file is given
 * up on (MAX_ATTEMPTS) instead of retried forever. Given-up recordings stay
 * visible to the admin and the teacher can still press Analyze.
 */
class RescoreQueue
{
    public const MAX_ATTEMPTS = 3;
    public const RETRY_AFTER_MINUTES = 10;

    public function __construct(
        private MLClassificationService $ml,
        private AssessmentScorer $scorer,
    ) {
    }

    /** Owed a score and still worth trying (what the admin sees as waiting). */
    public function waiting(): Builder
    {
        return Assessment::awaitingRescore()->where('rescore_attempts', '<', self::MAX_ATTEMPTS);
    }

    /** Waiting, and not tried within the last RETRY_AFTER_MINUTES: what a run picks up. */
    public function due(): Builder
    {
        return $this->waiting()->where(fn ($q) => $q->whereNull('last_rescore_at')
                ->orWhere('last_rescore_at', '<=', now()->subMinutes(self::RETRY_AFTER_MINUTES)));
    }

    /** Owed a score, but retried too often to try again on its own. */
    public function givenUp(): Builder
    {
        return Assessment::awaitingRescore()->where('rescore_attempts', '>=', self::MAX_ATTEMPTS);
    }

    /**
     * @return array{scored: int, still_waiting: int, failed: int, skipped: string|null}
     */
    public function run(int $limit = 5): array
    {
        $summary = ['scored' => 0, 'still_waiting' => 0, 'failed' => 0, 'skipped' => null];

        $status = $this->ml->serviceStatus(fresh: true);
        $canTranscribe = ($status['stt_ok'] ?? true) !== false;
        $canClassify = (bool) ($status['ok'] ?? false);

        if (! $canTranscribe) {
            $summary['skipped'] = 'The speech engine is down; nothing can be scored yet.';

            return $summary;
        }

        // Oldest first: the reading that has waited longest is scored first.
        $due = $this->due()->with(['result', 'learner', 'material'])->oldest('id')->limit($limit * 4)->get();

        $done = 0;
        foreach ($due as $assessment) {
            if ($done >= $limit) {
                break;
            }

            $unclassifiedOnly = $assessment->result?->classification_status === AssessmentResult::STATUS_UNAVAILABLE;

            // A result that only lacks a classification is worth redoing only
            // once the model can actually give one.
            if ($unclassifiedOnly && ! $canClassify) {
                continue;
            }

            // A comprehension test cannot be scored without its answers.
            if (! $assessment->result && $assessment->needsComprehensionTest() && ! $assessment->hasComprehensionAnswers()) {
                continue;
            }

            if (! $assessment->hasAudio()) {
                continue;
            }

            $done++;
            $this->rescore($assessment, $summary);
        }

        return $summary;
    }

    private function rescore(Assessment $assessment, array &$summary): void
    {
        $assessment->forceFill(['last_rescore_at' => now()])->save();

        try {
            $result = $this->scorer->score($assessment);
        } catch (SpeechServiceUnavailable $e) {
            // The engine, not this recording. Leave the attempt uncounted.
            $assessment->markFailed($e->getMessage());
            $summary['still_waiting']++;

            return;
        } catch (\Throwable $e) {
            $assessment->markFailed($e->getMessage());
            $assessment->increment('rescore_attempts');
            $summary['failed']++;
            Log::error("Rescore of assessment #{$assessment->id} failed: " . $e->getMessage(), ['exception' => $e]);

            return;
        }

        if ($result->classification_status === AssessmentResult::STATUS_UNAVAILABLE) {
            // Scored and measured, but the model dropped out again mid-run.
            $summary['still_waiting']++;

            return;
        }

        $summary['scored']++;
        Log::info("Rescored assessment #{$assessment->id} from its saved recording.");
    }
}
