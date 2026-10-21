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
}
