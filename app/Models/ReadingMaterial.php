<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReadingMaterial extends Model
{
    use HasFactory;

    // Language constants
    const LANG_ENGLISH = 'en';
    const LANG_FILIPINO = 'fil';
    // Difficulty levels
    const DIFFICULTY_EASY = 'easy';
    const DIFFICULTY_MEDIUM = 'medium';
    const DIFFICULTY_HARD = 'hard';

    // Categories
    const CATEGORY_NARRATIVE = 'narrative';
    const CATEGORY_EXPOSITORY = 'expository';
    const CATEGORY_POETRY = 'poetry';
    const CATEGORY_DIALOGUE = 'dialogue';

    protected $fillable = [
        'title',
        'content',
        'language',
        'grade_level',
        'difficulty',
        'word_count',
        'category',
        'source',
        'audio_guide',
        'image',
        'genre',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'grade_level' => 'integer',
            'word_count' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    // ── Relationships ──

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function comprehensionQuestions(): HasMany
    {
        return $this->hasMany(ComprehensionQuestion::class, 'material_id')->orderBy('sort_order');
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(Assessment::class, 'material_id');
    }

    public function practiceSessions(): HasMany
    {
        return $this->hasMany(PracticeSession::class, 'material_id');
    }

    // ── Scopes ──

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForGrade($query, int $gradeLevel)
    {
        return $query->where('grade_level', $gradeLevel);
    }

    public function scopeFilter($query, array $filters)
    {
        if (isset($filters['language'])) {
            $query->where('language', $filters['language']);
        }
        if (isset($filters['grade_level'])) {
            $query->where('grade_level', $filters['grade_level']);
        }
        if (isset($filters['difficulty'])) {
            $query->where('difficulty', $filters['difficulty']);
        }
        if (isset($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        return $query->active()->orderBy('grade_level')->orderBy('title');
    }

    // ── Helpers ──

    public function getLanguageName(): string
    {
        return match ($this->language) {
            'en' => 'English',
            'fil' => 'Filipino',
            default => 'Unknown',
        };
    }

    public function getGradeLevelName(): string
    {
        $levels = config('bigkas.grade_levels', []);
        return $levels[$this->grade_level] ?? 'Unknown';
    }

    public function getDifficultyName(): string
    {
        return ucfirst($this->difficulty ?? 'medium');
    }

    public function getCategoryName(): string
    {
        return ucfirst($this->category ?? 'narrative');
    }

    public function getWords(): array
    {
        return preg_split('/\s+/', trim($this->content), -1, PREG_SPLIT_NO_EMPTY);
    }

    public function calculateWordCount(): int
    {
        return count($this->getWords());
    }

    public function getSentences(): array
    {
        return preg_split('/[.!?]+/', trim($this->content), -1, PREG_SPLIT_NO_EMPTY);
    }

    public function getExcerpt(int $words = 30): string
    {
        $wordArray = $this->getWords();
        $excerpt = implode(' ', array_slice($wordArray, 0, $words));

        if (count($wordArray) > $words) {
            $excerpt .= '...';
        }

        return $excerpt;
    }

    public function getEstimatedReadingTime(int $wpm = 100): int
    {
        return (int) ceil(($this->word_count / $wpm) * 60);
    }

    public function hasAudioGuide(): bool
    {
        return $this->audio_guide && file_exists(storage_path('app/audio/guides/' . $this->audio_guide));
    }

    public function getAudioGuideUrl(): ?string
    {
        return $this->hasAudioGuide() ? '/storage/audio/guides/' . $this->audio_guide : null;
    }

    public function getUsageStats(): array
    {
        return [
            'times_used' => $this->assessments()->count(),
            'average_accuracy' => round((float) AssessmentResult::whereHas('assessment', fn($q) => $q->where('material_id', $this->id))->avg('accuracy_rate'), 1),
        ];
    }

    public static function getRandom(string $language, int $gradeLevel): ?self
    {
        $material = self::active()
            ->where('language', $language)
            ->where('grade_level', $gradeLevel)
            ->inRandomOrder()
            ->first();

        if (!$material) {
            $material = self::active()
                ->where('language', $language)
                ->where('grade_level', max(1, $gradeLevel - 1))
                ->inRandomOrder()
                ->first();
        }

        return $material;
    }
}
