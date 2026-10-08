<?php

namespace App\Services;

use App\Models\AssessmentResult;
use App\Models\Learner;
use Illuminate\Support\Carbon;

/**
 * How the whole school is progressing in reading, for the admin dashboard:
 *   - coverage: how many learners have been assessed at all
 *   - movement: of those assessed more than once, who moved up, held, or slipped a reading level
 *   - trend: average accuracy and reading speed per month
 *
 * This is the change over time, not a snapshot of who sits at which level.
 */
class SchoolReadingProgress
{
    private const LEVEL_RANK = ['frustration' => 1, 'instructional' => 2, 'independent' => 3];

    public static function summary(int $months = 6): array
    {
        $since = now()->startOfMonth()->subMonths($months - 1);

        $results = AssessmentResult::with('assessment:id,learner_id,created_at,language')
            ->whereHas('assessment', fn ($q) => $q->where('status', 'completed'))
            ->get()
            ->filter(fn ($r) => $r->assessment !== null);

        return [
            'trend'    => self::trend($results, $since, $months),
            'movement' => self::movement($results),
            'coverage' => self::coverage($results),
        ];
    }

    private static function trend($results, Carbon $since, int $months): array
    {
        $labels = $accuracy = $wpm = $counts = [];

        for ($i = 0; $i < $months; $i++) {
            $month = $since->copy()->addMonths($i);
            $rows  = $results->filter(fn ($r) => $r->assessment->created_at->isSameMonth($month));

            $labels[]   = $month->format('M');
            $counts[]   = $rows->count();
            $accuracy[] = $rows->isEmpty() ? null : round((float) $rows->avg('accuracy_rate'), 1);
            $wpm[]      = $rows->isEmpty() ? null : round((float) $rows->avg('words_per_minute'));
        }

        // Change from the first month that has data to the latest one that has.
        $withData = collect($accuracy)->filter(fn ($v) => $v !== null)->values();
        $change   = $withData->count() >= 2 ? round($withData->last() - $withData->first(), 1) : null;

        return [
            'labels'   => $labels,
            'accuracy' => $accuracy,
            'wpm'      => $wpm,
            'counts'   => $counts,
            'change'   => $change,
            'has_data' => $withData->isNotEmpty(),
        ];
    }

    /**
     * Compare each learner's first and latest reading level (Frustration < Instructional < Independent).
     *
     * Only readings in the SAME language are compared, so an English result followed by a Filipino one is never
     * mistaken for progress. A learner assessed in both languages is judged on the language with more readings
     * (the more recent one if tied). Learners with fewer than two readings in that language are left out.
     */
    private static function movement($results): array
    {
        $up = $same = $down = 0;

        foreach ($results->groupBy(fn ($r) => $r->assessment->learner_id) as $rows) {
            $series = $rows->groupBy(fn ($r) => $r->assessment->language)
                ->sortByDesc(fn ($g) => [$g->count(), $g->max(fn ($r) => $r->assessment->created_at->timestamp)])
                ->first()
                ->sortBy(fn ($r) => $r->assessment->created_at)
                ->values();

            if ($series->count() < 2) {
                continue;
            }
            $first  = self::LEVEL_RANK[$series->first()->reading_level] ?? null;
            $latest = self::LEVEL_RANK[$series->last()->reading_level] ?? null;
            if ($first === null || $latest === null) {
                continue;
            }

            match (true) {
                $latest > $first => $up++,
                $latest < $first => $down++,
                default          => $same++,
            };
        }

        return ['improved' => $up, 'steady' => $same, 'declined' => $down, 'total' => $up + $same + $down];
    }

    private static function coverage($results): array
    {
        $learners = Learner::count();
        $assessed = $results->pluck('assessment.learner_id')->unique()->count();

        return [
            'learners' => $learners,
            'assessed' => $assessed,
            'percent'  => $learners > 0 ? (int) round($assessed / $learners * 100) : 0,
        ];
    }
}
