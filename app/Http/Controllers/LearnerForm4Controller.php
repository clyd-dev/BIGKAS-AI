<?php

namespace App\Http\Controllers;

use App\Models\Learner;
use App\Services\Form4Builder;
use App\Traits\AuthorizesLearnerAccess;
use Barryvdh\DomPDF\Facade\Pdf;

/** Phil-IRI Form 4 (Individual Summary Record): one record per learner, English and Filipino. */
class LearnerForm4Controller extends Controller
{
    use AuthorizesLearnerAccess;

    public function show(Learner $learner)
    {
        $this->authorizeLearnerAccess($learner);

        return view('learners.form4', ['learner' => $learner, 'form' => Form4Builder::build($learner)]);
    }

    public function print(Learner $learner)
    {
        $this->authorizeLearnerAccess($learner);

        return view('learners.form4-print', ['form' => Form4Builder::build($learner), 'pdf' => false]);
    }

    public function pdf(Learner $learner)
    {
        $this->authorizeLearnerAccess($learner);

        return Pdf::loadView('learners.form4-print', ['form' => Form4Builder::build($learner), 'pdf' => true])
            ->setPaper('a4', 'landscape')
            ->download('Phil-IRI-Form-4_' . str_replace(' ', '-', $learner->getFullName()) . '.pdf');
    }
}
