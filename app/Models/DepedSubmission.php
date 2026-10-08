<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DepedSubmission extends Model
{
    protected $fillable = [
        'school_year', 'period', 'form_data', 'submitted_on', 'reference', 'notes', 'submitted_by',
    ];

    protected function casts(): array
    {
        return [
            'form_data'    => 'array',
            'submitted_on' => 'date',
        ];
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function periodLabel(): string
    {
        return ClassReport::PERIODS[$this->period] ?? $this->period;
    }
}
