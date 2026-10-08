<?php

namespace App\Services;

/**
 * Reading-level rules of the DepEd Phil-IRI Form 4 (Individual Summary Record).
 *
 * Fixed to the official workbook ("Integrated School Forms Coding" sheet), deliberately NOT read from
 * the app's editable thresholds, so the printed DepEd form always follows the DepEd rule.
 */
class PhilIriLevels
{
    public const INDEPENDENT   = 'independent';
    public const INSTRUCTIONAL = 'instructional';
    public const FRUSTRATION   = 'frustration';

    /** Word reading accuracy (%): >= 97 independent, 90-96 instructional, below 90 frustration. */
    public const WORD_INDEPENDENT_FROM   = 97.0;
    public const WORD_INSTRUCTIONAL_FROM = 90.0;

    /** Comprehension score (%): >= 80 independent, 59-79 instructional, 58 or below frustration. */
    public const COMP_INDEPENDENT_FROM   = 80.0;
    public const COMP_INSTRUCTIONAL_FROM = 59.0;

    /** Passage levels on the form: K, I, II, III, IV, V, VI, VII. */
    public const LEVELS = [0 => 'K', 1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV', 5 => 'V', 6 => 'VI', 7 => 'VII'];

    public static function wordReading(?float $accuracyPercent): ?string
    {
        if ($accuracyPercent === null) {
            return null;
        }

        return match (true) {
            $accuracyPercent >= self::WORD_INDEPENDENT_FROM   => self::INDEPENDENT,
            $accuracyPercent >= self::WORD_INSTRUCTIONAL_FROM => self::INSTRUCTIONAL,
            default                                           => self::FRUSTRATION,
        };
    }

    public static function comprehension(?float $scorePercent): ?string
    {
        if ($scorePercent === null) {
            return null;
        }

        return match (true) {
            $scorePercent >= self::COMP_INDEPENDENT_FROM   => self::INDEPENDENT,
            $scorePercent >= self::COMP_INSTRUCTIONAL_FROM => self::INSTRUCTIONAL,
            default                                        => self::FRUSTRATION,
        };
    }

    public static function levelLabel(int $level): string
    {
        return self::LEVELS[$level] ?? (string) $level;
    }
}
