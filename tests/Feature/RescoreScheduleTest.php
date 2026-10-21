<?php

namespace Tests\Feature;

use App\Services\AnalysisQueueEstimator;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class RescoreScheduleTest extends TestCase
{
    private function rescoreEvent(): Event
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains($e->command, 'assessments:rescore'));

        $this->assertNotNull($event, 'assessments:rescore is not scheduled.');

        return $event;
    }

    public function test_the_lane_is_tuned_for_a_batch_arriving_at_once(): void
    {
        $event = $this->rescoreEvent();

        $this->assertStringContainsString('--limit=20', $event->command);
        $this->assertSame('* * * * *', $event->expression);     // every minute
        $this->assertSame(120, $event->expiresAt);              // minutes

        // A full roster must clear in one run.
        $this->assertGreaterThanOrEqual(config('assessment.batch_max_learners'), 20);
    }

    public function test_a_rescore_run_cannot_outlast_its_overlap_lock(): void
    {
        $event = $this->rescoreEvent();
        preg_match('/--limit=(\d+)/', $event->command, $m);
        $limit = (int) ($m[1] ?? 5);

        // Worst case 3 min per recording, plus a 1.5x safety margin, must fit
        // inside the lock - otherwise the lock expires mid-run and the next tick
        // starts a second run that scores the same recordings again.
        $this->assertLessThanOrEqual(
            $event->expiresAt,
            $limit * 3 * 1.5,
            'Raising --limit without raising withoutOverlapping lets two runs score the same recording.'
        );
    }

    public function test_the_estimators_fallback_tick_matches_the_real_schedule(): void
    {
        // The teacher's board runs in a web request, where the Schedule is not
        // loaded, so the estimator always uses its fallback there. If the fallback
        // drifts from the real tick, every wait figure on the board is wrong.
        $this->rescoreEvent(); // the schedule must be loaded, or tickSeconds() would itself fall back

        $fallback = (new \ReflectionClassConstant(AnalysisQueueEstimator::class, 'FALLBACK_TICK_SECONDS'))->getValue();

        $this->assertSame(
            $fallback,
            app(AnalysisQueueEstimator::class)->tickSeconds(),
            'FALLBACK_TICK_SECONDS must equal the tick implied by the scheduled expression.'
        );
    }
}
