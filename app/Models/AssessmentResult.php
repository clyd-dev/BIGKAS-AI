<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssessmentResult extends Model
{
    use HasFactory;

    protected $fillable = [
        'assessment_id',
        'accuracy_rate',
        'words_per_minute',
        'error_count',
        'substitutions',
        'omissions',
        'insertions',
        'repetitions',
        'self_corrections',
        'fluency_score',
        'prosody_score',
        'comprehension_score',
        'reading_level',
        'primary_weakness',
        'secondary_weakness',
        'confidence_score',
        'ml_analysis_json',
    ];

    protected function casts(): array
    {
        return [
            'accuracy_rate' => 'float',
            'words_per_minute' => 'float',
            'error_count' => 'integer',
            'substitutions' => 'integer',
            'omissions' => 'integer',
            'insertions' => 'integer',
            'repetitions' => 'integer',
            'self_corrections' => 'integer',
            'fluency_score' => 'float',
            'prosody_score' => 'float',
            'comprehension_score' => 'float',
            'confidence_score' => 'float',
            'primary_weakness' => 'integer',
            'secondary_weakness' => 'integer',
            'ml_analysis_json' => 'array',
        ];
    }

    // ── Relationships ──

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    // ── Attribute aliases (view expects *_count / legacy names) ──
    // Real columns are substitutions/omissions/insertions/self_corrections;
    // word-level detail, duration and skill scores live in ml_analysis_json.

    public function getSubstitutionCountAttribute(): int
    {
        return (int) ($this->attributes['substitutions'] ?? 0);
    }

    public function getOmissionCountAttribute(): int
    {
        return (int) ($this->attributes['omissions'] ?? 0);
    }

    public function getInsertionCountAttribute(): int
    {
        return (int) ($this->attributes['insertions'] ?? 0);
    }

    public function getSelfCorrectionCountAttribute(): int
    {
        return (int) ($this->attributes['self_corrections'] ?? 0);
    }

    public function getDurationSecondsAttribute(): float
    {
        return (float) (($this->ml_analysis_json['duration_seconds'] ?? null) ?? 0);
    }

    public function getWeaknessConfidenceAttribute(): ?float
    {
        return isset($this->attributes['confidence_score'])
            ? (float) $this->attributes['confidence_score']
            : null;
    }

    public function getWordComparisonDataAttribute(): array
    {
        return $this->ml_analysis_json['word_comparison'] ?? [];
    }

    public function getMlClassificationDataAttribute(): array
    {
        $ml = $this->ml_analysis_json ?? [];

        // Prefer stored all_scores (new rows); fall back to rule-based
        // scores derived from saved error patterns so old rows still render.
        if (isset($ml['all_scores']) && is_array($ml['all_scores'])) {
            return ['all_scores' => $ml['all_scores']];
        }

        return ['all_scores' => $ml['skill_scores'] ?? []];
    }

    // ── Helpers ──

    public function getReadingLevelInfo(): array
    {
        $levels = config('bigkas.reading_levels', []);
        return $levels[$this->reading_level] ?? [
            'name' => 'Unknown',
            'color' => '#6c757d',
            'description' => 'Level not determined',
        ];
    }

    public function getPrimaryWeaknessInfo(): ?array
    {
        if ($this->primary_weakness === null) {
            return null;
        }
        $categories = config('bigkas.weakness_categories', []);
        return $categories[$this->primary_weakness] ?? null;
    }

    public function getErrorBreakdown(): array
    {
        return [
            'substitutions' => $this->substitutions,
            'omissions' => $this->omissions,
            'insertions' => $this->insertions,
            'repetitions' => $this->repetitions,
            'self_corrections' => $this->self_corrections,
            'total' => $this->error_count,
        ];
    }

    public function getScoreInterpretation(): string
    {
        $accuracy = $this->accuracy_rate;

        if ($accuracy >= 97) {
            return 'Excellent! The learner can read this material independently.';
        } elseif ($accuracy >= 90) {
            return 'Good progress. The learner can benefit from guided instruction at this level.';
        } elseif ($accuracy >= 80) {
            return 'The material is challenging. Consider providing more support or easier materials.';
        } else {
            return 'The material is too difficult. Recommend using lower-level reading materials.';
        }
    }

    public function getWPMInterpretation(int $gradeLevel): string
    {
        $benchmarks = config('bigkas.wpm_benchmarks', []);
        $benchmark = $benchmarks[$gradeLevel] ?? $benchmarks[1];
        $wpm = $this->words_per_minute;

        if ($wpm >= $benchmark['advanced']) {
            return 'Advanced fluency for this grade level';
        } elseif ($wpm >= $benchmark['target']) {
            return 'On target for this grade level';
        } elseif ($wpm >= $benchmark['min']) {
            return 'Approaching grade level fluency';
        } else {
            return 'Below grade level fluency - needs intervention';
        }
    }

    public function calculateOverallScore(): float
    {
        $accuracyScore = $this->accuracy_rate;
        $fluencyScore = ($this->fluency_score ?? 0) * 10;
        $wpmScore = min(($this->words_per_minute / 200) * 100, 100);

        return round(
            ($accuracyScore * 0.40) +
            ($fluencyScore * 0.30) +
            ($wpmScore * 0.30),
            1
        );
    }

    public function getSkillScores(): array
    {
        $mlAnalysis = $this->ml_analysis_json ?? [];

        return [
            'accuracy' => $this->accuracy_rate,
            'fluency' => ($this->fluency_score ?? 0) * 10,
            'phonemic' => $mlAnalysis['skill_scores']['phonemic'] ?? 50,
            'comprehension' => $mlAnalysis['skill_scores']['comprehension'] ?? 50,
            'prosody' => ($this->prosody_score ?? 5) * 10,
        ];
    }

    public function getComparisonWithPrevious(): ?array
    {
        $assessment = $this->assessment;
        if (!$assessment) return null;

        $previous = self::whereHas('assessment', function ($q) use ($assessment) {
            $q->where('learner_id', $assessment->learner_id)
              ->where('id', '<', $assessment->id);
        })->latest()->first();

        if (!$previous) return null;

        return [
            'accuracy_change' => $this->accuracy_rate - $previous->accuracy_rate,
            'wpm_change' => $this->words_per_minute - $previous->words_per_minute,
            'fluency_change' => ($this->fluency_score ?? 0) - ($previous->fluency_score ?? 0),
            'level_changed' => $this->reading_level !== $previous->reading_level,
            'previous_level' => $previous->reading_level,
        ];
    }
}
