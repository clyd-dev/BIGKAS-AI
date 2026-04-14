<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PracticeSession extends Model
{
    use HasFactory;

    public $timestamps = false;

    // Session types
    const TYPE_PHONEMIC = 'phonemic';
    const TYPE_SIGHT_WORDS = 'sight_words';
    const TYPE_GUIDED_READING = 'guided_reading';
    const TYPE_COMPREHENSION = 'comprehension';

    protected $fillable = [
        'learner_id',
        'material_id',
        'session_type',
        'score',
        'time_spent',
        'details',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'float',
            'time_spent' => 'integer',
            'details' => 'array',
            'completed_at' => 'datetime',
            'created_at' => 'datetime',
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

    // ── Helpers ──

    public function getTypeName(): string
    {
        return match ($this->session_type) {
            'phonemic' => 'Phonemic Awareness',
            'sight_words' => 'Sight Words',
            'guided_reading' => 'Guided Reading',
            'comprehension' => 'Reading Comprehension',
            default => ucfirst($this->session_type),
        };
    }

    public function getTimeSpentFormatted(): string
    {
        $seconds = $this->time_spent;

        if ($seconds < 60) {
            return $seconds . 's';
        }

        $minutes = floor($seconds / 60);
        $remaining = $seconds % 60;

        if ($minutes < 60) {
            return $minutes . 'm ' . $remaining . 's';
        }

        $hours = floor($minutes / 60);
        $minutes = $minutes % 60;

        return $hours . 'h ' . $minutes . 'm';
    }

    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }

    public function markCompleted(?float $score = null): void
    {
        $data = ['completed_at' => now()];
        if ($score !== null) {
            $data['score'] = $score;
        }
        $this->update($data);
    }

    public static function getAverageScore(int $learnerId, ?string $type = null): float
    {
        $query = self::where('learner_id', $learnerId)->whereNotNull('score');

        if ($type) {
            $query->where('session_type', $type);
        }

        return round((float) $query->avg('score'), 1);
    }
}
