<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\SchoolClass;
use App\Services\LearnerMasterList;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

/**
 * Printable master list of learners' reading results, by grade and section. Read-only.
 *
 * The principal sees the whole school; a teacher sees only their own section (the "mine*" actions), and
 * the section comes from who they are, never from the request, so it cannot be widened by editing the URL.
 */
class LearnerMasterListController extends Controller
{
    // ── Principal: the whole school ──

    public function index(Request $request)
    {
        [$schoolYear, $grade, $classId, $language] = $this->selection($request);

        $schoolYears = SchoolClass::query()->distinct()->orderByDesc('school_year')->pluck('school_year');
        $classes     = SchoolClass::query()->when($schoolYear, fn ($q) => $q->where('school_year', $schoolYear))
            ->orderBy('grade_level')->orderBy('section')->get();
        $list        = LearnerMasterList::build($schoolYear, $grade, $classId, $language);

        return view('reports.master-list.index', compact('list', 'schoolYears', 'classes', 'schoolYear', 'grade', 'classId', 'language') + ['mine' => false]);
    }

    public function print(Request $request)
    {
        return view('reports.master-list.print', ['list' => $this->printable($this->selection($request)), 'pdf' => false]);
    }

    public function pdf(Request $request)
    {
        return $this->download($this->printable($this->selection($request)));
    }

    // ── Teacher: their own section only ──

    public function mine(Request $request)
    {
        [$schoolYear, $grade, $classId, $language] = $this->mySelection($request);

        $schoolYears = auth()->user()->taughtClasses()->distinct()->orderByDesc('school_year')->pluck('school_year');
        $list        = LearnerMasterList::build($schoolYear, $grade, $classId, $language);
        $classes     = collect();

        return view('reports.master-list.index', compact('list', 'schoolYears', 'classes', 'schoolYear', 'grade', 'classId', 'language') + ['mine' => true]);
    }

    public function minePrint(Request $request)
    {
        return view('reports.master-list.print', ['list' => $this->printable($this->mySelection($request)), 'pdf' => false]);
    }

    public function minePdf(Request $request)
    {
        return $this->download($this->printable($this->mySelection($request)));
    }

    // ── Shared ──

    private function download(array $list)
    {
        return Pdf::loadView('reports.master-list.print', ['list' => $list, 'pdf' => true])
            ->setPaper('a4', 'landscape')
            ->download('Learner-Reading-Master-List_' . ($list['school_year'] ?: 'all-years') . '.pdf');
    }

    /** Build the sheet and record that it was produced: it lists every child by name. */
    private function printable(array $selection): array
    {
        [$schoolYear, $grade, $classId, $language] = $selection;
        abort_unless($schoolYear, 404);

        $list = LearnerMasterList::build($schoolYear, $grade, $classId, $language);
        ActivityLog::log(
            'print_master_list',
            "Printed the Learner Reading Master List ({$schoolYear}, " . ($grade ? "Grade {$grade}" : 'all grades') . ", {$list['totals']['learners']} learners)"
        );

        return $list;
    }

    private function selection(Request $request): array
    {
        $years      = SchoolClass::query()->distinct()->orderByDesc('school_year')->pluck('school_year');
        $schoolYear = $years->contains($request->input('school_year')) ? $request->input('school_year') : $years->first();
        $grade      = in_array((int) $request->input('grade'), range(1, 6), true) ? (int) $request->input('grade') : null;
        $classId    = $request->filled('class_id') ? (int) $request->input('class_id') : null;

        return [$schoolYear, $grade, $classId, $this->language($request)];
    }

    /** A teacher's own section for the chosen school year. Grade and section in the request are ignored. */
    private function mySelection(Request $request): array
    {
        $classes    = auth()->user()->taughtClasses()->orderByDesc('school_year')->get();
        $schoolYear = $classes->pluck('school_year')->contains($request->input('school_year'))
            ? $request->input('school_year') : $classes->first()?->school_year;
        $class      = $classes->firstWhere('school_year', $schoolYear);

        // Always pinned to one section id. A teacher with none gets 0, which matches nothing; it never widens to the school.
        return [$schoolYear, null, $class?->id ?? 0, $this->language($request)];
    }

    private function language(Request $request): string
    {
        return array_key_exists((string) $request->input('language'), LearnerMasterList::LANGUAGES) ? $request->input('language') : 'all';
    }
}
