<?php

namespace App\Services;

use App\Models\Assessment;

/**
 * Data of Phil-IRI Form 3A (Filipino) / 3B (English): the Grade Level Passage Rating Sheet for one assessment.
 *
 * Filled from the recorded oral reading. Not recorded by the system, so left blank for the teacher:
 * type of test, set, comprehension responses/score, and the miscue types Mispronunciation, Transposition and Reversal.
 */
class Form3Builder
{
    public static function build(Assessment $assessment): array
    {
        $assessment->loadMissing(['learner.schoolClass.teacher', 'learner.school', 'material', 'result', 'assessor']);

        $learner  = $assessment->learner;
        $class    = $learner->schoolClass;
        $material = $assessment->material;
        $result   = $assessment->result;
        $fil      = $assessment->language === 'fil';

        $words = $material?->word_count ? (int) $material->word_count : null;
        $wpm   = $result?->words_per_minute ? (float) $result->words_per_minute : null;

        // Total reading time is not stored; it follows from the words read and the reading rate.
        $seconds = ($words && $wpm) ? (int) round($words / $wpm * 60) : null;

        $miscues = [
            ['Mispronunciation', 'Maling Bigkas',                  null],
            ['Omission',         'Pagkakaltas',                    $result?->omissions],
            ['Substitution',     'Pagpapalit',                     $result?->substitutions],
            ['Insertion',        'Pagsisingit',                    $result?->insertions],
            ['Repetition',       'Pag-uulit',                      $result?->repetitions],
            ['Transposition',    'Pagpapalit ng lugar',            null],
            ['Reversal',         'Paglilipat',                     null],
        ];
        $totalMiscues = collect($miscues)->sum(fn ($m) => (int) $m[2]);

        $wordScore = ($words && $result) ? max(0.0, ($words - $totalMiscues) / $words * 100) : null;

        return [
            'formNo'   => $fil ? '3A' : '3B',
            'fil'      => $fil,
            'name'     => $learner->getFullName(),
            'grade'    => $learner->grade_level,
            'section'  => $class?->section,
            'school'   => $learner->school?->name ?? $class?->school?->name,
            'teacher'  => $class?->teacher?->name,
            'administrator' => $assessment->assessor?->name,
            'date'     => ($assessment->assessed_at ?? $assessment->created_at)?->format('M d, Y'),
            'passage'  => $material?->title,
            'level'    => $material ? PhilIriLevels::levelLabel((int) $material->grade_level) : null,
            'words'    => $words,
            'minutes'  => $seconds !== null ? intdiv($seconds, 60) : null,
            'seconds'  => $seconds !== null ? $seconds % 60 : null,
            'wpm'      => $wpm !== null ? round($wpm, 1) : null,
            'miscues'  => $miscues,
            'total_miscues'   => $totalMiscues,
            'word_score'      => $wordScore !== null ? round($wordScore, 1) : null,
            'word_level'      => PhilIriLevels::wordReading($wordScore),
        ];
    }
}
