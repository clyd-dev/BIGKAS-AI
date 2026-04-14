<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Learner extends Model
{
    use HasFactory;

    // Reading level constants
    const LEVEL_FRUSTRATION = 'frustration';
    const LEVEL_INSTRUCTIONAL = 'instructional';
    const LEVEL_INDEPENDENT = 'independent';

    protected $fillable = [
        'lrn',
        'first_name',
        'last_name',
        'middle_name',
        'birth_date',
        'gender',
        'class_id',
        'school_id',
        'grade_level',
        'reading_level',
        'mother_tongue',
        'notes',
        'avatar',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'grade_level' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    // ── Relationships ──

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'learner_user')
            ->withPivot('relationship')
            ->withTimestamps();
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(Assessment::class);
    }

    public function interventionLogs(): HasMany
    {
        return $this->hasMany(InterventionLog::class);
    }

    public function practiceSessions(): HasMany
    {
        return $this->hasMany(PracticeSession::class);
    }

    public function progressSnapshots(): HasMany
    {
        return $this->hasMany(ProgressSnapshot::class);
    }

    // ── Scopes ──

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // ── Helpers ──

    public function getFullName(): string
    {
        $middle = $this->middle_name ? ' ' . substr($this->middle_name, 0, 1) . '.' : '';
        return $this->first_name . $middle . ' ' . $this->last_name;
    }

    public function getAge(): int
    {
        return $this->birth_date ? $this->birth_date->age : 0;
    }

    public function getGradeLevelName(): string
    {
        $levels = config('bigkas.grade_levels', []);
        return $levels[$this->grade_level] ?? 'Unknown';
    }

    public function getReadingLevelInfo(): array
    {
        $levels = config('bigkas.reading_levels', []);
        return $levels[$this->reading_level] ?? [
            'name' => 'Not Assessed',
            'color' => '#6c757d',
            'description' => 'No assessment data available',
        ];
    }

    public function getLatestAssessment(): ?Assessment
    {
        return $this->assessments()->with('result')->latest()->first();
    }

    public function getAssessmentResults()
    {
        return AssessmentResult::whereHas('assessment', fn($q) => $q->where('learner_id', $this->id))
            ->with('assessment.material')
            ->latest()
            ->get();
    }

    public function getSkillBreakdown(): array
    {
        $latestResult = AssessmentResult::whereHas('assessment', fn($q) => $q->where('learner_id', $this->id))
            ->latest()
            ->first();

        if (!$latestResult) {
            return [
                'phonemic_awareness' => null,
                'decoding' => null,
                'fluency' => null,
                'comprehension' => null,
            ];
        }

        $mlAnalysis = $latestResult->ml_analysis_json ?? [];

        return [
            'phonemic_awareness' => $mlAnalysis['skill_scores']['phonemic'] ?? null,
            'decoding' => $latestResult->accuracy_rate ?? null,
            'fluency' => $latestResult->fluency_score ?? null,
            'comprehension' => $mlAnalysis['skill_scores']['comprehension'] ?? null,
        ];
    }

    public function getProgressData()
    {
        return AssessmentResult::select('assessment_results.*')
            ->join('assessments', 'assessments.id', '=', 'assessment_results.assessment_id')
            ->where('assessments.learner_id', $this->id)
            ->orderBy('assessments.created_at')
            ->get();
    }

    public function updateReadingLevel(): void
    {
        $latest = $this->getLatestAssessment();
        if ($latest && $latest->result) {
            $this->update(['reading_level' => $latest->result->reading_level]);
        }
    }

    public function getStats(): array
    {
        $avgAccuracy = AssessmentResult::whereHas('assessment', fn($q) => $q->where('learner_id', $this->id))
            ->avg('accuracy_rate');

        return [
            'total_assessments' => $this->assessments()->count(),
            'average_accuracy' => round((float) $avgAccuracy, 1),
            'completed_interventions' => $this->interventionLogs()->where('status', 'completed')->count(),
            'practice_sessions' => $this->practiceSessions()->count(),
        ];
    }
}
