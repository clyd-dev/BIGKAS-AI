<?php

namespace Tests\Unit;

use App\Services\MLClassificationService as ML;
use PHPUnit\Framework\TestCase;

/**
 * A weakness is only stored when the measured evidence shows it, so the result
 * never names a skill the teacher's own per-skill rows call fine.
 */
class ResolveWeaknessTest extends TestCase
{
    /** @return array<int, string> */
    private function statuses(string $phonemic, string $decoding, string $fluency, string $comprehension): array
    {
        return [1 => $phonemic, 2 => $decoding, 3 => $fluency, 4 => $comprehension];
    }

    public function test_a_prediction_the_evidence_backs_is_kept(): void
    {
        $c = ['primary' => '2', 'all_scores' => [0.1, 0.1, 0.5, 0.1, 0.2]];

        $this->assertSame(2, ML::resolveWeaknessId($c, $this->statuses('ok', 'concern', 'ok', 'ok')));
    }

    /**
     * The reported bug: phonemic green, decoding and fluency flagged, yet
     * Phonemic Awareness was stored as the primary weakness.
     */
    public function test_phonemic_is_dropped_when_no_sound_alike_pattern_was_measured(): void
    {
        $c = ['primary' => '1', 'all_scores' => [0.05, 0.45, 0.2, 0.3, 0.0]];

        $this->assertSame(
            3,
            ML::resolveWeaknessId($c, $this->statuses('ok', 'watch', 'concern', 'unknown')),
            'With phonemic clear, the next most likely supported class should win.'
        );
    }

    public function test_comprehension_is_dropped_when_it_was_never_tested(): void
    {
        $c = ['primary' => '4', 'all_scores' => [0.1, 0.1, 0.15, 0.25, 0.4]];

        $this->assertSame(3, ML::resolveWeaknessId($c, $this->statuses('ok', 'ok', 'watch', 'unknown')));
    }

    public function test_comprehension_is_kept_when_the_questions_show_a_problem(): void
    {
        $c = ['primary' => '4', 'all_scores' => [0.1, 0.1, 0.2, 0.1, 0.5]];

        $this->assertSame(4, ML::resolveWeaknessId($c, $this->statuses('ok', 'ok', 'ok', 'concern')));
    }

    public function test_no_weakness_needs_no_evidence(): void
    {
        $c = ['primary' => '0', 'all_scores' => [0.8, 0.05, 0.05, 0.05, 0.05]];

        $this->assertSame(0, ML::resolveWeaknessId($c, $this->statuses('ok', 'ok', 'ok', 'ok')));
    }

    /** Without probabilities to walk, the clearest measured problem is used. */
    public function test_an_unsupported_pick_with_no_scores_falls_back_to_the_clearest_problem(): void
    {
        $c = ['primary' => '1', 'all_scores' => []];

        $this->assertSame(
            2,
            ML::resolveWeaknessId($c, $this->statuses('ok', 'concern', 'watch', 'unknown')),
            'Red beats amber.'
        );

        $this->assertSame(
            3,
            ML::resolveWeaknessId($c, $this->statuses('ok', 'ok', 'watch', 'watch')),
            'Among equals the more foundational skill comes first.'
        );
    }

    public function test_nothing_is_stored_when_the_evidence_shows_no_problem_at_all(): void
    {
        $c = ['primary' => '1', 'all_scores' => []];

        $this->assertNull(ML::resolveWeaknessId($c, $this->statuses('ok', 'ok', 'ok', 'ok')));
    }

    public function test_a_walk_may_land_on_no_weakness(): void
    {
        // Phonemic is likeliest but unsupported; "none" is next and always allowed.
        $c = ['primary' => '1', 'all_scores' => [0.3, 0.4, 0.1, 0.1, 0.1]];

        $this->assertSame(0, ML::resolveWeaknessId($c, $this->statuses('ok', 'ok', 'ok', 'ok')));
    }
}
