<?php

namespace App\Services;

use App\Models\Assessment;
use App\Models\AssessmentResult;

/**
 * Score a saved recording: transcribe it, measure it, classify it, store it.
 *
 * One pipeline for the Analyze button, "Re-analyze" and the scheduled
 * re-score of recordings that were saved while the engine was down
 * (assessments:rescore). It needs no logged-in user, so it can run from the
 * scheduler.
 *
 * It throws when nothing could be measured (SpeechServiceUnavailable when no
 * speech engine answered) and the caller decides what to tell whom. It never
 * stands in for a missing engine.
 */
class AssessmentScorer
{
    public function __construct(
        private SpeechToTextService $stt,
        private ReadingAnalyzerService $analyzer,
        private MLClassificationService $ml,
    ) {
    }

    public function score(Assessment $assessment, ?string $referenceText = null, ?float $comprehensionScore = null): AssessmentResult
    {
        $assessment->updateStatus(Assessment::STATUS_PROCESSING);

        // Started and not yet timed is "being transcribed right now"; clearing
        // the duration keeps a re-run from looking finished while it works.
        $startedAt = hrtime(true);
        $assessment->forceFill(['analysis_started_at' => now(), 'analysis_duration_ms' => null])->save();

        // Always the saved copy, so what was played back is what was analysed.
        $transcription = $this->stt->transcribe($assessment->getAudioPath(), $assessment->language ?? 'en');

        $assessment->update(['transcription' => $transcription]);

        $analysis = $this->analyzer->analyze(
            $transcription,
            $referenceText ?? $assessment->material?->content ?? '',
            $transcription['duration'] ?? 60,
            $assessment->language
        );

        $classification = $this->ml->classify($analysis['ml_features']);

        // The model names the weakness, or nothing does. resolve() checks its
        // answer against the measured skill rows and keeps what it actually
        // said; if it could not answer, no label is stored and the result
        // says so. There is no rule standing in for it.
        $comprehension = $comprehensionScore ?? $assessment->comprehensionScore();

        $analysis = array_merge($analysis, MLClassificationService::resolve(
            $classification,
            WeaknessEvidence::statuses($analysis, $assessment->learner?->grade_level, $comprehension)
        ));
        $analysis['secondary_weakness'] = null;

        // Real comprehension score from the question set, when one was taken.
        // The ML feature vector is deliberately left untouched.
        if ($comprehensionScore !== null) {
            $analysis['comprehension_score'] = $comprehensionScore;
        }

        // Record which engines actually produced this result, so the teacher
        // can see whether to trust it (see AnalysisAdvisorService).
        $analysis['provenance'] = $this->provenance($transcription, $classification);

        $result = $assessment->createResult($analysis);

        // At least 1 ms, so a timed run is never mistaken for an untimed one.
        $assessment->forceFill([
            'analysis_duration_ms' => max(1, (int) round((hrtime(true) - $startedAt) / 1_000_000)),
        ])->save();

        return $result;
    }

    /**
     * Provenance of one analysis run: which speech engine and which classifier
     * produced it, and how confident the transcription was.
     */
    private function provenance(array $transcription, array $classification): array
    {
        $confidences = array_filter(
            array_column($transcription['words'] ?? [], 'confidence'),
            fn ($v) => $v !== null
        );

        // The classifier records itself. This used to read ml-service's metadata
        // file straight off disk, which stamped a model version onto rule-based
        // results - and kept doing so after the model files were deleted.
        return array_merge([
            'stt_engine' => $transcription['engine'] ?? 'unknown',
            'stt_is_mock' => (bool) ($transcription['is_mock'] ?? false),
            'mean_word_confidence' => $confidences ? round(array_sum($confidences) / count($confidences), 3) : null,
            'analysed_at' => now()->toIso8601String(),
        ], MLClassificationService::classifierProvenance($classification));
    }
}
