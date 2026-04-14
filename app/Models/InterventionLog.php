<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InterventionLog extends Model
{
    use HasFactory;

    // Status constants
    const STATUS_PENDING = 'pending';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_COMPLETED = 'completed';
    const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'assessment_result_id',
        'intervention_id',
        'learner_id',
        'assigned_by',
        'status',
        'started_at',
        'completed_at',
        'effectiveness_rating',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'effectiveness_rating' => 'integer',
        ];
    }

    // ── Relationships ──

    public function intervention(): BelongsTo
    {
        return $this->belongsTo(Intervention::class);
    }

    public function learner(): BelongsTo
    {
        return $this->belongsTo(Learner::class);
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function assessmentResult(): BelongsTo
    {
        return $this->belongsTo(AssessmentResult::class);
    }

    // ── Helpers ──

    public function getStatusDisplay(): string
    {
        return match ($this->status) {
            'pending' => 'Pending',
            'in_progress' => 'In Progress',
            'completed' => 'Completed',
            'skipped' => 'Skipped',
            default => ucfirst($this->status),
        };
    }

    public function getStatusColor(): string
    {
        return match ($this->status) {
            'pending' => 'secondary',
            'in_progress' => 'primary',
            'completed' => 'success',
            'skipped' => 'warning',
            default => 'secondary',
        };
    }

    public function start(): void
    {
        $this->update([
            'status' => self::STATUS_IN_PROGRESS,
            'started_at' => now(),
        ]);
    }

    public function complete(?int $effectivenessRating = null, ?string $notes = null): void
    {
        $data = [
            'status' => self::STATUS_COMPLETED,
            'completed_at' => now(),
        ];

        if ($effectivenessRating !== null) {
            $data['effectiveness_rating'] = min(10, max(1, $effectivenessRating));
        }

        if ($notes !== null) {
            $data['notes'] = $notes;
        }

        $this->update($data);

        // Update intervention effectiveness score
        $this->intervention?->updateEffectivenessScore();
    }

    public function skip(?string $reason = null): void
    {
        $data = ['status' => self::STATUS_SKIPPED];
        if ($reason) {
            $data['notes'] = $reason;
        }
        $this->update($data);
    }

    /**
     * Scope: logs for a specific learner with optional status filter.
     */
    public function scopeForLearner($query, int $learnerId, ?string $status = null)
    {
        $query->where('learner_id', $learnerId);
        if ($status) {
            $query->where('status', $status);
        }
        return $query;
    }
}
