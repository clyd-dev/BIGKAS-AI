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

    /**
     * Rules BadgeService can award on, with a plain-language label and whether the rule takes a number.
     * [label, takes a number?, unit shown next to the number]
     */
    public const RULES = [
        'assessment_count'   => ['Completes assessments', true,  'assessments'],
        'perfect_score'      => ['Gets a perfect score', false, ''],
        'streak_days'        => ['Keeps a daily streak', true,  'days in a row'],
        'practice_count'     => ['Completes practice sessions', true, 'sessions'],
        'flashcard_mastery'  => ['Masters flash cards', true,  '% mastery'],
        'xp_total'           => ['Earns total XP', true,  'XP'],
        'speed_reader'       => ['Reads at speed-reader pace', false, ''],
        'intervention_count' => ['Completes interventions', true,  'interventions'],
        'comprehension_ace'  => ['Aces comprehension', false, ''],
    ];

    public const CATEGORIES = ['assessment', 'streak', 'practice', 'milestone'];

    /** Human sentence for the badge's rule, e.g. "Keeps a daily streak: 3 days in a row". */
    public function ruleLabel(): string
    {
        $type = $this->criteria['type'] ?? null;
        $rule = self::RULES[$type] ?? null;
        if (! $rule) {
            return 'Awarded manually';
        }

        return $rule[0] . ($rule[1] && isset($this->criteria['value']) ? ': ' . $this->criteria['value'] . ' ' . $rule[2] : '');
    }

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
