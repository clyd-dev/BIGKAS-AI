<?php

namespace App\Services;

/**
 * Whether the measured evidence actually shows a problem in each reading skill.
 *
 * One source of truth for two jobs that used to disagree:
 *
 *  - the per-skill rows a teacher reads on the results page
 *    (ReadingInterpretationService::skillSigns), and
 *  - which weakness may be stored as the primary one
 *    (MLClassificationService::resolveWeaknessId).
 *
 * They disagreed because the classifier's three pattern features
 * (phonetic/vowel/blend) are *shares of the errors a reader made*, not rates
 * per word, so they rise to ~1.0 as soon as a single slip is bucketed that
 * way. Every hand-written rule that read them as magnitudes concluded
 * "Phonemic Awareness" from one vowel-ish slip, while the teacher's own row
 * for that skill — which weighs the count as well as the share — stayed green.
 *
 * The feature definitions themselves are deliberately left alone: the shipped
 * Random Forest was trained on those shares, so changing them here would feed
 * the model inputs it has never seen. Instead a prediction is only reportable
 * when the evidence below backs it up.
 */
class WeaknessEvidence
{
    /** A skill is reportable as a weakness when its row is amber or red. */
    private const SHOWS_A_PROBLEM = ['watch', 'concern'];

    public static function showsProblem(?string $status): bool
    {
        return in_array($status, self::SHOWS_A_PROBLEM, true);
    }

    /**
     * The clearest problem in a status map: red before amber, and where those
     * tie the more foundational skill, since decoding is taught before pace and
     * pace before comprehension.
     *
     * @param array<int, string> $statuses
     */
    public static function clearestProblem(array $statuses): ?int
    {
        foreach (['concern', 'watch'] as $severity) {
            $matches = array_keys($statuses, $severity, true);

            if ($matches !== []) {
                sort($matches);

                return (int) $matches[0];
            }
        }

        return null;
    }

    /**
     * Matching letters to sounds.
     *
     * Needs both a real count of sound-alike slips and a real share of them:
     * one slip out of twenty is not a phonics pattern, however it is bucketed.
     */
    public static function phonemicStatus(int $soundAlike, int $slips): string
    {
        $share = $slips > 0 ? $soundAlike / $slips : 0;

        return match (true) {
            $soundAlike >= 3 && $share >= 0.4 => 'concern',
            $soundAlike >= 1 && $share >= 0.4 => 'watch',
            default => 'ok',
        };
    }

    /** Reading words correctly, judged on the words actually attempted. */
    public static function decodingStatus(float $attemptedAccuracy): string
    {
        return match (true) {
            $attemptedAccuracy < PhilIriLevels::WORD_INSTRUCTIONAL_FROM => 'concern',
            $attemptedAccuracy < PhilIriLevels::WORD_INDEPENDENT_FROM => 'watch',
            default => 'ok',
        };
    }

    /** Pace and smoothness against the grade benchmark, plus pauses and repeats. */
    public static function fluencyStatus(float $wpm, ?array $bench, int $longPauses, int $hesitations, int $repeats): string
    {
        $rank = ['ok' => 0, 'watch' => 1, 'concern' => 2];
        $status = 'ok';
        $raise = function (string $to) use (&$status, $rank) {
            if ($rank[$to] > $rank[$status]) {
                $status = $to;
            }
        };

        if ($bench) {
            $raise($wpm < $bench['min'] ? 'concern' : ($wpm < $bench['target'] ? 'watch' : 'ok'));
        }

        if ($longPauses >= 3) {
            $raise('concern');
        } elseif ($longPauses >= 1 || $hesitations >= 3) {
            $raise('watch');
        }

        if ($repeats >= 3) {
            $raise('watch');
        }

        return $status;
    }

    /** Understanding the text. Only the comprehension questions measure this. */
    public static function comprehensionStatus(?float $score): string
    {
        if ($score === null) {
            return 'unknown';
        }

        return ['independent' => 'ok', 'instructional' => 'watch', 'frustration' => 'concern'][
            PhilIriLevels::comprehension($score)
        ];
    }

    /**
     * The status of each skill (ok / watch / concern / unknown), keyed 1-4.
     *
     * Called at analysis time, from the same `$analysis` array the results page
     * is later rendered from, so a stored primary weakness can never contradict
     * the rows the teacher is shown.
     *
     * @return array<int, string>
     */
    public static function statuses(array $analysis, ?int $gradeLevel, ?float $comprehensionScore): array
    {
        $counts = ($analysis['miscues'] ?? []) + array_fill_keys(
            ['correct', 'mispronunciation', 'substitution', 'omission', 'not_reached',
                'insertion', 'repetition', 'hesitation', 'long_pause'],
            0
        );

        $slips = $counts['mispronunciation'] + $counts['substitution'] + $counts['omission']
            + $counts['insertion'] + $counts['repetition'];

        $total = (int) ($analysis['total_words'] ?? 0);
        $attempted = max(0, $total - $counts['not_reached']);
        $correct = $counts['correct']
            ?: (int) round(((float) ($analysis['accuracy_rate'] ?? 0)) / 100 * $total);

        // With nothing attempted there is no decoding evidence either way.
        $decoding = $attempted > 0
            ? self::decodingStatus($correct / $attempted * 100)
            : 'unknown';

        return [
            1 => self::phonemicStatus($counts['mispronunciation'], $slips),
            2 => $decoding,
            3 => self::fluencyStatus(
                (float) ($analysis['words_per_minute'] ?? 0),
                $gradeLevel ? config("bigkas.wpm_benchmarks.{$gradeLevel}") : null,
                $counts['long_pause'],
                $counts['hesitation'],
                $counts['repetition'],
            ),
            4 => self::comprehensionStatus($comprehensionScore),
        ];
    }
}
