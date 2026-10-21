<?php

namespace App\Http\Controllers;

use App\Traits\AuthorizesLearnerAccess;

/**
 * Batch assessment. Only create() exists yet; the other actions are declared so
 * the routes resolve, and answer 501 until the tasks that own them land.
 */
class AssessmentBatchController extends Controller
{
    use AuthorizesLearnerAccess;

    public function create()
    {
        return view('assessments.batch.create');
    }

    /** Routed but not built: store, board, poll, entry, uploadAudio, answers, send, sendAll, cancel. */
    public function __call($method, $parameters)
    {
        abort(501, 'Batch assessment: this action is not built yet.');
    }
}
