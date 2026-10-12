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

    // ── Tracking: how long this intervention has been sitting ──
    //
    // The dashboard needs to tell a six-week-old untouched assignment apart
    // from yesterday's, so every "how long" question is answered here.

    /** Days since the intervention was assigned. */
    public function daysSinceAssigned(): int
    {
        return (int) $this->created_at?->startOfDay()->diffInDays(now()->startOfDay());
    }

    /** Days since the teacher marked it started, or null if it never was. */
    public function daysSinceStarted(): ?int
    {
        if (! $this->started_at) {
            return null;
        }

        return (int) $this->started_at->startOfDay()->diffInDays(now()->startOfDay());
    }

    /** Still pending well past the point where it should have begun. */
    public function isStalled(): bool
    {
        return $this->status === self::STATUS_PENDING
            && $this->daysSinceAssigned() >= config('bigkas.intervention_stalled_days', 14);
    }

    /** True while the work is open, i.e. not yet completed or skipped. */
    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_IN_PROGRESS], true);
    }

    /**
     * One plain-language line about where this intervention stands, written for
     * a teacher glancing at a dashboard rather than reading a table.
     */
    public function trackingSummary(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => $this->describeDays($this->daysSinceAssigned(), 'Assigned', 'not started yet'),
            self::STATUS_IN_PROGRESS => $this->describeDays($this->daysSinceStarted() ?? 0, 'Started', 'in progress'),
            self::STATUS_COMPLETED => 'Completed' . ($this->completed_at ? ' on ' . $this->completed_at->format('M d, Y') : ''),
            self::STATUS_SKIPPED => 'Skipped' . ($this->notes ? ' — ' . $this->notes : ''),
            default => $this->getStatusDisplay(),
        };
    }

    private function describeDays(int $days, string $verb, string $tail): string
    {
        $when = match (true) {
            $days <= 0 => 'today',
            $days === 1 => 'yesterday',
            default => "{$days} days ago",
        };

        return "{$verb} {$when}, {$tail}";
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
