<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
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
        'role',
        'school_id',
        'phone',
        'avatar',
        'email_verified_at',
        'last_login_at',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
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

    public function taughtClasses(): HasMany
    {
        return $this->hasMany(SchoolClass::class, 'teacher_id');
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
