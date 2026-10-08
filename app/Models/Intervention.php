<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Intervention extends Model
{
    use HasFactory;

    // Weakness targets
    const TARGET_PHONEMIC = 1;
    const TARGET_DECODING = 2;
    const TARGET_FLUENCY = 3;
    const TARGET_COMPREHENSION = 4;

    // Activity types
    const TYPE_GAME = 'game';
    const TYPE_DRILL = 'drill';
    const TYPE_READING = 'reading';
    const TYPE_WRITING = 'writing';
    const TYPE_AUDIO = 'audio';
    const TYPE_VISUAL = 'visual';

    protected $fillable = [
        'name',
        'description',
        'target_weakness',
        'activity_type',
        'materials_needed',
        'instructions',
        'for_teacher',
        'for_parent',
        'grade_level_min',
        'grade_level_max',
        'estimated_duration',
        'effectiveness_score',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'target_weakness' => 'integer',
            'for_teacher' => 'boolean',
            'for_parent' => 'boolean',
            'grade_level_min' => 'integer',
            'grade_level_max' => 'integer',
            'estimated_duration' => 'integer',
            'effectiveness_score' => 'float',
            'is_active' => 'boolean',
        ];
    }

    // ── Relationships ──

    public function logs(): HasMany
    {
        return $this->hasMany(InterventionLog::class);
    }

    // ── Scopes ──

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForWeakness($query, int $weakness)
    {
        return $query->where('target_weakness', $weakness);
    }

    public function scopeForGrade($query, int $gradeLevel)
    {
        return $query->where('grade_level_min', '<=', $gradeLevel)
                     ->where('grade_level_max', '>=', $gradeLevel);
    }

    // ── Helpers ──

    public function getWeaknessInfo(): array
    {
        $categories = config('bigkas.weakness_categories', []);
        return $categories[$this->target_weakness] ?? [
            'name' => 'General',
            'code' => 'GENERAL',
            'description' => 'General reading intervention',
        ];
    }

    public function getActivityTypeName(): string
    {
        return match ($this->activity_type) {
            'game' => 'Interactive Game',
            'drill' => 'Practice Drill',
            'reading' => 'Reading Activity',
            'writing' => 'Writing Activity',
            'audio' => 'Audio-Based',
            'visual' => 'Visual Activity',
            default => ucfirst($this->activity_type),
        };
    }

    public function getGradeLevelRange(): string
    {
        if ($this->grade_level_min === $this->grade_level_max) {
            return "Grade {$this->grade_level_min}";
        }
        return "Grades {$this->grade_level_min}-{$this->grade_level_max}";
    }

    public function getDurationDisplay(): string
    {
        if ($this->estimated_duration < 60) {
            return "{$this->estimated_duration} minutes";
        }

        $hours = floor($this->estimated_duration / 60);
        $minutes = $this->estimated_duration % 60;

        if ($minutes === 0) {
            return "{$hours} hour" . ($hours > 1 ? 's' : '');
        }

        return "{$hours}h {$minutes}m";
    }

    public function isSuitableForGrade(int $gradeLevel): bool
    {
        return $gradeLevel >= $this->grade_level_min && $gradeLevel <= $this->grade_level_max;
    }

    public static function getByWeakness(int $weakness, ?int $gradeLevel = null, ?string $userRole = null)
    {
        $query = self::active()->forWeakness($weakness);

        if ($gradeLevel) {
            $query->forGrade($gradeLevel);
        }

        if ($userRole === 'teacher') {
            $query->where('for_teacher', true);
        } elseif ($userRole === 'parent') {
            $query->where('for_parent', true);
        }

        return $query->orderByDesc('effectiveness_score')->get();
    }

    public static function getRecommendations(AssessmentResult $result, Learner $learner, ?string $userRole = null): array
    {
        $recommendations = [];

        if ($result->primary_weakness !== null) {
            // For independent readers (class 0), we might not have specific interventions 
            // but we fetch them if they exist
            $primary = self::getByWeakness($result->primary_weakness, $learner->grade_level, $userRole);
            foreach ($primary->take(3) as $intervention) {
                $recommendations[] = [
                    'intervention' => $intervention,
                    'priority' => 'high',
                    'reason' => 'Addresses primary profile: ' . $intervention->getWeaknessInfo()['name'],
                ];
            }
        }

        if ($result->secondary_weakness !== null && $result->secondary_weakness !== $result->primary_weakness) {
            $secondary = self::getByWeakness($result->secondary_weakness, $learner->grade_level, $userRole);
            foreach ($secondary->take(2) as $intervention) {
                $recommendations[] = [
                    'intervention' => $intervention,
                    'priority' => 'medium',
                    'reason' => 'Addresses secondary weakness: ' . $intervention->getWeaknessInfo()['name'],
                ];
            }
        }

        return $recommendations;
    }

    public function getStats(): array
    {
        // Each call below must get a fresh query builder — `logs()` returns a
        // mutable relation builder, so chaining ->where() on a reused instance
        // permanently narrows it, corrupting any later count() on that same
        // variable (previously caused a 0/0 DivisionByZeroError whenever an
        // intervention had assignments but none yet completed).
        $totalCount = $this->logs()->count();
        $completedCount = $this->logs()->where('status', 'completed')->count();
        $avgEffectiveness = $this->logs()->whereNotNull('effectiveness_rating')->avg('effectiveness_rating');

        return [
            'times_assigned' => $totalCount,
            'completion_rate' => $totalCount > 0
                ? round(($completedCount / $totalCount) * 100, 1)
                : 0,
            'average_effectiveness' => round((float) $avgEffectiveness, 1),
        ];
    }

    public function updateEffectivenessScore(): void
    {
        $stats = $this->getStats();

        if ($stats['times_assigned'] > 0 && $stats['average_effectiveness'] > 0) {
            $this->update([
                'effectiveness_score' => round(
                    ($stats['average_effectiveness'] * 0.7) +
                    (($stats['completion_rate'] / 10) * 0.3),
                    2
                ),
            ]);
        }
    }
}
