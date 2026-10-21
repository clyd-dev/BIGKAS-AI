<?php

namespace App\Http\Controllers;

use App\Models\Assessment;
use App\Models\AssessmentBatch;
use App\Traits\AuthorizesLearnerAccess;
use Illuminate\Http\Request;

class AssessmentBatchController extends Controller
{
    use AuthorizesLearnerAccess;

    public function create()
    {
        return view('assessments.batch.create');
    }

    // Declared, not built: the routes resolve but answer 501. Task 7 owns store and
    // board, Task 8 poll, Task 9 entry, uploadAudio and answers, Task 10 send and
    // sendAll, Task 11 cancel. Each task replaces its own body.

    public function store(Request $request)
    {
        abort(501);
    }

    public function board(AssessmentBatch $batch)
    {
        abort(501);
    }

    public function poll(AssessmentBatch $batch)
    {
        abort(501);
    }

    public function entry(AssessmentBatch $batch, Assessment $assessment)
    {
        abort(501);
    }

    public function uploadAudio(Request $request, AssessmentBatch $batch, Assessment $assessment)
    {
        abort(501);
    }

    public function answers(Request $request, AssessmentBatch $batch, Assessment $assessment)
    {
        abort(501);
    }

    public function send(Request $request, AssessmentBatch $batch, Assessment $assessment)
    {
        abort(501);
    }

    public function sendAll(Request $request, AssessmentBatch $batch)
    {
        abort(501);
    }

    public function cancel(Request $request, AssessmentBatch $batch)
    {
        abort(501);
    }
}
