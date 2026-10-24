<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssessmentSession extends Model
{
    protected $fillable = [
        'assessment_id',
        'learner_id',
        'teacher_id',
        'material_id',
        'session_code',
        'status',
        'started_at',
        'student_joined_at',
        'reading_started_at',
        'completed_at',
        'elapsed_seconds',
        'teacher_notes',
        'student_progress',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'student_joined_at' => 'datetime',
            'reading_started_at' => 'datetime',
            'completed_at' => 'datetime',
            'teacher_notes' => 'array',
            'student_progress' => 'array',
        ];
    }

    // ── Relationships ──

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    public function learner(): BelongsTo
    {
        return $this->belongsTo(Learner::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(ReadingMaterial::class, 'material_id');
    }

    // ── Scopes ──

    public function scopeActive($query)
    {
        return $query->whereNotIn('status', ['completed', 'cancelled']);
    }

    public function scopeForLearner($query, int $learnerId)
    {
        return $query->where('learner_id', $learnerId);
    }

    public function scopeForTeacher($query, int $teacherId)
    {
        return $query->where('teacher_id', $teacherId);
    }

    // ── Helpers ──

    public static function generateCode(): string
    {
        do {
            $code = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 6));
        } while (self::where('session_code', $code)->exists());

        return $code;
    }

    public function isWaiting(): bool
    {
        return $this->status === 'waiting';
    }

    public function isReading(): bool
    {
        return in_array($this->status, ['reading', 'recording']);
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function markStudentJoined(): void
    {
        $this->update([
            'status' => 'ready',
            'student_joined_at' => now(),
        ]);
    }

    public function startReading(): void
    {
        $this->update([
            'status' => 'reading',
            'reading_started_at' => now(),
        ]);
    }

    public function startRecording(): void
    {
        $this->update(['status' => 'recording']);
    }

    /**
     * Nothing calls this, and that is deliberate (R40).
     *
     * It is the only writer of the `completed` status that scopeActive() and
     * isCompleted() read, so a session reaches none of them: once issued, a
     * session stays active for ever and Learner::getActiveSession() goes on
     * returning it. The student dashboard was fixed by asking the *reading*
     * whether anything is pending (StudentAssessmentController::pendingFor())
     * rather than by calling this from the scoring pipeline - the pipeline is
     * shared with the single-child flow and the scheduled re-score, and reaching
     * into it to repair a display problem would have changed behaviour for both.
     *
     * What would legitimately call it: a session lifecycle owner - the student
     * portal stamping the session when the child finishes reading, or the batch
     * board when an entry becomes terminal. Whoever does it must fix the elapsed
     * figure below first: on Carbon 3 now()->diffInSeconds($past) is **negative**,
     * so the first caller would start storing negative reading times. Kept rather
     * than deleted because `completed` is live vocabulary in two readers here, and
     * deleting the only thing that can write it would leave them unreachable with
     * nothing saying why.
     */
    public function complete(): void
    {
        $elapsed = $this->reading_started_at
            ? now()->diffInSeconds($this->reading_started_at)
            : 0;

        $this->update([
            'status' => 'completed',
            'completed_at' => now(),
            'elapsed_seconds' => $elapsed,
        ]);
    }

    public function cancel(): void
    {
        $this->update([
            'status' => 'cancelled',
            'completed_at' => now(),
        ]);
    }

    public function updateStudentProgress(array $progress): void
    {
        $this->update(['student_progress' => $progress]);
    }
}
