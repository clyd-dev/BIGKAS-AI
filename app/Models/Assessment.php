<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

class Assessment extends Model
{
    use HasFactory;

    // Status constants
    const STATUS_PENDING = 'pending';
    const STATUS_RECORDING = 'recording';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';

    protected $fillable = [
        'learner_id',
        'material_id',
        'assessor_id',
        'audio_file',
        'transcription',
        'language',
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

    public function result(): HasOne
    {
        return $this->hasOne(AssessmentResult::class);
    }

    // ── Helpers ──

    public function getAudioPath(): string
    {
        return storage_path('app/audio/' . $this->audio_file);
    }

    public function getAudioUrl(): string
    {
        return '/storage/audio/' . $this->audio_file;
    }

    public function hasAudio(): bool
    {
        return $this->audio_file && file_exists($this->getAudioPath());
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

    public function markFailed(?string $errorMessage = null): void
    {
        $data = ['status' => self::STATUS_FAILED];
        if ($errorMessage) {
            $data['notes'] = ($this->notes ? $this->notes . "\n" : '') . "Error: " . $errorMessage;
        }
        $this->update($data);
    }

    public function createResult(array $analysisData): AssessmentResult
    {
        $result = $this->result()->create([
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
        ]);

        // Update learner reading level
        $this->learner->update(['reading_level' => $result->reading_level]);
        $this->markCompleted();

        return $result;
    }

    public function getRecommendedInterventions()
    {
        $result = $this->result;

        if (!$result || !$result->primary_weakness) {
            return collect();
        }

        return Intervention::where('target_weakness', $result->primary_weakness)
            ->where('grade_level_min', '<=', $this->learner->grade_level)
            ->where('grade_level_max', '>=', $this->learner->grade_level)
            ->where('is_active', true)
            ->orderByDesc('effectiveness_score')
            ->limit(5)
            ->get();
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
    public function scopeForUser($query, int $userId)
    {
        return $query->whereHas('learner.users', fn($q) => $q->where('users.id', $userId));
    }
}
