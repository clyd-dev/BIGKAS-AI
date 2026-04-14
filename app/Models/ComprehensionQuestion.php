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

    public function isCorrect(string $answer): bool
    {
        return strtolower(trim($answer)) === strtolower(trim($this->correct_answer));
    }
}
