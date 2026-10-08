<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssessmentVerdict extends Model
{
    const DECISION_ACCEPTED = 'accepted';
    const DECISION_OVERRIDDEN = 'overridden';
    const DECISION_INVALIDATED = 'invalidated';

    protected $fillable = [
        'assessment_id',
        'decision',
        'final_reading_level',
        'final_primary_weakness',
        'manual_scoring',
        'words_read',
        'manual_substitutions',
        'manual_omissions',
        'manual_insertions',
        'manual_self_corrections',
        'final_accuracy_rate',
        'final_words_per_minute',
        'reason',
        'decided_by',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'manual_scoring' => 'boolean',
            'final_primary_weakness' => 'integer',
            'words_read' => 'integer',
            'manual_substitutions' => 'integer',
            'manual_omissions' => 'integer',
            'manual_insertions' => 'integer',
            'manual_self_corrections' => 'integer',
            'final_accuracy_rate' => 'float',
            'final_words_per_minute' => 'float',
            'decided_at' => 'datetime',
        ];
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isAccepted(): bool
    {
        return $this->decision === self::DECISION_ACCEPTED;
    }

    public function isOverridden(): bool
    {
        return $this->decision === self::DECISION_OVERRIDDEN;
    }

    public function isInvalidated(): bool
    {
        return $this->decision === self::DECISION_INVALIDATED;
    }

    /** Total miscues entered during manual scoring (self-corrections aren't miscues). */
    public function miscueCount(): int
    {
        return (int) $this->manual_substitutions
            + (int) $this->manual_omissions
            + (int) $this->manual_insertions;
    }

    public function decisionLabel(): string
    {
        return match ($this->decision) {
            self::DECISION_ACCEPTED => 'Accepted AI result',
            self::DECISION_OVERRIDDEN => 'Overridden by teacher',
            self::DECISION_INVALIDATED => 'Marked invalid',
            default => ucfirst((string) $this->decision),
        };
    }
}
