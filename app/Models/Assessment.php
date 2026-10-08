<?php

namespace App\Models;

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

    // Assessment type constants
    const TYPE_ORAL_READING = 'oral_reading';
    const TYPE_COMPREHENSION = 'comprehension';
    const TYPE_COMBINED = 'combined';

    protected $fillable = [
        'learner_id',
        'material_id',
        'assessor_id',
        'audio_file',
        'transcription',
        'language',
        'assessment_type',
        'status',
        'assessed_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'transcription' => 'array',
            'assessed_at' => 'datetime',
        ];
    }

    // ── Relationships ──

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

    public function effectiveReadingLevel(): ?string
    {
        return $this->verdict?->final_reading_level ?? $this->result?->reading_level;
    }

    public function effectivePrimaryWeakness(): ?int
    {
        $verdict = $this->verdict;

        if ($verdict && $verdict->final_primary_weakness !== null) {
            return $verdict->final_primary_weakness;
        }

        return $this->result?->primary_weakness;
    }

    public function effectiveAccuracy(): ?float
    {
        return $this->verdict?->final_accuracy_rate ?? $this->result?->accuracy_rate;
    }

    public function effectiveWordsPerMinute(): ?float
    {
        return $this->verdict?->final_words_per_minute ?? $this->result?->words_per_minute;
    }

    /** True when the teacher changed any of the AI's conclusions. */
    public function wasEdited(): bool
    {
        return $this->verdict?->isOverridden() ?? false;
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
            'confidence_score' => $analysisData['confidence_score'] ?? 0.50,
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
        $result = $this->result;

        if (!$result || $result->primary_weakness === null || $result->primary_weakness === 0) {
            return collect();
        }

        $interventions = Intervention::where('target_weakness', $result->primary_weakness)
            ->where('grade_level_min', '<=', $this->learner->grade_level)
            ->where('grade_level_max', '>=', $this->learner->grade_level)
            ->where('is_active', true)
            ->orderByDesc('effectiveness_score')
            ->limit(5)
            ->get();

        // Fallback: If no interventions match the exact grade level (e.g. a Grade 6 learner lacking Grade 1 Phonemic Awareness),
        // we provide the most advanced available interventions for that fundamental weakness.
        if ($interventions->isEmpty()) {
            $interventions = Intervention::where('target_weakness', $result->primary_weakness)
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
                'primary_weakness' => $this->result->primary_weakness,
            ] : null,
        ];
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
