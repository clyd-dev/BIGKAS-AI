<?php

namespace App\Services;

use App\Models\ClassReport;
use App\Models\School;
use App\Models\SchoolClass;

/**
 * Consolidates the Group Screening Test counts in the teachers' submitted section reports
 * into Phil-IRI Form 2 (School Reading Profile): per language, per grade (III–VI), per section.
 */
class Form2Builder
{
    private const ROMAN = [3 => 'III', 4 => 'IV', 5 => 'V', 6 => 'VI'];

    public static function build(string $schoolYear, string $period): array
    {
        $classes = SchoolClass::with('school')
            ->where('school_year', $schoolYear)
            ->whereBetween('grade_level', [GstScoring::MIN_GRADE, GstScoring::MAX_GRADE])
            ->orderBy('grade_level')->orderBy('section')
            ->get();

        $school = $classes->first()?->school ?? School::orderBy('id')->first();

        $languages = [];
        foreach (['fil' => 'Filipino', 'en' => 'English'] as $code => $label) {
            $languages[$code] = ['label' => $label, 'grades' => [], 'total' => self::zero()];
        }

        $missing = [];
        $unreviewed = 0;

        foreach ($classes as $class) {
            // Latest report that was not returned for correction.
            $report = ClassReport::where('class_id', $class->id)
                ->where('school_year', $schoolYear)->where('period', $period)
                ->whereIn('status', [ClassReport::STATUS_SUBMITTED, ClassReport::STATUS_REVIEWED])
                ->latest('submitted_at')->first();

            $screening = $report?->snapshot['screening'] ?? null;
            if (! $screening) {
                $missing[] = "Grade {$class->grade_level} – {$class->section}";
                continue;
            }
            if ($report->status === ClassReport::STATUS_SUBMITTED) {
                $unreviewed++;
            }

            foreach ($languages as $code => &$lang) {
                $row = $screening[$code] ?? null;
                if (! $row) {
                    continue;
                }
                $g = $class->grade_level;
                $lang['grades'][$g] ??= ['roman' => self::ROMAN[$g], 'sections' => [], 'total' => self::zero()];
                $line = [
                    'section'    => $class->section,
                    'enrolment'  => (int) $row['enrolment'],
                    'at_grade'   => (int) $row['at_grade'],
                    'below'      => (int) $row['below'],
                    'not_tested' => (int) $row['not_tested'],
                ];
                $lang['grades'][$g]['sections'][] = $line;
                foreach (['enrolment', 'at_grade', 'below', 'not_tested'] as $k) {
                    $lang['grades'][$g]['total'][$k] += $line[$k];
                    $lang['total'][$k] += $line[$k];
                }
            }
            unset($lang);
        }

        foreach ($languages as &$lang) {
            ksort($lang['grades']);
        }
        unset($lang);

        return [
            'school_year' => $schoolYear,
            'period'      => $period,
            'school'      => [
                'name'      => $school?->name,
                'division'  => $school?->division,
                'district'  => $school?->district,
                'region'    => $school?->region,
                'principal' => $school?->principal_name,
            ],
            'languages'  => $languages,
            'missing'    => $missing,
            'unreviewed' => $unreviewed,
        ];
    }

    private static function zero(): array
    {
        return ['enrolment' => 0, 'at_grade' => 0, 'below' => 0, 'not_tested' => 0];
    }
}
