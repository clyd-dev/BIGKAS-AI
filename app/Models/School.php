<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class School extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'school_id_number',
        'address',
        'district',
        'division',
        'region',
        'contact_number',
        'email',
        'principal_name',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    // ── Relationships ──

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function teachers(): HasMany
    {
        return $this->hasMany(User::class)->where('role', 'teacher');
    }

    public function learners(): HasMany
    {
        return $this->hasMany(Learner::class);
    }

    public function classes(): HasMany
    {
        return $this->hasMany(SchoolClass::class);
    }

    // ── Scopes ──

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // ── Helpers ──

    public function getFullName(): string
    {
        return $this->name . ($this->district ? ' - ' . $this->district : '');
    }

    public function getStats(): array
    {
        return [
            'learners' => $this->learners()->count(),
            'teachers' => $this->teachers()->count(),
            'assessments' => Assessment::whereHas('learner', fn($q) => $q->where('school_id', $this->id))->count(),
            'frustration_learners' => $this->learners()->where('reading_level', 'frustration')->count(),
        ];
    }
}
