<?php

namespace App\Models;

use App\Support\Directory;

use App\Casts\EncryptedPin;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Hash;

class Learner extends Model
{
    use HasFactory;

    // Reading level constants
    const LEVEL_FRUSTRATION = 'frustration';
    const LEVEL_INSTRUCTIONAL = 'instructional';
    const LEVEL_INDEPENDENT = 'independent';

    protected $fillable = [
        'lrn',
        'first_name',
        'last_name',
        'middle_name',
        'birth_date',
        'gender',
        'class_id',
        'school_id',
        'grade_level',
        'reading_level',
        'mother_tongue',
        'notes',
        'avatar',
        'failed_login_attempts',
        'locked_at',
        'last_activity_date',
        'pin_created_at',
    ];

    /**
     * Sensitive fields — never mass-assignable. Set via explicit
     * attribute assignment only (e.g. $learner->pin = ...; $learner->save()).
     */
    protected $guarded = [
        'pin',
        'is_active',
        'total_xp',
        'current_streak',
        'longest_streak',
    ];

    protected function casts(): array
    {
        return [
            // Personal data is stored encrypted (see the encrypt_learner_and_user_identity migration).
            'first_name' => 'encrypted',
            'last_name' => 'encrypted',
            'middle_name' => 'encrypted',
            'lrn' => 'encrypted',
            'mother_tongue' => 'encrypted',
            'notes' => 'encrypted',
            'birth_date' => \App\Casts\EncryptedDate::class,
            'grade_level' => 'integer',
            'is_active' => 'boolean',
            'locked_at' => 'datetime',
            'current_streak' => 'integer',
            'longest_streak' => 'integer',
            'total_xp' => 'integer',
            'last_activity_date' => 'date',
            'pin_created_at' => 'datetime',
            'pin' => \App\Casts\EncryptedPin::class,
        ];
    }

    protected static function booted(): void
    {
        // Exact-match fingerprint of the (encrypted) LRN, for lookups and the uniqueness check.
        static::saving(function (self $learner) {
            if ($learner->isDirty('lrn') || $learner->lrn_index === null) {
                $learner->lrn_index = \App\Support\BlindIndex::make($learner->lrn, 'lrn');
            }
        });
    }

    public function scopeByLrn($query, ?string $lrn)
    {
        return $query->where('lrn_index', \App\Support\BlindIndex::make($lrn, 'lrn') ?? '-none-');
    }

    // ── Relationships ──

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    /**
     * Staff a parent may send this learner's report to: the teacher of the
     * learner's class, plus the admin (principal).
     */
    public function reportRecipients(): \Illuminate\Support\Collection
    {
        $teachers = $this->class_id
            ? User::where('role', 'teacher')
                ->whereHas('taughtClasses', fn ($q) => $q->where('classes.id', $this->class_id))
                ->get()
            : collect();
        $teachers = Directory::sortUsers($teachers);

        $admins = Directory::sortUsers(User::where('role', 'admin')->get());

        return collect(['teacher' => $teachers, 'admin' => $admins])
            ->filter(fn ($group) => $group->isNotEmpty());
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'learner_user')
            ->withPivot('relationship')
            ->withTimestamps();
    }

    public function gstResults(): HasMany
    {
        return $this->hasMany(GstResult::class);
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(Assessment::class);
    }

    public function interventionLogs(): HasMany
    {
        return $this->hasMany(InterventionLog::class);
    }

    public function practiceSessions(): HasMany
    {
        return $this->hasMany(PracticeSession::class);
    }

    public function progressSnapshots(): HasMany
    {
        return $this->hasMany(ProgressSnapshot::class);
    }

    public function badges(): BelongsToMany
    {
        return $this->belongsToMany(Badge::class, 'learner_badges')
            ->withPivot('earned_at', 'context')
            ->orderByPivot('earned_at', 'desc');
    }

    public function assessmentSessions(): HasMany
    {
        return $this->hasMany(AssessmentSession::class);
    }

    // ── Scopes ──

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // ── Helpers ──

    /**
     * Full name attribute accessor — allows $learner->full_name in views.
     */
    public function getFullNameAttribute(): string
    {
        return $this->getFullName();
    }

    public function getFullName(): string
    {
        $middle = $this->middle_name ? ' ' . substr($this->middle_name, 0, 1) . '.' : '';
        return $this->first_name . $middle . ' ' . $this->last_name;
    }

    public function getAge(): int
    {
        return $this->birth_date ? $this->birth_date->age : 0;
    }

    public function getGradeLevelName(): string
    {
        $levels = config('bigkas.grade_levels', []);
        return $levels[$this->grade_level] ?? 'Unknown';
    }

    public function getReadingLevelInfo(): array
    {
        $levels = config('bigkas.reading_levels', []);
        return $levels[$this->reading_level] ?? [
            'name' => 'Not Assessed',
            'color' => '#6c757d',
            'description' => 'No assessment data available',
        ];
    }

    public function getLatestAssessment(): ?Assessment
    {
        return $this->assessments()->with('result')->latest()->first();
    }

    public function getAssessmentResults()
    {
        return AssessmentResult::whereHas('assessment', fn($q) => $q->where('learner_id', $this->id))
            ->with('assessment.material')
            ->latest()
            ->get();
    }

    public function getSkillBreakdown(): array
    {
        $latestResult = AssessmentResult::whereHas('assessment', fn($q) => $q->where('learner_id', $this->id))
            ->latest()
            ->first();

        if (!$latestResult) {
            return [
                'phonemic_awareness' => null,
                'decoding' => null,
                'fluency' => null,
                'comprehension' => null,
            ];
        }

        $mlAnalysis = $latestResult->ml_analysis_json ?? [];

        return [
            'phonemic_awareness' => $mlAnalysis['skill_scores']['phonemic'] ?? null,
            'decoding' => $latestResult->accuracy_rate ?? null,
            'fluency' => $latestResult->fluency_score ?? null,
            'comprehension' => $mlAnalysis['skill_scores']['comprehension'] ?? null,
        ];
    }

    public function getProgressData()
    {
        return AssessmentResult::select('assessment_results.*')
            ->join('assessments', 'assessments.id', '=', 'assessment_results.assessment_id')
            ->where('assessments.learner_id', $this->id)
            ->orderBy('assessments.created_at')
            ->get();
    }

    /**
     * Set the learner's reading level from their most recent usable assessment.
     *
     * Results a teacher marked invalid are skipped (they stay on record, they
     * just don't define the child's level), and where a teacher overrode the
     * AI's judgement, the teacher's level wins.
     */
    public function updateReadingLevel(): void
    {
        $latest = $this->assessments()
            ->whereHas('result')
            ->with(['result', 'verdict'])
            // Tie-break on id: two assessments can share a created_at second,
            // which would otherwise make "most recent" non-deterministic.
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->first(fn (Assessment $assessment) => !$assessment->isInvalidated());

        $level = $latest?->effectiveReadingLevel();

        if ($level) {
            $this->update(['reading_level' => $level]);
        }
    }

    public function getStats(): array
    {
        $avgAccuracy = AssessmentResult::whereHas('assessment', fn($q) => $q->where('learner_id', $this->id))
            ->avg('accuracy_rate');

        return [
            'total_assessments' => $this->assessments()->count(),
            'average_accuracy' => round((float) $avgAccuracy, 1),
            'completed_interventions' => $this->interventionLogs()->where('status', 'completed')->count(),
            'practice_sessions' => $this->practiceSessions()->count(),
        ];
    }

    // ── Student Portal / Gamification ──

    /** A new 6-digit PIN that no other learner currently has. */
    public static function generatePin(): string
    {
        $commonPins = ['000000', '123456', '111111', '222222', '654321', '987654'];
        $holders = self::whereNotNull('pin')->get(['id', 'pin']);

        do {
            $pin = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        } while (in_array($pin, $commonPins, true) || $holders->contains(fn (self $l) => $l->checkPin($pin)));

        return $pin;
    }

    public function checkPin(string $pin): bool
    {
        $stored = $this->attributes['pin'] ?? null;
        if ($stored === null || $stored === '') {
            return false;
        }

        // Old PINs were stored as bcrypt hashes; they still work until re-issued or upgraded at login.
        if (EncryptedPin::isLegacyHash($stored)) {
            return Hash::check($pin, $stored);
        }

        $plain = $this->pin;

        return $plain !== null && hash_equals($plain, $pin);
    }

    /** True when a PIN exists but was stored as an unreadable bcrypt hash (issued before PINs could be viewed). */
    public function hasLegacyPin(): bool
    {
        return EncryptedPin::isLegacyHash($this->attributes['pin'] ?? null);
    }

    /** Re-store a legacy hashed PIN as an encrypted one. Call only with the PIN just proven correct at login. */
    public function upgradeLegacyPin(string $plainPin): void
    {
        if ($this->hasLegacyPin()) {
            $this->pin = $plainPin;   // encrypted by the cast; pin_created_at is left as issued
            $this->save();
        }
    }

    /**
     * Detection ONLY — callers must NOT block login on expiry (no
     * rotation UX exists yet; rotation is a follow-up task).
     *
     * A PIN older than 6 months is expired. Null pin_created_at means
     * the PIN predates expiry tracking (legacy row) → NOT expired.
     */
    public function isPinExpired(): bool
    {
        if ($this->pin_created_at === null) {
            return false;
        }

        return $this->pin_created_at->lte(now()->subMonths(6));
    }

    public function isLocked(): bool
    {
        if ($this->locked_at && $this->locked_at->gt(now())) {
            return true;
        }
        if ($this->locked_at && $this->locked_at->lte(now())) {
            $this->update(['failed_login_attempts' => 0, 'locked_at' => null]);
            return false;
        }
        return false;
    }

    public function hasBadge(string $slug): bool
    {
        return $this->badges()->where('slug', $slug)->exists();
    }

    public function getBadgeCount(): int
    {
        return $this->badges()->count();
    }

    public function addXp(int $amount): void
    {
        $this->increment('total_xp', $amount);
    }

    public function recordActivity(): void
    {
        $today = now()->toDateString();
        $lastDate = $this->last_activity_date?->toDateString();

        if ($lastDate === $today) {
            return; // already recorded today
        }

        $yesterday = now()->subDay()->toDateString();

        if ($lastDate === $yesterday) {
            // Continue streak (streak fields are guarded — explicit assignment only).
            $newStreak = $this->current_streak + 1;
            $this->current_streak = $newStreak;
            $this->longest_streak = max($this->longest_streak, $newStreak);
            $this->last_activity_date = $today;
            $this->save();
        } else {
            // Reset streak (or first activity)
            $this->current_streak = 1;
            $this->longest_streak = max($this->longest_streak, 1);
            $this->last_activity_date = $today;
            $this->save();
        }

        // Log to unified activity logs for Admin overview
        ActivityLog::create([
            'user_id' => null, // Learners aren't users
            'action' => 'Learner Login',
            'description' => "Learner {$this->getFullName()} logged into the student portal.",
            'subject_type' => 'learner',
            'subject_id' => $this->id,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'created_at' => now(),
        ]);
    }

    public function getActiveSession(): ?AssessmentSession
    {
        return $this->assessmentSessions()->active()->latest()->first();
    }
}
