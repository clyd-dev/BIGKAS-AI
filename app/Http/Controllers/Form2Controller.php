<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\ClassReport;
use App\Models\DepedSubmission;
use App\Models\SchoolClass;
use App\Services\Form2Builder;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

/** Principal's consolidation of teachers' reports into Phil-IRI Form 2, and the DepEd submission record. */
class Form2Controller extends Controller
{
    public function index(Request $request)
    {
        [$schoolYear, $period] = $this->selection($request);
        $schoolYears = SchoolClass::query()->distinct()->orderByDesc('school_year')->pluck('school_year');
        $periods     = ClassReport::PERIODS;

        $form = $schoolYear ? Form2Builder::build($schoolYear, $period) : null;

        $submissions = DepedSubmission::with('submitter')->latest('submitted_on')->latest('id')
            ->paginate(10)->withQueryString();

        return view('reports.form2.index', compact('form', 'schoolYears', 'schoolYear', 'period', 'periods', 'submissions'));
    }

    public function print(Request $request)
    {
        [$schoolYear, $period] = $this->selection($request);
        abort_unless($schoolYear, 404);

        return view('reports.form2.print', ['form' => Form2Builder::build($schoolYear, $period), 'pdf' => false]);
    }

    public function pdf(Request $request)
    {
        [$schoolYear, $period] = $this->selection($request);
        abort_unless($schoolYear, 404);

        $pdf = Pdf::loadView('reports.form2.print', ['form' => Form2Builder::build($schoolYear, $period), 'pdf' => true])
            ->setPaper('a4', 'portrait');

        return $pdf->download("Phil-IRI-Form-2_{$schoolYear}_{$period}.pdf");
    }

    /** Record that the signed Form 2 was sent to DepEd (the system cannot transmit it itself). */
    public function submit(Request $request)
    {
        [$schoolYear, $period] = $this->selection($request);
        abort_unless($schoolYear, 404);

        $data = $request->validate([
            'submitted_on' => 'required|date|before_or_equal:today',
            'reference'    => 'nullable|string|max:120',
            'notes'        => 'nullable|string|max:2000',
        ]);

        $form = Form2Builder::build($schoolYear, $period);

        $submission = DepedSubmission::create([
            'school_year'  => $schoolYear,
            'period'       => $period,
            'form_data'    => $form,
            'submitted_on' => $data['submitted_on'],
            'reference'    => $data['reference'] ?? null,
            'notes'        => $data['notes'] ?? null,
            'submitted_by' => auth()->id(),
        ]);

        ActivityLog::log('submit_deped_form2', "Recorded Form 2 ({$schoolYear}, {$period}) as submitted", 'deped_submission', $submission->id);

        return redirect()->route('reports.form2.index', ['school_year' => $schoolYear, 'period' => $period])
            ->with('success', 'Form 2 recorded as submitted.');
    }

    public function show(DepedSubmission $depedSubmission)
    {
        return view('reports.form2.print', ['form' => $depedSubmission->form_data, 'pdf' => false]);
    }

    private function selection(Request $request): array
    {
        $years  = SchoolClass::query()->distinct()->orderByDesc('school_year')->pluck('school_year');
        $year   = $request->input('school_year', $years->first());
        $period = array_key_exists((string) $request->input('period'), ClassReport::PERIODS) ? $request->input('period') : 'pre_test';

        return [$year, $period];
    }
}
