<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\ClassReport;
use App\Models\SchoolClass;
use Illuminate\Http\Request;

/**
 * Teacher → principal reporting. Teachers submit a snapshot of their section's
 * reading profile; the principal (admin) reviews it or returns it with comments.
 */
class ClassReportController extends Controller
{
    /** Admin: inbox of every section (submitted or not). Teacher: own submissions. */
    public function index(Request $request)
    {
        $user = auth()->user();

        if ($user->isAdmin()) {
            return $this->adminInbox($request);
        }

        $reports = ClassReport::with('schoolClass')
            ->where('teacher_id', $user->id)
            ->latest('submitted_at')
            ->paginate(10)
            ->withQueryString();

        return view('reports.submissions.teacher-index', compact('reports'));
    }

    private function adminInbox(Request $request)
    {
        $period = $request->input('period', 'pre_test');
        abort_unless(array_key_exists($period, ClassReport::PERIODS), 404);

        $schoolYears = SchoolClass::query()->distinct()->orderByDesc('school_year')->pluck('school_year');
        $schoolYear  = $request->input('school_year', $schoolYears->first());

        $query = SchoolClass::with([
                'teacher',
                'reports' => fn ($q) => $q->where('period', $period)->latest('submitted_at'),
            ])
            ->when($schoolYear, fn ($q) => $q->where('school_year', $schoolYear))
            ->orderBy('grade_level')->orderBy('section');

        $status = $request->input('status');
        if ($status === 'not_submitted') {
            $query->whereDoesntHave('reports', fn ($q) => $q->where('period', $period));
        } elseif (in_array($status, [ClassReport::STATUS_SUBMITTED, ClassReport::STATUS_REVIEWED, ClassReport::STATUS_RETURNED], true)) {
            $query->whereHas('reports', fn ($q) => $q->where('period', $period)->where('status', $status));
        }

        $classes = $query->paginate(10)->withQueryString();
        $periods = ClassReport::PERIODS;

        return view('reports.submissions.admin-index', compact('classes', 'periods', 'period', 'schoolYears', 'schoolYear', 'status'));
    }

    /** Teacher: preview + submit form for their own section. */
    public function create()
    {
        $class    = $this->teacherClass();
        $period   = array_key_exists((string) request('period'), ClassReport::PERIODS) ? request('period') : 'pre_test';
        $snapshot = ClassReport::buildSnapshot($class, $period);
        $periods  = ClassReport::PERIODS;

        return view('reports.submissions.create', compact('class', 'snapshot', 'periods', 'period'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'period'       => 'required|in:' . implode(',', array_keys(ClassReport::PERIODS)),
            'teacher_note' => 'nullable|string|max:2000',
        ]);

        $class = $this->teacherClass();

        $open = ClassReport::where('class_id', $class->id)
            ->where('school_year', $class->school_year)
            ->where('period', $data['period'])
            ->where('status', '!=', ClassReport::STATUS_RETURNED)
            ->exists();

        if ($open) {
            return back()->withInput()->with('error', 'A report for this period was already submitted. You can resubmit only if the principal returns it.');
        }

        $report = ClassReport::create([
            'class_id'     => $class->id,
            'teacher_id'   => auth()->id(),
            'school_year'  => $class->school_year,
            'period'       => $data['period'],
            'status'       => ClassReport::STATUS_SUBMITTED,
            'snapshot'     => ClassReport::buildSnapshot($class, $data['period']),
            'teacher_note' => $data['teacher_note'] ?? null,
            'submitted_at' => now(),
        ]);

        ActivityLog::log('submit_class_report', "Submitted {$report->periodLabel()} report for Grade {$class->grade_level} - {$class->section}", 'class_report', $report->id);

        return redirect()->route('reports.submissions.show', $report)
            ->with('success', 'Report submitted to the principal.');
    }

    public function show(ClassReport $classReport)
    {
        $user = auth()->user();
        abort_unless($user->isAdmin() || $classReport->teacher_id === $user->id, 403);

        $classReport->load(['schoolClass', 'teacher', 'reviewer']);

        return view('reports.submissions.show', ['report' => $classReport]);
    }

    /** Admin: mark reviewed, or return to the teacher with a comment. */
    public function review(Request $request, ClassReport $classReport)
    {
        $data = $request->validate([
            'action'            => 'required|in:reviewed,returned',
            'principal_comment' => 'nullable|string|max:2000|required_if:action,returned',
        ]);

        $classReport->update([
            'status'            => $data['action'],
            'principal_comment' => $data['principal_comment'] ?? null,
            'reviewed_at'       => now(),
            'reviewed_by'       => auth()->id(),
        ]);

        ActivityLog::log('review_class_report', "Marked class report #{$classReport->id} as {$data['action']}", 'class_report', $classReport->id);

        return redirect()->route('reports.submissions.show', $classReport)
            ->with('success', $data['action'] === 'reviewed' ? 'Report marked as reviewed.' : 'Report returned to the teacher.');
    }

    private function teacherClass(): SchoolClass
    {
        $class = auth()->user()->taughtClasses()->first();
        abort_if(! $class, 403, 'You are not assigned to a class/section yet.');

        return $class;
    }
}
