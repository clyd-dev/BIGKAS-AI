<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComprehensionQuestion extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'material_id',
        'question',
        'question_type',
        'correct_answer',
        'option_a',
        'option_b',
        'option_c',
        'option_d',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(ReadingMaterial::class, 'material_id');
    }

    public function getOptions(): array
    {
        return array_filter([
            'A' => $this->option_a,
            'B' => $this->option_b,
            'C' => $this->option_c,
            'D' => $this->option_d,
        ]);
    }

    /**
     * Which option letter holds the correct answer. Answers are stored as text,
     * so the letter is derived by matching it back against the options.
     */
    public function getCorrectOptionLetter(): ?string
    {
        foreach ($this->getOptions() as $letter => $text) {
            if ($this->isCorrect((string) $text)) {
                return $letter;
            }
        }

        return null;
    }

    public function getOptionText(string $letter): ?string
    {
        return $this->getOptions()[strtoupper($letter)] ?? null;
    }

    public function isCorrect(string $answer): bool
    {
        return strtolower(trim($answer)) === strtolower(trim($this->correct_answer));
    }
}
