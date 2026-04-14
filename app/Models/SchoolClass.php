<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SchoolClass extends Model
{
    use HasFactory;

    protected $table = 'classes';

    protected $fillable = [
        'school_id',
        'teacher_id',
        'grade_level',
        'section',
        'school_year',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'grade_level' => 'integer',
        ];
    }

    // ── Relationships ──

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function learners(): HasMany
    {
        return $this->hasMany(Learner::class, 'class_id');
    }

    // ── Scopes ──

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // ── Helpers ──

    public function getDisplayName(): string
    {
        $gradeLevels = config('bigkas.grade_levels', []);
        $grade = $gradeLevels[$this->grade_level] ?? "Grade {$this->grade_level}";
        return "{$grade} - {$this->section} ({$this->school_year})";
    }
}
