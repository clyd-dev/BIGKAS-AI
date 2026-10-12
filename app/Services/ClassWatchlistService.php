<?php

namespace App\Services;

use App\Models\Assessment;
use App\Models\InterventionLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Decides which learners in a class need the teacher's attention, and says why.
 *
 * Reading level alone misses the learners who are quietly sliding: the one whose
 * accuracy has dropped two assessments running, the one with an intervention
 * assigned a month ago that was never started, the one never assessed at all.
 * This service checks all four signals, keeps the reason attached to the
 * learner, and ranks the result so the most urgent case is first.
 *
 * Every accuracy and reading level it reads is the *effective* value, so a
 * result the teacher has overridden or invalidated is reflected rather than the
 * raw model output.
 */
class ClassWatchlistService
{
    /** Signal weights. A learner's urgency is the sum of the signals that fired. */
    private const WEIGHTS = [
        'frustration'    => 400,
        'declining'      => 300,
        'stalled'        => 200,
        'never_assessed' => 100,
    ];

    /** How many recent assessments to read when working out a trend. */
    private const TREND_WINDOW = 3;

    /**
     * @param  Builder  $learnersQuery  Scoped to what this teacher may see.
     * @return Collection<int, array>   Ranked watchlist entries.
     */
    public function build(Builder $learnersQuery, int $limit = 5): Collection
    {
        $learners = (clone $learnersQuery)->get();

        if ($learners->isEmpty()) {
            return collect();
        }

        $learnerIds = $learners->pluck('id');
        $history    = $this->recentAssessmentsByLearner($learnerIds);
        $openLogs   = $this->openInterventionsByLearner($learnerIds);

        return $learners
            ->map(fn ($learner) => $this->evaluate(
                $learner,
                $history->get($learner->id, collect()),
                $openLogs->get($learner->id, collect()),
            ))
            ->filter(fn ($entry) => $entry['signals']->isNotEmpty())
            ->sortByDesc(fn ($entry) => [$entry['urgency'], $entry['waiting_days']])
            ->values()
            ->take($limit);
    }

    /**
     * Assess one learner against every signal. Returns the entry regardless of
     * whether anything fired; the caller filters, which keeps the scoring rules
     * in one readable place.
     */
    private function evaluate($learner, Collection $assessments, Collection $openLogs): array
    {
        $trend   = $this->trend($assessments);
        $latest  = $assessments->first();
        $signals = collect();

        $level = $latest?->effectiveReadingLevel() ?? $learner->reading_level;

        if ($level === 'frustration') {
            $signals->push([
                'key'         => 'frustration',
                'label'       => 'Frustration level',
                'tone'        => 'danger',
                'explanation' => $trend['latest'] !== null
                    ? 'Reading at ' . $this->pct($trend['latest']) . ' accuracy, below the 90% instructional floor.'
                    : 'Last assessment placed this learner at frustration level.',
            ]);
        }

        if ($trend['direction'] === 'down') {
            $signals->push([
                'key'         => 'declining',
                'label'       => 'Accuracy dropping',
                'tone'        => 'warning',
                'explanation' => 'Accuracy fell from ' . $this->pct($trend['previous'])
                    . ' to ' . $this->pct($trend['latest']) . ' between the last two assessments.',
            ]);
        }

        $stalled = $openLogs->first(fn (InterventionLog $log) => $log->isStalled());

        if ($stalled) {
            $signals->push([
                'key'         => 'stalled',
                'label'       => 'Intervention not started',
                'tone'        => 'warning',
                'explanation' => '"' . ($stalled->intervention?->name ?? 'An intervention')
                    . '" was assigned ' . $stalled->daysSinceAssigned() . ' days ago and has not been started.',
            ]);
        }

        if ($assessments->isEmpty()) {
            $signals->push([
                'key'         => 'never_assessed',
                'label'       => 'Never assessed',
                'tone'        => 'secondary',
                'explanation' => 'No reading assessment has been recorded, so there is no baseline yet.',
            ]);
        }

        return [
            'learner'      => $learner,
            'level'        => $level,
            'signals'      => $signals,
            'trend'        => $trend,
            'open_logs'    => $openLogs,
            'urgency'      => $signals->sum(fn ($s) => self::WEIGHTS[$s['key']] ?? 0),
            'waiting_days' => $this->waitingDays($learner, $latest, $stalled),
        ];
    }

    /**
     * Direction of travel across the last few assessments, ignoring any the
     * teacher invalidated - an invalidated result is not evidence of anything.
     */
    private function trend(Collection $assessments): array
    {
        $scores = $assessments
            ->reject(fn (Assessment $a) => $a->isInvalidated())
            ->map(fn (Assessment $a) => $a->effectiveAccuracy())
            ->filter(fn ($accuracy) => $accuracy !== null)
            ->values();

        $latest   = $scores->get(0);
        $previous = $scores->get(1);

        if ($latest === null || $previous === null) {
            return [
                'direction' => 'unknown',
                'latest'    => $latest,
                'previous'  => $previous,
                'delta'     => null,
            ];
        }

        $delta     = round($latest - $previous, 1);
        $threshold = (float) config('bigkas.accuracy_decline_points', 3);

        return [
            'direction' => match (true) {
                $delta <= -$threshold => 'down',
                $delta >= $threshold  => 'up',
                default               => 'flat',
            },
            'latest'   => $latest,
            'previous' => $previous,
            'delta'    => $delta,
        ];
    }

    /**
     * How long this learner has been waiting, used to break ties between
     * learners of equal urgency so the longest-neglected case surfaces first.
     */
    private function waitingDays($learner, ?Assessment $latest, ?InterventionLog $stalled): int
    {
        if ($stalled) {
            return $stalled->daysSinceAssigned();
        }

        if ($latest?->created_at) {
            return (int) $latest->created_at->startOfDay()->diffInDays(now()->startOfDay());
        }

        // Never assessed: measure from when the learner joined the roster.
        return (int) ($learner->created_at?->startOfDay()->diffInDays(now()->startOfDay()) ?? 0);
    }

    /**
     * The last few assessments per learner, grouped in PHP. A class is a few
     * dozen learners, so one query beats a per-learner limited subquery.
     *
     * @return Collection<int, Collection<int, Assessment>>
     */
    private function recentAssessmentsByLearner(Collection $learnerIds): Collection
    {
        return Assessment::whereIn('learner_id', $learnerIds)
            ->with(['result', 'verdict'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy('learner_id')
            ->map(fn (Collection $group) => $group->take(self::TREND_WINDOW));
    }

    /**
     * @return Collection<int, Collection<int, InterventionLog>>
     */
    private function openInterventionsByLearner(Collection $learnerIds): Collection
    {
        return InterventionLog::whereIn('learner_id', $learnerIds)
            ->whereIn('status', [InterventionLog::STATUS_PENDING, InterventionLog::STATUS_IN_PROGRESS])
            ->with('intervention')
            ->orderBy('created_at')
            ->get()
            ->groupBy('learner_id');
    }

    private function pct(?float $value): string
    {
        return $value === null ? '--' : round($value, 1) . '%';
    }
}
