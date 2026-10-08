<?php

namespace App\Services;

/**
 * Scoring rules of the Phil-IRI Group Screening Test (Forms 1A / 1B).
 *
 * Kept in one place so it can be updated if DepEd changes the rule.
 * Source: "Phil-IRI Form 1A and 1B" workbook (Integrated School Forms Coding sheet).
 */
class GstScoring
{
    /** Items per question type on the screening test: literal, inferential, critical (20 total). */
    public const ITEMS = ['literal' => 7, 'inferential' => 7, 'critical' => 6];

    /** A total score at or above this stops testing ("Discontinue"). */
    public const PASSING_SCORE = 14;

    /** Between LOW_BAND_FROM and PASSING_SCORE - 1 starts 2 levels down; below it, 3 levels down. */
    public const LOW_BAND_FROM = 8;

    /** Phil-IRI is administered in Grades 3 to 6. */
    public const MIN_GRADE = 3;
    public const MAX_GRADE = 6;

    public const AT_GRADE_LEVEL    = 'at_grade_level';
    public const NEEDS_ASSESSMENT  = 'needs_assessment';

    public static function maxScore(): int
    {
        return array_sum(self::ITEMS);
    }

    public static function total(?int $literal, ?int $inferential, ?int $critical): ?int
    {
        if ($literal === null || $inferential === null || $critical === null) {
            return null;
        }

        return $literal + $inferential + $critical;
    }

    public static function classify(?int $total): ?string
    {
        if ($total === null) {
            return null;
        }

        return $total >= self::PASSING_SCORE ? self::AT_GRADE_LEVEL : self::NEEDS_ASSESSMENT;
    }

    /**
     * Grade level at which the individual graded-passage assessment should start.
     * null = "Discontinue" (score met the passing mark); 0 = Kindergarten.
     */
    public static function startingLevel(int $testLevel, ?int $total): ?int
    {
        if ($total === null || $total >= self::PASSING_SCORE) {
            return null;
        }

        $level = $total >= self::LOW_BAND_FROM ? $testLevel - 2 : $testLevel - 3;

        return max(0, $level);
    }

    public static function startingLevelLabel(?int $level): string
    {
        if ($level === null) {
            return 'Discontinue';
        }

        return $level === 0 ? 'Kindergarten' : "Grade {$level}";
    }

    /** Is a learner's grade within the range the screening test covers? */
    public static function gradeEligible(?int $grade): bool
    {
        return $grade !== null && $grade >= self::MIN_GRADE && $grade <= self::MAX_GRADE;
    }
}
