<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\SchoolClass;
use App\Services\LearnerMasterList;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

/** Principal's printable master list of every learner's reading results, by grade and section. Read-only. */
class LearnerMasterListController extends Controller
{
    public function index(Request $request)
    {
        [$schoolYear, $grade, $classId, $language] = $this->selection($request);

        $schoolYears = SchoolClass::query()->distinct()->orderByDesc('school_year')->pluck('school_year');
        $classes     = SchoolClass::query()->when($schoolYear, fn ($q) => $q->where('school_year', $schoolYear))
            ->orderBy('grade_level')->orderBy('section')->get();
        $list        = LearnerMasterList::build($schoolYear, $grade, $classId, $language);

        return view('reports.master-list.index', compact('list', 'schoolYears', 'classes', 'schoolYear', 'grade', 'classId', 'language'));
    }

    public function print(Request $request)
    {
        $list = $this->printable($request);

        return view('reports.master-list.print', ['list' => $list, 'pdf' => false]);
    }

    public function pdf(Request $request)
    {
        $list = $this->printable($request);

        return Pdf::loadView('reports.master-list.print', ['list' => $list, 'pdf' => true])
            ->setPaper('a4', 'landscape')
            ->download('Learner-Reading-Master-List_' . ($list['school_year'] ?: 'all-years') . '.pdf');
    }

    /** Build the sheet and record that it was produced: it lists every child by name. */
    private function printable(Request $request): array
    {
        [$schoolYear, $grade, $classId, $language] = $this->selection($request);
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
        $years     = SchoolClass::query()->distinct()->orderByDesc('school_year')->pluck('school_year');
        $schoolYear = $years->contains($request->input('school_year')) ? $request->input('school_year') : $years->first();
        $grade     = in_array((int) $request->input('grade'), range(1, 6), true) ? (int) $request->input('grade') : null;
        $classId   = $request->filled('class_id') ? (int) $request->input('class_id') : null;
        $language  = array_key_exists((string) $request->input('language'), LearnerMasterList::LANGUAGES) ? $request->input('language') : 'all';

        return [$schoolYear, $grade, $classId, $language];
    }
}
