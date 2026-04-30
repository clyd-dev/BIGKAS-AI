<?php

namespace Database\Seeders;

use App\Models\Badge;
use Illuminate\Database\Seeder;

class BadgeSeeder extends Seeder
{
    /**
     * Seed the badges table with the 12 achievement badges.
     */
    public function run(): void
    {
        $badges = [
            // ── Assessment Badges ──
            [
                'slug' => 'first-assessment',
                'name' => 'First Assessment',
                'description' => 'Complete your first reading assessment',
                'icon' => '📝',
                'color' => '#6C63FF',
                'category' => 'assessment',
                'xp_reward' => 20,
                'criteria' => ['type' => 'assessment_count', 'value' => 1],
                'sort_order' => 1,
            ],
            [
                'slug' => 'five-assessments',
                'name' => '5 Assessments',
                'description' => 'Complete 5 reading assessments',
                'icon' => '🎯',
                'color' => '#8B83FF',
                'category' => 'assessment',
                'xp_reward' => 50,
                'criteria' => ['type' => 'assessment_count', 'value' => 5],
                'sort_order' => 2,
            ],
            [
                'slug' => 'speed-reader',
                'name' => 'Speed Reader',
                'description' => 'Read 100+ words per minute in an assessment',
                'icon' => '⚡',
                'color' => '#FFB830',
                'category' => 'assessment',
                'xp_reward' => 40,
                'criteria' => ['type' => 'speed_reader'],
                'sort_order' => 3,
            ],
            [
                'slug' => 'perfect-score',
                'name' => 'Perfect Score',
                'description' => 'Get 98%+ accuracy in an assessment',
                'icon' => '💯',
                'color' => '#00C897',
                'category' => 'assessment',
                'xp_reward' => 60,
                'criteria' => ['type' => 'perfect_score'],
                'sort_order' => 4,
            ],

            // ── Streak Badges ──
            [
                'slug' => 'streak-3',
                'name' => '3-Day Streak',
                'description' => 'Use BIGKAS-AI for 3 days in a row',
                'icon' => '🔥',
                'color' => '#FF8A5C',
                'category' => 'streak',
                'xp_reward' => 15,
                'criteria' => ['type' => 'streak_days', 'value' => 3],
                'sort_order' => 5,
            ],
            [
                'slug' => 'streak-7',
                'name' => '7-Day Streak',
                'description' => 'Use BIGKAS-AI for 7 days in a row',
                'icon' => '🌟',
                'color' => '#FF6584',
                'category' => 'streak',
                'xp_reward' => 30,
                'criteria' => ['type' => 'streak_days', 'value' => 7],
                'sort_order' => 6,
            ],
            [
                'slug' => 'streak-30',
                'name' => '30-Day Streak',
                'description' => 'Use BIGKAS-AI for 30 days in a row!',
                'icon' => '👑',
                'color' => '#FFD700',
                'category' => 'streak',
                'xp_reward' => 100,
                'criteria' => ['type' => 'streak_days', 'value' => 30],
                'sort_order' => 7,
            ],

            // ── Practice Badges ──
            [
                'slug' => 'flashcard-master',
                'name' => 'Flash Card Master',
                'description' => 'Score 90%+ on 5 flash card sessions',
                'icon' => '🃏',
                'color' => '#4ECDC4',
                'category' => 'practice',
                'xp_reward' => 35,
                'criteria' => ['type' => 'flashcard_mastery', 'value' => 5],
                'sort_order' => 8,
            ],
            [
                'slug' => 'practice-champion',
                'name' => 'Practice Champion',
                'description' => 'Complete 10 practice sessions',
                'icon' => '🏆',
                'color' => '#6C63FF',
                'category' => 'practice',
                'xp_reward' => 40,
                'criteria' => ['type' => 'practice_count', 'value' => 10],
                'sort_order' => 9,
            ],

            // ── Milestone Badges ──
            [
                'slug' => 'bookworm',
                'name' => 'Bookworm',
                'description' => 'Complete 5 intervention activities',
                'icon' => '📚',
                'color' => '#FF8A5C',
                'category' => 'milestone',
                'xp_reward' => 30,
                'criteria' => ['type' => 'intervention_count', 'value' => 5],
                'sort_order' => 10,
            ],
            [
                'slug' => 'rising-star',
                'name' => 'Rising Star',
                'description' => 'Earn 200 total XP',
                'icon' => '⭐',
                'color' => '#FFB830',
                'category' => 'milestone',
                'xp_reward' => 25,
                'criteria' => ['type' => 'xp_total', 'value' => 200],
                'sort_order' => 11,
            ],
            [
                'slug' => 'comprehension-king',
                'name' => 'Comprehension King',
                'description' => 'Score 90%+ comprehension in 3 assessments',
                'icon' => '🧠',
                'color' => '#00C897',
                'category' => 'milestone',
                'xp_reward' => 50,
                'criteria' => ['type' => 'comprehension_ace'],
                'sort_order' => 12,
            ],
        ];

        foreach ($badges as $badgeData) {
            Badge::updateOrCreate(
                ['slug' => $badgeData['slug']],
                $badgeData
            );
        }

        $this->command->info('Seeded ' . count($badges) . ' badges.');
    }
}
