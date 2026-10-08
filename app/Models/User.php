<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, Notifiable;

    // Role constants
    const ROLE_ADMIN = 'admin';
    const ROLE_TEACHER = 'teacher';
    const ROLE_PARENT = 'parent';
    const ROLE_STUDENT = 'student';

    protected $fillable = [
        'name',
        'email',
        'password',
        'school_id',
        'phone',
        'avatar',
        'last_login_at',
        'failed_login_attempts',
        'locked_at',
    ];

    /**
     * Sensitive fields — never mass-assignable. Set via explicit
     * attribute assignment only (e.g. $user->role = ...; $user->save()).
     */
    protected $guarded = [
        'role',
        'is_active',
        'email_verified_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'email_index',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'locked_at' => 'datetime',
            'password' => 'hashed',
            // Personal data is stored encrypted (see the encrypt_learner_and_user_identity migration).
            'name' => 'encrypted',
            'email' => 'encrypted',
            'phone' => 'encrypted',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Exact-match fingerprint of the (encrypted) email: used for login and the uniqueness check.
        static::saving(function (self $user) {
            if ($user->isDirty('email') || $user->email_index === null) {
                $user->email_index = \App\Support\BlindIndex::make($user->email, 'email');
            }
        });
    }

    public function scopeByEmail($query, ?string $email)
    {
        return $query->where('email_index', \App\Support\BlindIndex::make($email, 'email') ?? '-none-');
    }

    /**
     * Password-reset tokens are keyed by the email's blind index, so the reset table never holds a readable email.
     * (The reset link itself still carries the real email; see AppServiceProvider.)
     */
    public function getEmailForPasswordReset(): string
    {
        return (string) $this->email_index;
    }

    // ── Relationships ──

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function learners(): BelongsToMany
    {
        return $this->belongsToMany(Learner::class, 'learner_user')
            ->withPivot('relationship')
            ->withTimestamps();
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(Assessment::class, 'assessor_id');
    }

    public function assignedInterventions(): HasMany
    {
        return $this->hasMany(InterventionLog::class, 'assigned_by');
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    /** True when the teacher has been given a grade & section (the principal assigns it). */
    public function hasAssignedClass(): bool
    {
        return $this->taughtClasses()->exists();
    }

    public function taughtClasses(): HasMany
    {
        return $this->hasMany(SchoolClass::class, 'teacher_id');
    }

    /**
     * Gets a query builder for all learners this user has access to.
     */
    public function accessibleLearnersQuery()
    {
        if ($this->isAdmin()) {
            return Learner::query();
        }

        if ($this->isTeacher()) {
            $classIds = $this->taughtClasses()->pluck('id');
            $userId = $this->id;
            return Learner::where(function($q) use ($classIds, $userId) {
                $q->whereIn('class_id', $classIds)
                  ->orWhereHas('users', function($uq) use ($userId) {
                      $uq->where('users.id', $userId);
                  });
            });
        }

        return $this->learners(); // Parents only see their linked learners
    }

    // ── Role Checks ──

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isTeacher(): bool
    {
        return $this->role === self::ROLE_TEACHER;
    }

    public function isParent(): bool
    {
        return $this->role === self::ROLE_PARENT;
    }

    public function isStudent(): bool
    {
        return $this->role === self::ROLE_STUDENT;
    }

    public function hasRole(string ...$roles): bool
    {
        return in_array($this->role, $roles);
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

    // ── Helpers ──

    public function getDisplayName(): string
    {
        return $this->name ?? 'User';
    }

    public function getRecentAssessments(int $limit = 10)
    {
        return $this->assessments()
            ->with(['learner', 'material'])
            ->latest()
            ->limit($limit)
            ->get();
    }

    /**
     * Get the learner record linked to this student user (if role=student).
     */
    public function linkedLearner(): ?Learner
    {
        if (!$this->isStudent()) {
            return null;
        }

        return $this->learners()->first();
    }

    /**
     * Get dashboard statistics for teacher/parent.
     */
    public function getStats(): array
    {
        $learnerIds = $this->learners()->pluck('learners.id');

        if ($learnerIds->isEmpty()) {
            return [
                'total_learners' => 0,
                'total_assessments' => 0,
                'pending_interventions' => 0,
                'this_month_assessments' => 0,
            ];
        }

        return [
            'total_learners' => $learnerIds->count(),
            'total_assessments' => Assessment::whereIn('learner_id', $learnerIds)->count(),
            'pending_interventions' => InterventionLog::whereIn('learner_id', $learnerIds)
                ->where('status', 'pending')->count(),
            'this_month_assessments' => Assessment::whereIn('learner_id', $learnerIds)
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->count(),
        ];
    }
}
