<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProgressSnapshot extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'learner_id',
        'snapshot_date',
        'overall_score',
        'phonemic_awareness_score',
        'decoding_score',
        'fluency_score',
        'comprehension_score',
        'reading_level',
        'words_per_minute',
        'assessments_count',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'snapshot_date' => 'date',
            'overall_score' => 'float',
            'phonemic_awareness_score' => 'float',
            'decoding_score' => 'float',
            'fluency_score' => 'float',
            'comprehension_score' => 'float',
            'words_per_minute' => 'float',
            'assessments_count' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function learner(): BelongsTo
    {
        return $this->belongsTo(Learner::class);
    }
}
