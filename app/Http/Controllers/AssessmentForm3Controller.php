<?php

namespace App\Http\Controllers;

use App\Models\Assessment;
use App\Services\Form3Builder;
use App\Traits\AuthorizesLearnerAccess;
use Barryvdh\DomPDF\Facade\Pdf;

/** Phil-IRI Form 3A/3B (Grade Level Passage Rating Sheet) for one completed assessment. */
class AssessmentForm3Controller extends Controller
{
    use AuthorizesLearnerAccess;

    public function print(Assessment $assessment)
    {
        return view('assessments.form3-print', ['form' => $this->form($assessment), 'assessment' => $assessment, 'pdf' => false]);
    }

    public function pdf(Assessment $assessment)
    {
        $form = $this->form($assessment);

        return Pdf::loadView('assessments.form3-print', ['form' => $form, 'assessment' => $assessment, 'pdf' => true])
            ->setPaper('a4', 'portrait')
            ->download("Phil-IRI-Form-{$form['formNo']}_" . str_replace(' ', '-', $form['name']) . '.pdf');
    }

    private function form(Assessment $assessment): array
    {
        $this->authorizeLearnerAccess($assessment->learner);
        abort_unless($assessment->status === Assessment::STATUS_COMPLETED && $assessment->result()->exists(), 404);

        return Form3Builder::build($assessment);
    }
}
