<?php

namespace App\Services;

use App\Models\Intervention;
use App\Models\InterventionLog;
use App\Models\AssessmentResult;
use App\Models\Learner;

class InterventionRecommenderService
{
    public function getRecommendations(
        AssessmentResult $result,
        Learner $learner,
        ?string $userRole = null
    ): array {
        $recommendations = [];

        // Primary weakness interventions
        if ($result->primary_weakness) {
            $primary = $this->getInterventionsForWeakness($result->primary_weakness, $learner->grade_level, $userRole);
            foreach ($primary as $intervention) {
                $recommendations[] = [
                    'intervention' => $intervention->toArray(),
                    'priority' => 'high',
                    'reason' => $this->generateReason($intervention, $result, 'primary'),
                    'estimated_impact' => $this->estimateImpact($intervention, $result, $learner),
                ];
            }
        }

        // Secondary weakness interventions
        if ($result->secondary_weakness && $result->secondary_weakness !== $result->primary_weakness) {
            $secondary = $this->getInterventionsForWeakness($result->secondary_weakness, $learner->grade_level, $userRole);
            foreach ($secondary as $intervention) {
                $recommendations[] = [
                    'intervention' => $intervention->toArray(),
                    'priority' => 'medium',
                    'reason' => $this->generateReason($intervention, $result, 'secondary'),
                    'estimated_impact' => $this->estimateImpact($intervention, $result, $learner),
                ];
            }
        }

        // Add fluency interventions if low WPM
        if ($result->words_per_minute < 80) {
            $fluency = $this->getInterventionsForWeakness(3, $learner->grade_level, $userRole);
            $existingIds = collect($recommendations)->pluck('intervention.id')->toArray();

            foreach ($fluency->take(2) as $intervention) {
                if (!in_array($intervention->id, $existingIds)) {
                    $recommendations[] = [
                        'intervention' => $intervention->toArray(),
                        'priority' => 'low',
                        'reason' => 'Recommended to improve reading speed and fluency',
                        'estimated_impact' => $this->estimateImpact($intervention, $result, $learner),
                    ];
                }
            }
        }

        // Sort by priority then impact
        usort($recommendations, function ($a, $b) {
            $order = ['high' => 0, 'medium' => 1, 'low' => 2];
            $diff = $order[$a['priority']] - $order[$b['priority']];
            return $diff !== 0 ? $diff : $b['estimated_impact'] - $a['estimated_impact'];
        });

        return array_slice($recommendations, 0, 7);
    }

    protected function getInterventionsForWeakness(int $weakness, int $gradeLevel, ?string $userRole)
    {
        return Intervention::getByWeakness($weakness, $gradeLevel, $userRole)->take(3);
    }

    protected function generateReason(Intervention $intervention, AssessmentResult $result, string $type): string
    {
        $name = $intervention->getWeaknessInfo()['name'] ?? 'reading skills';

        $reasons = $type === 'primary'
            ? [
                "Analysis identified {$name} as the primary area needing support",
                "Based on assessment, this activity targets the main difficulty in {$name}",
                "Recommended to address primary weakness in {$name}",
            ]
            : [
                "Additional support for {$name} will reinforce learning",
                "Targets secondary area of difficulty in {$name}",
                "Supplementary activity for {$name}",
            ];

        return $reasons[array_rand($reasons)];
    }

    protected function estimateImpact(Intervention $intervention, AssessmentResult $result, Learner $learner): int
    {
        $impact = 50;

        if ($intervention->target_weakness === $result->primary_weakness) {
            $impact += 20;
        }

        $impact += ($intervention->effectiveness_score ?? 5) * 2;

        if ($intervention->isSuitableForGrade($learner->grade_level)) {
            $impact += 10;
        }

        $avgRating = InterventionLog::where('learner_id', $learner->id)
            ->whereHas('intervention', fn($q) => $q->where('target_weakness', $intervention->target_weakness))
            ->whereNotNull('effectiveness_rating')
            ->avg('effectiveness_rating');

        $impact += ((int) round((float) $avgRating)) * 5;

        return min(100, max(0, $impact));
    }

    public function getAllInterventions(array $filters = [])
    {
        $query = Intervention::active();

        if (isset($filters['weakness'])) {
            $query->forWeakness($filters['weakness']);
        }
        if (isset($filters['grade_level'])) {
            $query->forGrade($filters['grade_level']);
        }
        if (isset($filters['activity_type'])) {
            $query->where('activity_type', $filters['activity_type']);
        }

        return $query->orderByDesc('effectiveness_score')->get();
    }

    public function getCategorySummary(): array
    {
        $categories = config('bigkas.weakness_categories', []);
        $summary = [];

        foreach ($categories as $id => $info) {
            $summary[] = [
                'id' => $id,
                'name' => $info['name'],
                'code' => $info['code'],
                'description' => $info['description'],
                'intervention_count' => Intervention::active()->forWeakness($id)->count(),
            ];
        }

        return $summary;
    }

    public function generateInterventionPlan(Learner $learner, array $recommendations, int $weeksCount = 4): array
    {
        $plan = [
            'learner' => ['name' => $learner->getFullName(), 'grade' => $learner->getGradeLevelName(), 'reading_level' => $learner->reading_level],
            'created_at' => now()->toDateString(),
            'duration_weeks' => $weeksCount,
            'weekly_schedule' => [],
        ];

        $high = array_values(array_filter($recommendations, fn($r) => $r['priority'] === 'high'));
        $medium = array_values(array_filter($recommendations, fn($r) => $r['priority'] === 'medium'));

        for ($week = 1; $week <= $weeksCount; $week++) {
            $activities = [];
            if (!empty($high)) {
                $act = $high[($week - 1) % count($high)];
                $activities[] = ['name' => $act['intervention']['name'], 'description' => $act['intervention']['description'], 'duration' => ($act['intervention']['estimated_duration'] ?? 15) . ' minutes', 'frequency' => 'Daily or as needed'];
            }
            if (!empty($medium)) {
                $act = $medium[($week - 1) % count($medium)];
                $activities[] = ['name' => $act['intervention']['name'], 'description' => $act['intervention']['description'], 'duration' => ($act['intervention']['estimated_duration'] ?? 15) . ' minutes', 'frequency' => 'Daily or as needed'];
            }
            $plan['weekly_schedule'][] = ['week' => $week, 'activities' => $activities];
        }

        return $plan;
    }
}
