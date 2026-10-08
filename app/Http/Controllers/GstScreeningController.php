<?php

namespace App\Http\Controllers;

use App\Support\Directory;

use App\Models\ActivityLog;
use App\Models\ClassReport;
use App\Models\GstResult;
use App\Models\SchoolClass;
use App\Services\GstScoring;
use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Phil-IRI Group Screening Test (Forms 1A/1B) score entry.
 * Teachers encode (or import) scores for their own section; the admin has a read-only view.
 */
class GstScreeningController extends Controller
{
    public function index(Request $request)
    {
        return auth()->user()->isAdmin() ? $this->adminIndex($request) : $this->teacherGrid($request);
    }

    // ── Teacher ─────────────────────────────────────────────

    private function teacherGrid(Request $request, array $overrides = [], array $unmatched = [])
    {
        $class    = $this->teacherClass();
        $period   = $this->period($request);
        $language = $this->language($request);
        $eligible = GstScoring::gradeEligible($class->grade_level);

        $learners = $eligible ? Directory::sortLearners($class->learners()->get()) : collect();

        $saved = GstResult::where('class_id', $class->id)
            ->where('school_year', $class->school_year)
            ->where('period', $period)->where('language', $language)
            ->get()->keyBy('learner_id');

        $entries = [];
        foreach ($learners as $learner) {
            $row = $overrides[$learner->id] ?? null;
            $res = $saved->get($learner->id);
            $entries[$learner->id] = $row ?? [
                'taken'       => $res ? (bool) $res->test_taken : true,
                'literal'     => $res?->literal_correct,
                'inferential' => $res?->inferential_correct,
                'critical'    => $res?->critical_correct,
            ];
        }

        $items     = GstScoring::ITEMS;
        $periods   = ClassReport::PERIODS;
        $languages = GstResult::LANGUAGES;

        return view('screening.teacher', compact('class', 'learners', 'entries', 'items', 'period', 'language', 'periods', 'languages', 'eligible', 'unmatched'));
    }

    public function store(Request $request)
    {
        $class    = $this->teacherClass();
        $period   = $this->period($request);
        $language = $this->language($request);
        abort_unless(GstScoring::gradeEligible($class->grade_level), 403, 'The screening test covers Grades 3 to 6 only.');

        $items = GstScoring::ITEMS;
        $request->validate([
            'rows'               => 'required|array',
            'rows.*.literal'     => 'nullable|integer|min:0|max:' . $items['literal'],
            'rows.*.inferential' => 'nullable|integer|min:0|max:' . $items['inferential'],
            'rows.*.critical'    => 'nullable|integer|min:0|max:' . $items['critical'],
        ], [
            'rows.*.literal.max'     => "Literal score cannot exceed {$items['literal']}.",
            'rows.*.inferential.max' => "Inferential score cannot exceed {$items['inferential']}.",
            'rows.*.critical.max'    => "Critical score cannot exceed {$items['critical']}.",
        ]);

        $learners = $class->learners()->get()->keyBy('id');
        $saved = 0;

        foreach ($request->input('rows') as $learnerId => $row) {
            $learner = $learners->get((int) $learnerId);
            if (! $learner) {
                continue; // not in this teacher's section
            }

            $taken = ($row['taken'] ?? '1') === '1';
            $score = fn ($k) => ($row[$k] ?? '') === '' ? null : (int) $row[$k];

            // Nothing entered for a learner who "took" the test: leave them unscored.
            $blank = $taken && $score('literal') === null && $score('inferential') === null && $score('critical') === null;
            if ($blank) {
                GstResult::where([
                    'learner_id' => $learner->id, 'school_year' => $class->school_year,
                    'period' => $period, 'language' => $language,
                ])->delete();
                continue;
            }

            $result = GstResult::firstOrNew([
                'learner_id' => $learner->id, 'school_year' => $class->school_year,
                'period' => $period, 'language' => $language,
            ]);
            $result->fill([
                'class_id'            => $class->id,
                'test_level'          => $learner->grade_level ?: $class->grade_level,
                'test_taken'          => $taken,
                'literal_correct'     => $score('literal'),
                'inferential_correct' => $score('inferential'),
                'critical_correct'    => $score('critical'),
                'entered_by'          => auth()->id(),
            ])->applyScoring()->save();
            $saved++;
        }

        ActivityLog::log('save_gst_scores', "Saved {$saved} screening result(s) for Grade {$class->grade_level} - {$class->section}", 'class', $class->id);

        return redirect()->route('screening.index', ['period' => $period, 'language' => $language])
            ->with('success', "Screening scores saved ({$saved} learner" . ($saved === 1 ? '' : 's') . ').');
    }

    /** Read a filled Phil-IRI Form 1A/1B workbook and pre-fill the grid (nothing is saved yet). */
    public function import(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:xlsx,xlsm|max:5120']);
        $class    = $this->teacherClass();
        $language = $this->language($request);
        $period   = $this->period($request);

        try {
            $book  = IOFactory::load($request->file('file')->getRealPath());
            $sheet = $book->getSheetByName('CLASS PROFILE') ?? $book->getSheet(0);
        } catch (\Throwable $e) {
            return back()->with('error', 'Could not read that file. Please upload the Phil-IRI Form 1A/1B Excel workbook.');
        }

        // Class Profile layout: names in B from row 10; taken? in G (English) / H (Filipino);
        // correct answers Literal/Inferential/Critical in I,K,M (English) or O,Q,S (Filipino).
        [$takenCol, $cols] = $language === 'en' ? ['G', ['I', 'K', 'M']] : ['H', ['O', 'Q', 'S']];

        $learners  = $class->learners()->get();
        $overrides = [];
        $unmatched = [];

        for ($r = 10; $r <= 200; $r++) {
            $name = trim((string) $sheet->getCell("B{$r}")->getCalculatedValue());
            if ($name === '') {
                continue;
            }

            $learner = $this->matchLearner($name, $learners);
            if (! $learner) {
                $unmatched[] = $name;
                continue;
            }

            $val = fn ($col) => ($v = $sheet->getCell("{$col}{$r}")->getCalculatedValue()) === null || $v === '' ? null : (int) $v;
            $overrides[$learner->id] = [
                'taken'       => strtoupper(trim((string) $sheet->getCell("{$takenCol}{$r}")->getCalculatedValue())) !== 'NO',
                'literal'     => $val($cols[0]),
                'inferential' => $val($cols[1]),
                'critical'    => $val($cols[2]),
            ];
        }

        $request->merge(['period' => $period, 'language' => $language]);

        return $this->teacherGrid($request, $overrides, $unmatched)
            ->with('info', count($overrides) . ' learner(s) read from the file. Review the scores below, then press Save.');
    }

    // ── Admin (read-only) ───────────────────────────────────

    private function adminIndex(Request $request)
    {
        $period   = $this->period($request);
        $language = $this->language($request);
        $schoolYears = SchoolClass::query()->distinct()->orderByDesc('school_year')->pluck('school_year');
        $schoolYear  = $request->input('school_year', $schoolYears->first());

        $classes = SchoolClass::with('teacher')
            ->whereBetween('grade_level', [GstScoring::MIN_GRADE, GstScoring::MAX_GRADE])
            ->when($schoolYear, fn ($q) => $q->where('school_year', $schoolYear))
            ->orderBy('grade_level')->orderBy('section')
            ->paginate(10)->withQueryString();

        $classes->getCollection()->transform(function (SchoolClass $c) use ($period, $language) {
            $c->gst = GstResult::summarize($c, $period)[$language];

            return $c;
        });

        $periods   = ClassReport::PERIODS;
        $languages = GstResult::LANGUAGES;

        return view('screening.admin-index', compact('classes', 'period', 'language', 'periods', 'languages', 'schoolYears', 'schoolYear'));
    }

    public function section(Request $request, SchoolClass $schoolClass)
    {
        $period   = $this->period($request);
        $language = $this->language($request);

        $learners = $schoolClass->learners()
            ->with(['gstResults' => fn ($q) => $q->where('school_year', $schoolClass->school_year)
                ->where('period', $period)->where('language', $language)])
            ->get();
        $learners = Directory::paginate(Directory::sortLearners($learners), 10);

        $summary   = GstResult::summarize($schoolClass, $period)[$language];
        $periods   = ClassReport::PERIODS;
        $languages = GstResult::LANGUAGES;

        return view('screening.section', ['class' => $schoolClass, 'learners' => $learners, 'summary' => $summary,
            'period' => $period, 'language' => $language, 'periods' => $periods, 'languages' => $languages]);
    }

    // ── Form 1A / 1B class record (print / PDF) ─────────────

    public function recordPrint(Request $request, SchoolClass $schoolClass)
    {
        return view('screening.record-print', $this->recordData($request, $schoolClass) + ['pdf' => false]);
    }

    public function recordPdf(Request $request, SchoolClass $schoolClass)
    {
        $data = $this->recordData($request, $schoolClass);

        return Pdf::loadView('screening.record-print', $data + ['pdf' => true])
            ->setPaper('a4', 'portrait')
            ->download("Phil-IRI-Form-{$data['formNo']}_Grade{$schoolClass->grade_level}-{$schoolClass->section}.pdf");
    }

    /** The class record of one section/language/period, laid out as Form 1A (Filipino) or 1B (English). */
    private function recordData(Request $request, SchoolClass $class): array
    {
        $user = auth()->user();
        abort_unless($user->isAdmin() || $class->teacher_id === $user->id, 403);

        $period   = $this->period($request);
        $language = $this->language($request);

        $results = GstResult::where('class_id', $class->id)->where('school_year', $class->school_year)
            ->where('period', $period)->where('language', $language)->get()->keyBy('learner_id');

        $learners = Directory::sortLearners($class->learners()->get());
        $groups = [
            'Male'   => $learners->where('gender', 'male')->values(),
            'Female' => $learners->where('gender', 'female')->values(),
        ];
        $other = $learners->filter(fn ($l) => ! in_array($l->gender, ['male', 'female']))->values();
        if ($other->isNotEmpty()) {
            $groups['Not specified'] = $other;
        }

        $scored = $results->where('test_taken', true)->whereNotNull('total_score');

        return [
            'class'    => $class->loadMissing(['school', 'teacher']),
            'groups'   => $groups,
            'results'  => $results,
            'language' => $language,
            'period'   => $period,
            'formNo'   => $language === 'fil' ? '1A' : '1B',
            'summary'  => [
                'tested'   => $scored->count(),
                'at_grade' => $scored->where('classification', GstScoring::AT_GRADE_LEVEL)->count(),
                'below'    => $scored->where('classification', GstScoring::NEEDS_ASSESSMENT)->count(),
            ],
        ];
    }

    // ── Helpers ─────────────────────────────────────────────

    private function teacherClass(): SchoolClass
    {
        $class = auth()->user()->taughtClasses()->first();
        abort_if(! $class, 403, 'You are not assigned to a class/section yet.');

        return $class;
    }

    private function period(Request $request): string
    {
        $p = $request->input('period', 'pre_test');

        return array_key_exists($p, ClassReport::PERIODS) ? $p : 'pre_test';
    }

    private function language(Request $request): string
    {
        $l = $request->input('language', 'fil');

        return array_key_exists($l, GstResult::LANGUAGES) ? $l : 'fil';
    }

    private function words(string $s): array
    {
        return array_values(array_filter(preg_split('/[^a-z0-9ñ]+/u', mb_strtolower($s))));
    }

    /** Match a name from the Excel sheet ("First Last" or "Last, First") to a learner of the section. */
    private function matchLearner(string $name, $learners)
    {
        $cell = $this->words($name);

        return $learners->first(function ($l) use ($cell) {
            $need = array_merge($this->words($l->first_name), $this->words($l->last_name));

            return $need && ! array_diff($need, $cell);
        });
    }
}
