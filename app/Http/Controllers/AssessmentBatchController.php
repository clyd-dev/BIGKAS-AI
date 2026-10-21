<?php

namespace App\Http\Controllers;

use App\Models\Assessment;
use App\Models\AssessmentBatch;
use App\Services\BatchRosterBuilder;
use App\Support\Directory;
use App\Traits\AuthorizesLearnerAccess;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AssessmentBatchController extends Controller
{
    use AuthorizesLearnerAccess;

    public function create()
    {
        $user = auth()->user();
        $learners = Directory::sortLearners($user->accessibleLearnersQuery()->get());
        $classes = $user->taughtClasses()->orderBy('grade_level')->orderBy('section')->get();
        $maxLearners = (int) config('assessment.batch_max_learners');

        // Materials are loaded progressively via materials.options, as on the
        // single-child screen.
        return view('assessments.batch.create', compact('learners', 'classes', 'maxLearners'));
    }

    // Declared, not built: the routes resolve but answer 501. Task 7 owns board, Task 8 poll, Task 9 entry, uploadAudio and answers, Task 10 send and
    // sendAll, Task 11 cancel. Each task replaces its own body.

    public function store(Request $request)
    {
        // Re-index so the entries line up with the "entries.N.*" error keys, which
        // BatchRosterBuilder numbers by position; a roster row removed on the form
        // would otherwise leave a gap and put a message beside the wrong child.
        $input = $request->all();
        $input['entries'] = array_values(array_filter((array) $request->input('entries', []), 'is_array'));

        try {
            $batch = app(BatchRosterBuilder::class)->build($request->user(), [
                'title' => filled($input['title'] ?? null) ? $input['title'] : null,
                'language' => $input['language'] ?? null,
                'assessment_type' => $input['assessment_type'] ?? null,
                'default_material_id' => filled($input['default_material_id'] ?? null) ? (int) $input['default_material_id'] : null,
                'school_class_id' => filled($input['school_class_id'] ?? null) ? (int) $input['school_class_id'] : null,
                'entries' => array_map(fn ($e) => [
                    'learner_id' => filled($e['learner_id'] ?? null) ? (int) $e['learner_id'] : null,
                    'material_id' => filled($e['material_id'] ?? null) ? (int) $e['material_id'] : null,
                    // A plain checkbox: absent when unticked, "on" when ticked.
                    'allow_fallback' => filter_var($e['allow_fallback'] ?? false, FILTER_VALIDATE_BOOLEAN),
                ], $input['entries']),
            ]);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput($input);
        }

        return redirect()->route('assessments.batch.board', $batch);
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
