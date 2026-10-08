<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Assessment;
use App\Models\AssessmentVerdict;
use App\Traits\AuthorizesLearnerAccess;
use Illuminate\Http\Request;

/**
 * Records the teacher's decision on an AI result.
 *
 * BIGKAS advises; the teacher decides. Nothing here rewrites the model's own
 * numbers — the verdict is stored beside them, so the record always shows both
 * what the AI predicted and what the teacher concluded, and who concluded it.
 */
class AssessmentVerdictController extends Controller
{
    use AuthorizesLearnerAccess;

    public function store(Request $request, Assessment $assessment)
    {
        $this->authorizeLearnerAccess($assessment->learner);

        abort_unless($assessment->result, 404, 'This assessment has no result to review yet.');

        $validated = $request->validate([
            'decision' => 'required|in:accepted,overridden,invalidated',

            // Only meaningful for an override; required there so a teacher can't
            // "override" without actually stating a conclusion.
            'final_reading_level' => 'required_if:decision,overridden|nullable|in:frustration,instructional,independent',
            'final_primary_weakness' => 'nullable|integer|min:0|max:4',

            // A changed or discarded result must say why — that note is the
            // audit trail a principal or panel will ask about.
            'reason' => 'required_unless:decision,accepted|nullable|string|max:1000',

            'manual_scoring' => 'nullable|boolean',
            'words_read' => 'required_if:manual_scoring,1|nullable|integer|min:1|max:2000',
            'manual_substitutions' => 'nullable|integer|min:0|max:2000',
            'manual_omissions' => 'nullable|integer|min:0|max:2000',
            'manual_insertions' => 'nullable|integer|min:0|max:2000',
            'manual_self_corrections' => 'nullable|integer|min:0|max:2000',
        ]);

        $decision = $validated['decision'];
        $manualScoring = $decision === AssessmentVerdict::DECISION_OVERRIDDEN
            && $request->boolean('manual_scoring');

        $attributes = [
            'decision' => $decision,
            'reason' => $validated['reason'] ?? null,
            'manual_scoring' => $manualScoring,
            'decided_by' => $request->user()->id,
            'decided_at' => now(),

            // Cleared unless this decision sets them below.
            'final_reading_level' => null,
            'final_primary_weakness' => null,
            'words_read' => null,
            'manual_substitutions' => null,
            'manual_omissions' => null,
            'manual_insertions' => null,
            'manual_self_corrections' => null,
            'final_accuracy_rate' => null,
            'final_words_per_minute' => null,
        ];

        if ($decision === AssessmentVerdict::DECISION_OVERRIDDEN) {
            $attributes['final_reading_level'] = $validated['final_reading_level'];
            $attributes['final_primary_weakness'] = $validated['final_primary_weakness'] ?? null;

            if ($manualScoring) {
                $attributes = array_merge($attributes, $this->manualScores($assessment, $validated));
            }
        }

        $assessment->verdict()->updateOrCreate(
            ['assessment_id' => $assessment->id],
            $attributes
        );

        // An invalidated result must stop counting towards the child's level,
        // and an override must start counting — both are a recompute.
        $assessment->refresh()->learner?->updateReadingLevel();

        ActivityLog::log(
            'assessment_verdict',
            "Recorded '{$decision}' decision on assessment #{$assessment->id}",
            'assessment',
            $assessment->id
        );

        return redirect()->route('assessments.results', $assessment)
            ->with('success', $this->confirmationFor($decision));
    }

    /**
     * Re-score the reading from the teacher's own miscue counts.
     *
     * Phil-IRI counts substitutions, omissions and insertions as miscues;
     * self-corrections are recorded but don't count against accuracy. Words per
     * minute is recomputed from the recorded audio length, which is still
     * trustworthy even when the transcription wasn't.
     */
    private function manualScores(Assessment $assessment, array $validated): array
    {
        $wordsRead = (int) $validated['words_read'];

        $miscues = (int) ($validated['manual_substitutions'] ?? 0)
            + (int) ($validated['manual_omissions'] ?? 0)
            + (int) ($validated['manual_insertions'] ?? 0);

        $accuracy = $wordsRead > 0
            ? round((max(0, $wordsRead - $miscues) / $wordsRead) * 100, 2)
            : null;

        $duration = (float) ($assessment->result->ml_analysis_json['duration_seconds'] ?? 0);
        $wpm = $duration > 0 ? round($wordsRead / ($duration / 60), 2) : null;

        return [
            'words_read' => $wordsRead,
            'manual_substitutions' => (int) ($validated['manual_substitutions'] ?? 0),
            'manual_omissions' => (int) ($validated['manual_omissions'] ?? 0),
            'manual_insertions' => (int) ($validated['manual_insertions'] ?? 0),
            'manual_self_corrections' => (int) ($validated['manual_self_corrections'] ?? 0),
            'final_accuracy_rate' => $accuracy,
            'final_words_per_minute' => $wpm,
        ];
    }

    private function confirmationFor(string $decision): string
    {
        return match ($decision) {
            AssessmentVerdict::DECISION_ACCEPTED => 'Result accepted. It now stands as this learner\'s record.',
            AssessmentVerdict::DECISION_OVERRIDDEN => 'Your decision was saved and now overrides the AI result.',
            AssessmentVerdict::DECISION_INVALIDATED => 'Result marked invalid. It stays on record but no longer sets the learner\'s level.',
            default => 'Decision saved.',
        };
    }
}
