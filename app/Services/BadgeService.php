<?php

namespace App\Services;

use App\Models\Badge;
use App\Models\Learner;
use Illuminate\Support\Facades\DB;

class BadgeService
{
    /**
     * Check all badge criteria and award any newly earned badges.
     * Returns array of newly awarded badge names.
     */
    public function checkAndAward(Learner $learner): array
    {
        $awarded = [];
        $badges = Badge::active()->get();

        foreach ($badges as $badge) {
            if ($learner->hasBadge($badge->slug)) {
                continue;
            }

            if ($this->meetsCriteria($learner, $badge)) {
                $this->award($learner, $badge);
                $awarded[] = $badge->name;
            }
        }

        return $awarded;
    }

    /**
     * Award a specific badge to a learner.
     */
    public function award(Learner $learner, Badge $badge, ?string $context = null): void
    {
        if ($learner->hasBadge($badge->slug)) {
            return;
        }

        DB::table('learner_badges')->insert([
            'learner_id' => $learner->id,
            'badge_id' => $badge->id,
            'earned_at' => now(),
            'context' => $context,
        ]);

        // Award XP
        if ($badge->xp_reward > 0) {
            $learner->addXp($badge->xp_reward);
        }
    }

    /**
     * Check if a learner meets the criteria for a specific badge.
     */
    protected function meetsCriteria(Learner $learner, Badge $badge): bool
    {
        $criteria = $badge->criteria;

        if (!$criteria || !isset($criteria['type'])) {
            return false;
        }

        return match ($criteria['type']) {
            'assessment_count' => $learner->assessments()->count() >= ($criteria['value'] ?? 1),
            'perfect_score' => $this->hasPerfectScore($learner),
            'streak_days' => $learner->current_streak >= ($criteria['value'] ?? 3),
            'practice_count' => $learner->practiceSessions()->count() >= ($criteria['value'] ?? 5),
            'flashcard_mastery' => $this->hasFlashcardMastery($learner, $criteria['value'] ?? 50),
            'xp_total' => $learner->total_xp >= ($criteria['value'] ?? 100),
            'speed_reader' => $this->isSpeedReader($learner),
            'intervention_count' => $learner->interventionLogs()->where('status', 'completed')->count() >= ($criteria['value'] ?? 5),
            'comprehension_ace' => $this->isComprehensionAce($learner),
            default => false,
        };
    }

    protected function hasPerfectScore(Learner $learner): bool
    {
        return $learner->assessments()
            ->whereHas('result', fn($q) => $q->where('accuracy_rate', '>=', 98))
            ->exists();
    }

    protected function hasFlashcardMastery(Learner $learner, int $threshold): bool
    {
        return $learner->practiceSessions()
            ->where('session_type', 'sight_words')
            ->where('score', '>=', 90)
            ->count() >= $threshold;
    }

    protected function isSpeedReader(Learner $learner): bool
    {
        return $learner->assessments()
            ->whereHas('result', function ($q) {
                $q->where('words_per_minute', '>=', 100);
            })
            ->exists();
    }

    protected function isComprehensionAce(Learner $learner): bool
    {
        return $learner->assessments()
            ->whereHas('result', function ($q) {
                $q->where('comprehension_score', '>=', 90);
            })
            ->count() >= 3;
    }
}
