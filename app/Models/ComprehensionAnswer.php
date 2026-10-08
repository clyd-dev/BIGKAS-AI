<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComprehensionAnswer extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'assessment_id',
        'question_id',
        'question_text',
        'selected_option',
        'selected_text',
        'correct_text',
        'is_correct',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'is_correct' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(ComprehensionQuestion::class, 'question_id');
    }
}
