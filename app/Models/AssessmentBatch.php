<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssessmentBatch extends Model
{
    use HasFactory;

    const STATUS_COLLECTING = 'collecting';
    const STATUS_ANALYZING = 'analyzing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'teacher_id',
        'school_class_id',
        'title',
        'language',
        'assessment_type',
        'default_material_id',
        'status',
        'completed_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    // ── Relationships ──

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'school_class_id');
    }

    public function defaultMaterial(): BelongsTo
    {
        return $this->belongsTo(ReadingMaterial::class, 'default_material_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(Assessment::class, 'batch_id');
    }

    // ── Status ──

    /**
     * Recompute this batch's own status from its entries and store it.
     *
     * This is the only thing that writes assessment_batches.status apart from
     * creation (collecting) and cancellation. It is driven by
     * AssessmentObserver, not by the board's poll: a batch that finishes
     * overnight must be `completed` the moment its last entry is, with nobody
     * watching. A stored status that only updates while someone is looking at
     * it is a rendering artefact, not a stored status.
     *
     * Rules: a cancelled batch never changes; every entry `done` or `failed`
     * means completed, with completed_at; any entry past `waiting` means
     * analyzing. It saves the batch, never an entry, so the observer that
     * calls it cannot re-enter.
     */
    public function refreshStatus(): void
    {
        if ($this->status === self::STATUS_CANCELLED) {
            return;
        }

        $entries = $this->entries()->with(['result', 'verdict', 'material'])->get();

        if ($entries->isEmpty()) {
            return;
        }

        $chips = $entries->map(fn (Assessment $entry) => \App\Services\BatchEntryState::for($entry)['chip']);

        if ($chips->every(fn (string $chip) => in_array($chip, ['done', 'failed'], true))) {
            $this->writeStatus(self::STATUS_COMPLETED, $this->completed_at ?? now());

            return;
        }

        $this->writeStatus(
            $chips->contains(fn (string $chip) => $chip !== 'waiting') ? self::STATUS_ANALYZING : self::STATUS_COLLECTING,
            null
        );
    }

    /** Write only when something actually changed, so nothing churns updated_at. */
    private function writeStatus(string $status, ?\DateTimeInterface $completedAt): void
    {
        if ($this->status === $status && (bool) $this->completed_at === (bool) $completedAt) {
            return;
        }

        $this->forceFill(['status' => $status, 'completed_at' => $completedAt])->save();
    }
}
