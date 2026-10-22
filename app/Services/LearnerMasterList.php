<?php

namespace App\Services;

use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\Learner;
use App\Models\School;
use App\Models\SchoolClass;
use App\Support\Directory;
use Illuminate\Support\Collection;

/**
 * The principal's printable Learner Reading Master List: every learner, grouped by grade and section,
 * with their latest oral-reading and comprehension levels, scores and overall progress.
 *
 * Read-only. Levels follow the fixed DepEd Phil-IRI rule (PhilIriLevels). Only readings measured from the
 * child count, the same standard as Form 4. Names are stored encrypted, so grouping and sorting happen in PHP.
 */
class LearnerMasterList
{
    public const LANGUAGES = ['en' => 'English', 'fil' => 'Filipino'];

    private const RANK = [
        PhilIriLevels::FRUSTRATION   => 1,
        PhilIriLevels::INSTRUCTIONAL => 2,
        PhilIriLevels::INDEPENDENT   => 3,
    ];

    /**
     * @param  string|null  $schoolYear  e.g. "2025-2026"; null means every year
     * @param  int|null     $grade       1-6, or null for every grade
     * @param  int|null     $classId     one section, or null for all
     * @param  string       $language    'all', 'en' or 'fil'
     */
    public static function build(?string $schoolYear, ?int $grade = null, ?int $classId = null, string $language = 'all'): array
    {
        $languages = isset(self::LANGUAGES[$language]) ? [$language => self::LANGUAGES[$language]] : self::LANGUAGES;

        $classes = SchoolClass::with('teacher')
            ->when($schoolYear, fn ($q) => $q->where('school_year', $schoolYear))
            ->when($grade, fn ($q) => $q->where('grade_level', $grade))
            ->when($classId, fn ($q) => $q->where('id', $classId))
            ->orderBy('grade_level')->orderBy('section')
            ->get();

        $learners = Learner::active()
            ->where(function ($q) use ($classes, $grade, $classId) {
                $q->whereIn('class_id', $classes->pluck('id'));
                // Learners with no section still belong on the sheet (under their grade), unless one section was asked for.
                if (! $classId) {
                    $q->orWhere(fn ($u) => $u->whereNull('class_id')->when($grade, fn ($g) => $g->where('grade_level', $grade)));
                }
            })
            ->get();

        $results = $learners->isEmpty() ? collect() : AssessmentResult::measured()
            ->with('assessment:id,learner_id,language,created_at,assessed_at')
            ->whereHas('assessment', fn ($q) => $q->whereIn('learner_id', $learners->pluck('id'))->where('status', Assessment::STATUS_COMPLETED))
            ->get()
            ->filter(fn ($r) => $r->assessment !== null)
            ->groupBy(fn ($r) => $r->assessment->learner_id);

        $sections = [];
        foreach ($classes as $class) {
            $sections[] = self::section(
                ['label' => 'Grade ' . $class->grade_level . ' - ' . $class->section, 'grade' => $class->grade_level,
                 'section' => $class->section, 'teacher' => $class->teacher?->name, 'class_id' => $class->id],
                $learners->where('class_id', $class->id), $results, $languages
            );
        }

        $unassigned = $learners->whereNull('class_id');
        foreach ($unassigned->groupBy('grade_level')->sortKeys() as $g => $group) {
            $sections[] = self::section(
                ['label' => 'Grade ' . $g . ' - No section yet', 'grade' => (int) $g, 'section' => null, 'teacher' => null, 'class_id' => null],
                $group, $results, $languages
            );
        }

        $school = School::first();

        return [
            'school'      => [
                'name'      => $school?->name,
                'division'  => $school?->division,
                'district'  => $school?->district,
                'region'    => $school?->region,
                'principal' => $school?->principal_name,
            ],
            'school_year' => $schoolYear,
            'languages'   => $languages,
            'sections'    => $sections,
            'totals'      => [
                'learners' => collect($sections)->sum(fn ($s) => $s['summary']['learners']),
                'assessed' => collect($sections)->sum(fn ($s) => $s['summary']['assessed']),
            ],
            'generated_at' => now()->format('F j, Y g:i A'),
        ];
    }

    private static function section(array $meta, Collection $learners, Collection $results, array $languages): array
    {
        $rows = Directory::sortLearners($learners)->map(function (Learner $l) use ($results, $languages) {
            $mine = $results->get($l->id, collect());

            $perLanguage = [];
            foreach ($languages as $code => $label) {
                $perLanguage[$code] = self::languageBlock($mine->filter(fn ($r) => $r->assessment->language === $code));
            }

            return [
                'name'      => self::listedName($l),
                'lrn'       => $l->lrn,
                'languages' => $perLanguage,
                'readings'  => $mine->count(),
                'progress'  => $mine->isEmpty() ? 'not_assessed' : (SchoolReadingProgress::direction($mine) ?? 'one_reading'),
            ];
        })->values();

        return $meta + [
            'rows'    => $rows->all(),
            'summary' => [
                'learners' => $rows->count(),
                'assessed' => $rows->where('readings', '>', 0)->count(),
                'up'       => $rows->where('progress', 'up')->count(),
                'steady'   => $rows->where('progress', 'steady')->count(),
                'down'     => $rows->where('progress', 'down')->count(),
            ],
        ];
    }

    /** Latest oral-reading and comprehension result in one language, and the overall level they give together. */
    private static function languageBlock(Collection $results): ?array
    {
        if ($results->isEmpty()) {
            return null;
        }

        $latest = fn ($r) => $r->assessment->assessed_at ?? $r->assessment->created_at;
        $oral   = $results->filter(fn ($r) => $r->accuracy_rate !== null)->sortBy($latest)->last();
        $comp   = $results->filter(fn ($r) => $r->comprehension_score !== null)->sortBy($latest)->last();

        $oralPct = $oral ? (float) $oral->accuracy_rate : null;
        $compPct = $comp ? (float) $comp->comprehension_score : null;
        $oralLvl = PhilIriLevels::wordReading($oralPct);
        $compLvl = PhilIriLevels::comprehension($compPct);

        // The overall Phil-IRI level is the lower of the two, as the learner is only as ready as the weaker skill.
        $levels  = array_values(array_filter([$oralLvl, $compLvl]));
        $overall = $levels === [] ? null : collect($levels)->sortBy(fn ($l) => self::RANK[$l])->first();

        return [
            'oral_pct'  => $oralPct,
            'oral'      => $oralLvl,
            'comp_pct'  => $compPct,
            'comp'      => $compLvl,
            'overall'   => $overall,
            'wpm'       => $oral?->words_per_minute !== null ? (int) round((float) $oral->words_per_minute) : null,
            'date'      => $latest($results->sortBy($latest)->last())?->format('M j, Y'),
        ];
    }

    /** "DELA CRUZ, Juan Santos" - the way DepEd class lists print a name. */
    private static function listedName(Learner $l): string
    {
        $given = trim($l->first_name . ' ' . ($l->middle_name ?? ''));

        return mb_strtoupper((string) $l->last_name) . ', ' . $given;
    }

    public static function levelLabel(?string $level): string
    {
        return match ($level) {
            PhilIriLevels::INDEPENDENT   => 'Independent',
            PhilIriLevels::INSTRUCTIONAL => 'Instructional',
            PhilIriLevels::FRUSTRATION   => 'Frustration',
            default                      => '-',
        };
    }

    public static function progressLabel(string $progress): string
    {
        return match ($progress) {
            'up'           => 'Moved up',
            'steady'       => 'Steady',
            'down'         => 'Slipped',
            'one_reading'  => 'Only one reading',
            default        => 'Not yet assessed',
        };
    }
}
