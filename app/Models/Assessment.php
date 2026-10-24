<?php

namespace App\Models;

use App\Services\PhilIriLevels;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;
use App\Notifications\NewAssessmentCompleted;

class Assessment extends Model
{
    use HasFactory;

    // Status constants
    const STATUS_PENDING = 'pending';
    const STATUS_RECORDING = 'recording';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';

    /**
     * How long a run may sit at `processing` before it counts as abandoned.
     *
     * One number, named once: scopeAwaitingRescore() re-claims a stuck run past
     * it, and BatchEntryState::isRunningNow() stops calling such a run "being
     * analysed now" at the same moment - so the lane and the board cannot
     * disagree about whether anything is still working on a recording.
     */
    const STALE_PROCESSING_MINUTES = 15;

    // Assessment type constants
    const TYPE_ORAL_READING = 'oral_reading';
    const TYPE_COMPREHENSION = 'comprehension';
    const TYPE_COMBINED = 'combined';

    protected $fillable = [
        'learner_id',
        'material_id',
        'assessor_id',
        'batch_id',
        'audio_file',
        'transcription',
        'language',
        'assessment_type',
        'status',
        'rescore_attempts',
        'last_rescore_at',
        'assessed_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'transcription' => 'array',
            'assessed_at' => 'datetime',
            'last_rescore_at' => 'datetime',
        ];
    }

    // ── Relationships ──

    public function batch(): BelongsTo
    {
        return $this->belongsTo(AssessmentBatch::class, 'batch_id');
    }

    public function learner(): BelongsTo
    {
        return $this->belongsTo(Learner::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(ReadingMaterial::class, 'material_id');
    }

    public function assessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessor_id');
    }

    public function comprehensionAnswers(): HasMany
    {
        return $this->hasMany(ComprehensionAnswer::class, 'assessment_id');
    }

    public function verdict(): HasOne
    {
        return $this->hasOne(AssessmentVerdict::class, 'assessment_id');
    }

    // ── Effective values: the teacher's verdict outranks the AI ──
    //
    // The model advises and the teacher decides, so anything that reports on a
    // child reads these rather than the raw result columns.

    public function isInvalidated(): bool
    {
        return $this->verdict?->isInvalidated() ?? false;
    }

    public function isReviewed(): bool
    {
        return $this->verdict !== null;
    }

    /**
     * Why this reading was not measured from the child, or null if it was.
     *
     *  - mock       a placeholder transcript was scored, not the child's voice
     *               (stored by an earlier version when no speech engine ran)
     *  - no_speech  nothing but silence or noise was recorded
     *
     * A teacher's own count (manual scoring) is a measurement, so it clears
     * this. Everything that reports on a child reads it: a reading that was
     * never measured must not show numbers, be accepted, or define a level.
     */
    public function notMeasuredReason(): ?string
    {
        $result = $this->result;

        if (! $result) {
            return null;
        }

        if ($this->verdict?->manual_scoring) {
            return null;
        }

        $ml = $result->ml_analysis_json ?? [];
        $provenance = $ml['provenance'] ?? [];

        if (($provenance['stt_engine'] ?? null) === 'mock' || ($provenance['stt_is_mock'] ?? false)) {
            return 'mock';
        }

        if (($ml['audio_quality']['rating'] ?? null) === 'no_speech') {
            return 'no_speech';
        }

        return null;
    }

    public function isMeasured(): bool
    {
        return $this->notMeasuredReason() === null;
    }

    public function effectiveReadingLevel(): ?string
    {
        return $this->verdict?->final_reading_level
            ?? ($this->isMeasured() ? $this->result?->reading_level : null);
    }

    /**
     * The weakness label as it stands, or null when there is none to stand on.
     * Reads weaknessOutcome(), so it is null for an unclassified or invalidated
     * result and never a guess.
     */
    public function effectivePrimaryWeakness(): ?int
    {
        return $this->weaknessOutcome()['id'];
    }

    /**
     * What this assessment can honestly say about the learner's weakness.
     *
     * Every page and service reads this instead of the raw column, so a result
     * the model could not classify is described the same way everywhere:
     *
     *  - teacher_set   the teacher's verdict names the weakness
     *  - classified    the model answered and the measurements back it
     *  - unclassified  there is no label; `reason` says why (unavailable,
     *                  model_unsupported, invalidated, not_analyzed)
     *  - legacy        saved before the outcome was tracked. It keeps its label
     *                  and says what is known of its source, with a warning
     *                  where that source was a rule or was never recorded.
     *
     * @return array{state: string, id: ?int, name: ?string, usable: bool, reason: ?string, source: ?string, warning: ?string}
     */
    public function weaknessOutcome(): array
    {
        $labels = config('bigkas.weakness_categories', []);

        $outcome = function (string $state, ?int $id, array $extra = []) use ($labels): array {
            return array_merge([
                'state' => $state,
                'id' => $id,
                'name' => $id === null ? null : ($labels[$id]['name'] ?? null),
                'usable' => $id !== null && $state !== 'unclassified',
                'reason' => null,
                'source' => null,
                'warning' => null,
            ], $extra);
        };

        $result = $this->result;

        if (! $result) {
            return $outcome('unclassified', null, ['reason' => 'not_analyzed']);
        }

        // An invalidated reading is not evidence of anything about the child.
        if ($this->isInvalidated()) {
            return $outcome('unclassified', null, ['reason' => 'invalidated']);
        }

        // Nor is one that was never measured from the child's voice.
        if (! $this->isMeasured()) {
            return $outcome('unclassified', null, ['reason' => 'not_measured']);
        }

        $teacherSet = $this->verdict?->final_primary_weakness;

        if ($teacherSet !== null) {
            return $outcome('teacher_set', (int) $teacherSet, ['source' => 'teacher']);
        }

        switch ($result->classification_status) {
            case AssessmentResult::STATUS_CLASSIFIED:
                return $outcome('classified', $result->primary_weakness, ['source' => 'model']);

            case AssessmentResult::STATUS_MODEL_UNSUPPORTED:
            case AssessmentResult::STATUS_UNAVAILABLE:
                return $outcome('unclassified', null, ['reason' => $result->classification_status]);
        }

        // No status: saved before outcomes were tracked. The label stays, with
        // what is known of where it came from.
        $classifier = $result->ml_analysis_json['provenance']['classifier'] ?? null;

        [$source, $warning] = match ($classifier) {
            'ml' => ['model', null],
            'rule_based' => ['rule', 'This label was chosen by a fixed rule, not the model.'],
            default => ['unrecorded', 'It was not recorded whether the model or a rule chose this label.'],
        };

        return $outcome('legacy', $result->primary_weakness, ['source' => $source, 'warning' => $warning]);
    }

    public function effectiveAccuracy(): ?float
    {
        return $this->verdict?->final_accuracy_rate
            ?? ($this->isMeasured() ? $this->result?->accuracy_rate : null);
    }

    public function effectiveWordsPerMinute(): ?float
    {
        return $this->verdict?->final_words_per_minute
            ?? ($this->isMeasured() ? $this->result?->words_per_minute : null);
    }

    /** True when the teacher changed any of the AI's conclusions. */
    public function wasEdited(): bool
    {
        return $this->verdict?->isOverridden() ?? false;
    }

    /**
     * The Phil-IRI levels this assessment actually measured, each labelled by
     * what produced it.
     *
     * Word reading and comprehension are scored on different scales and answer
     * different questions, so a bare "Reading Level" is only honest when both
     * were measured. Whichever halves exist are returned; `overall` appears
     * only when both do, and is the lower of the two - a child who decodes
     * well but understands nothing is not reading the passage.
     *
     * @return array{word: ?array, comprehension: ?array, overall: ?array}
     */
    public function readingLevels(): array
    {
        $word = $this->wordReadingLevel();
        $comprehension = $this->comprehensionLevel();
        $overall = $this->overallReadingLevel($word, $comprehension);

        // When word reading is the only measure, the level the teacher set in
        // their verdict IS this assessment's level - there is no overall tile
        // for it to land on, and the learner's record reads it from here.
        // With both halves present the verdict applies to the combined level,
        // and the two halves stay as the evidence behind it.
        if ($overall === null && $word && $this->verdict?->final_reading_level) {
            $word['level'] = $this->verdict->final_reading_level;
            $word['from_teacher'] = true;
        }

        return [
            'word' => $word,
            'comprehension' => $comprehension,
            'overall' => $overall,
        ];
    }

    /** Level from word accuracy, respecting a teacher's re-score. */
    private function wordReadingLevel(): ?array
    {
        $accuracy = $this->effectiveAccuracy();

        if ($accuracy === null || ! $this->result) {
            return null;
        }

        $verdict = $this->verdict;
        $ml = $this->result->ml_analysis_json ?? [];

        $total = (int) ($verdict?->words_read ?: ($ml['total_words'] ?? 0));
        $correct = $total > 0 ? (int) round((float) $accuracy / 100 * $total) : null;

        return [
            'level' => PhilIriLevels::wordReading((float) $accuracy),
            'score' => (float) $accuracy,
            'correct' => $correct,
            'total' => $total ?: null,
            'label' => 'Word Reading Level',
        ];
    }

    /** Level from the comprehension questions, when they were administered. */
    private function comprehensionLevel(): ?array
    {
        $score = $this->comprehensionScore();

        if ($score === null) {
            return null;
        }

        $answers = $this->relationLoaded('comprehensionAnswers')
            ? $this->comprehensionAnswers
            : $this->comprehensionAnswers()->get();

        return [
            'level' => PhilIriLevels::comprehension($score),
            'score' => $score,
            'correct' => $answers->where('is_correct', true)->count(),
            'total' => $answers->count(),
            'label' => 'Comprehension Level',
        ];
    }

    /**
     * One level for the passage as a whole. Only meaningful when both halves
     * were measured; the teacher's verdict outranks the calculation.
     */
    private function overallReadingLevel(?array $word, ?array $comprehension): ?array
    {
        if (! $word || ! $comprehension) {
            return null;
        }

        $rank = ['frustration' => 0, 'instructional' => 1, 'independent' => 2];
        $lower = ($rank[$comprehension['level']] ?? 0) < ($rank[$word['level']] ?? 0)
            ? $comprehension['level']
            : $word['level'];

        $final = $this->verdict?->final_reading_level ?? $lower;

        return [
            'level' => $final,
            'label' => 'Reading Level',
            'from_teacher' => $this->verdict?->final_reading_level !== null,
            'basis' => 'The lower of word reading and comprehension, which is how the passage reads as a whole.',
        ];
    }

    /**
     * Does this assessment include a comprehension test? True for the
     * comprehension and combined types when the material actually has questions.
     */
    public function needsComprehensionTest(): bool
    {
        if (!in_array($this->assessment_type, [self::TYPE_COMPREHENSION, self::TYPE_COMBINED], true)) {
            return false;
        }

        return $this->material?->comprehensionQuestions()->exists() ?? false;
    }

    public function hasComprehensionAnswers(): bool
    {
        return $this->comprehensionAnswers()->exists();
    }

    /**
     * Percentage of comprehension questions answered correctly, or null when
     * the test hasn't been administered.
     */
    public function comprehensionScore(): ?float
    {
        $total = $this->comprehensionAnswers()->count();

        if ($total === 0) {
            return null;
        }

        $correct = $this->comprehensionAnswers()->where('is_correct', true)->count();

        return round(($correct / $total) * 100, 2);
    }

    public function result(): HasOne
    {
        return $this->hasOne(AssessmentResult::class);
    }

    // ── Helpers ──

    public function getAudioPath(): string
    {
        return Storage::disk('public')->path($this->audio_file);
    }

    public function getAudioUrl(): string
    {
        return $this->audio_file ? Storage::url($this->audio_file) : '';
    }

    public function hasAudio(): bool
    {
        return $this->audio_file && file_exists($this->getAudioPath());
    }

    /**
     * Why there is (or isn't) a recording to play:
     *   available — the file is there
     *   not_saved — no recording was ever kept for this assessment
     *   missing   — one was saved, but the file is gone from the server
     */
    public function audioStatus(): string
    {
        if (!$this->audio_file) {
            return 'not_saved';
        }

        return file_exists($this->getAudioPath()) ? 'available' : 'missing';
    }

    public function getTranscribedText(): string
    {
        $transcription = $this->transcription ?? [];

        if (isset($transcription['text'])) {
            return $transcription['text'];
        }

        if (isset($transcription['words'])) {
            return implode(' ', array_column($transcription['words'], 'word'));
        }

        return '';
    }

    public function updateStatus(string $status): void
    {
        $this->update(['status' => $status]);
    }

    public function markCompleted(): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'assessed_at' => now(),
        ]);
    }

    /**
     * A saved recording that was never scored.
     *
     * The teacher can analyse it again without bringing the learner back to
     * read the passage a second time, so the recording page keeps offering
     * Analyze in this state instead of becoming a dead end.
     */
    public function awaitingAnalysis(): bool
    {
        return $this->audio_file !== null
            && in_array($this->status, [self::STATUS_PROCESSING, self::STATUS_FAILED], true)
            && ! $this->result()->exists();
    }

    /** As above, but specifically after an attempt that failed. */
    public function analysisFailed(): bool
    {
        return $this->status === self::STATUS_FAILED
            && $this->audio_file !== null
            && ! $this->result()->exists();
    }

    public function markFailed(?string $errorMessage = null): void
    {
        $data = ['status' => self::STATUS_FAILED];
        if ($errorMessage) {
            $data['notes'] = ($this->notes ? $this->notes . "\n" : '') . "Error: " . $errorMessage;
        }
        $this->update($data);
    }

    /**
     * Store the analysis for this assessment.
     *
     * assessment_results.assessment_id is unique, and re-analysing is normal:
     * a slow run, a reloaded page or a plain retry all land here again. So the
     * newer numbers replace the old row rather than attempting a second insert
     * that the database would reject.
     */
    public function createResult(array $analysisData): AssessmentResult
    {
        $result = $this->result()->firstOrNew();
        $isFirstAnalysis = ! $result->exists;

        $result->fill([
            'accuracy_rate' => $analysisData['accuracy_rate'] ?? 0,
            'words_per_minute' => $analysisData['words_per_minute'] ?? 0,
            'error_count' => $analysisData['error_count'] ?? 0,
            'substitutions' => $analysisData['substitutions'] ?? 0,
            'omissions' => $analysisData['omissions'] ?? 0,
            'insertions' => $analysisData['insertions'] ?? 0,
            'repetitions' => $analysisData['repetitions'] ?? 0,
            'self_corrections' => $analysisData['self_corrections'] ?? 0,
            'fluency_score' => $analysisData['fluency_score'] ?? 0,
            'prosody_score' => $analysisData['prosody_score'] ?? null,
            'comprehension_score' => $analysisData['comprehension_score'] ?? null,
            'reading_level' => $analysisData['reading_level'] ?? 'frustration',
            'primary_weakness' => $analysisData['primary_weakness'] ?? null,
            'secondary_weakness' => $analysisData['secondary_weakness'] ?? null,
            // No default: a confidence nobody measured is worse than none.
            'confidence_score' => $analysisData['confidence_score'] ?? null,
            'classification_status' => $analysisData['classification_status'] ?? null,
            'ml_analysis_json' => $analysisData,
        ])->save();

        // Recompute from the learner's assessments so a teacher verdict or an
        // invalidated result is respected rather than blindly trusting this one.
        $this->markCompleted();
        $this->refresh()->learner?->updateReadingLevel();

        // Notify linked parent(s) — only for the first analysis, so a re-run
        // doesn't tell parents the same assessment finished all over again.
        if ($isFirstAnalysis) {
            $parents = $this->learner->users()->wherePivot('relationship', 'parent')->get();
            foreach ($parents as $parent) {
                $parent->notify(new NewAssessmentCompleted($this));
            }
        }

        return $result;
    }

    public function getRecommendedInterventions()
    {
        // From the outcome, not the raw column: an unclassified or invalidated
        // result recommends nothing, and a teacher's own verdict is honoured.
        $weakness = $this->weaknessOutcome()['id'];

        if ($weakness === null || $weakness === 0) {
            return collect();
        }

        $interventions = Intervention::where('target_weakness', $weakness)
            ->where('grade_level_min', '<=', $this->learner->grade_level)
            ->where('grade_level_max', '>=', $this->learner->grade_level)
            ->where('is_active', true)
            ->orderByDesc('effectiveness_score')
            ->limit(5)
            ->get();

        // Fallback: If no interventions match the exact grade level (e.g. a Grade 6 learner lacking Grade 1 Phonemic Awareness),
        // we provide the most advanced available interventions for that fundamental weakness.
        if ($interventions->isEmpty()) {
            $interventions = Intervention::where('target_weakness', $weakness)
                ->where('is_active', true)
                ->orderByDesc('grade_level_max')
                ->orderByDesc('effectiveness_score')
                ->limit(5)
                ->get();
        }

        return $interventions;
    }

    public function getSummary(): array
    {
        return [
            'id' => $this->id,
            'learner_name' => $this->learner?->getFullName() ?? 'Unknown',
            'material_title' => $this->material?->title ?? 'Unknown',
            'language' => $this->language,
            'status' => $this->status,
            'assessed_at' => $this->assessed_at,
            'has_audio' => $this->hasAudio(),
            'result' => $this->result ? [
                'accuracy_rate' => $this->result->accuracy_rate,
                'words_per_minute' => $this->result->words_per_minute,
                'reading_level' => $this->result->reading_level,
                'primary_weakness' => $this->weaknessOutcome()['id'],
                'weakness_state' => $this->weaknessOutcome()['state'],
                'classification_status' => $this->result->classification_status,
            ] : null,
        ];
    }

    /**
     * Readings that could not be scored and are waiting for the engine.
     *
     *  - a saved recording with no result (analysis failed, or was left
     *    "processing" for longer than STALE_PROCESSING_MINUTES - a run that
     *    finished would have stored a result), or
     *  - a result the model could not classify, which has no teacher verdict
     *    over it yet (a teacher's decision is never overwritten).
     *
     * Attempts and timing are the caller's concern; this is only "what is
     * owed a score".
     */
    public function scopeAwaitingRescore($query)
    {
        return $query->whereNotNull('audio_file')->where(function ($q) {
            $q->where(function ($unscored) {
                $unscored->doesntHave('result')->where(function ($s) {
                    $s->where('status', self::STATUS_FAILED)
                        ->orWhere(fn ($stuck) => $stuck->where('status', self::STATUS_PROCESSING)
                            ->where('updated_at', '<', now()->subMinutes(self::STALE_PROCESSING_MINUTES)))
                        // A batch entry the teacher has sent is owed a score at
                        // once; an unsent one is only a stored recording.
                        ->orWhere(fn ($sent) => $sent->whereNotNull('batch_id')
                            ->whereNotNull('submitted_for_analysis_at'));
                });
            })->orWhere(function ($unclassified) {
                $unclassified->whereHas('result', fn ($r) => $r->where('classification_status', AssessmentResult::STATUS_UNAVAILABLE))
                    ->doesntHave('verdict');
            });
        });
    }

    /**
     * Scope: assessments for learners belonging to a user.
     */
    public function scopeForUser($query, \App\Models\User $user)
    {
        $learnerIds = $user->accessibleLearnersQuery()->select('learners.id');
        return $query->whereIn('learner_id', $learnerIds);
    }
}
