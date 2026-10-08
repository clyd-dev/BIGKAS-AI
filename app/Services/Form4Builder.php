<?php

namespace App\Services;

use App\Models\Assessment;
use App\Models\Learner;

/**
 * Builds the data of Phil-IRI Form 4 (Individual Summary Record) for one learner, per language.
 *
 * Filled from completed oral-reading assessments: passage level, word-reading level and date taken.
 * Comprehension, passage set, the behaviour checklist and page 2 are not recorded by the system yet and are
 * left blank on the form for the teacher to complete.
 */
class Form4Builder
{
    private const LANGUAGES = ['en' => 'English', 'fil' => 'Filipino'];

    public static function build(Learner $learner): array
    {
        $learner->loadMissing(['schoolClass.teacher', 'school']);
        $class = $learner->schoolClass;

        $assessments = Assessment::where('learner_id', $learner->id)
            ->where('status', Assessment::STATUS_COMPLETED)
            ->whereHas('result')
            ->with(['material', 'result'])
            ->orderBy('created_at')
            ->get();

        $languages = [];
        foreach (self::LANGUAGES as $code => $label) {
            $rows = $assessments->where('language', $code);

            // Level started = the first passage level the learner was tested at in this language.
            $first = $rows->first(fn ($a) => $a->material !== null);
            $started = $first ? self::passageLevel($first) : null;

            $levels = [];
            foreach (PhilIriLevels::LEVELS as $num => $roman) {
                // Latest completed assessment at this passage level.
                $a = $rows->filter(fn ($x) => $x->material && self::passageLevel($x) === $num)->last();

                $levels[$num] = [
                    'roman'     => $roman,
                    'started'   => $started === $num,
                    'word'      => $a ? PhilIriLevels::wordReading($a->result->accuracy_rate !== null ? (float) $a->result->accuracy_rate : null) : null,
                    'date'      => $a ? ($a->assessed_at ?? $a->created_at)?->format('M d, Y') : null,
                    'accuracy'  => $a?->result->accuracy_rate,
                    'has_data'  => (bool) $a,
                ];
            }

            $languages[$code] = [
                'label'    => $label,
                'levels'   => $levels,
                'has_data' => $rows->isNotEmpty(),
            ];
        }

        // If the learner has no assessment in any language, print both blank forms.
        $anyData = collect($languages)->contains('has_data', true);

        return [
            'name'      => $learner->getFullName(),
            'age'       => $learner->birth_date ? $learner->birth_date->age : null,
            'grade'     => $learner->grade_level,
            'section'   => $class?->section,
            'school'    => $learner->school?->name ?? $class?->school?->name,
            'teacher'   => $class?->teacher?->name,
            'languages' => $anyData ? array_filter($languages, fn ($l) => $l['has_data']) : $languages,
            'any_data'  => $anyData,
        ];
    }

    private static function passageLevel(Assessment $a): ?int
    {
        $g = $a->material?->grade_level;

        return $g !== null && isset(PhilIriLevels::LEVELS[(int) $g]) ? (int) $g : null;
    }
}
