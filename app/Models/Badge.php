<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Badge extends Model
{
    protected $fillable = [
        'slug',
        'name',
        'description',
        'icon',
        'color',
        'category',
        'xp_reward',
        'criteria',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'criteria' => 'array',
            'xp_reward' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    // ── Relationships ──

    public function learners(): BelongsToMany
    {
        return $this->belongsToMany(Learner::class, 'learner_badges')
            ->withPivot('earned_at', 'context')
            ->orderByPivot('earned_at', 'desc');
    }

    // ── Scopes ──

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    // ── Helpers ──

    public function isEarnedBy(Learner $learner): bool
    {
        return $this->learners()->where('learner_id', $learner->id)->exists();
    }
}
