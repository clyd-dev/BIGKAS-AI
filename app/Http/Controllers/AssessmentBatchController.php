<?php

namespace App\Http\Controllers;

use App\Models\Assessment;
use App\Models\AssessmentBatch;
use App\Services\AnalysisQueueEstimator;
use App\Services\BatchEntryState;
use App\Services\BatchRosterBuilder;
use App\Support\Directory;
use App\Traits\AuthorizesLearnerAccess;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AssessmentBatchController extends Controller
{
    use AuthorizesLearnerAccess;

    public function __construct(private AnalysisQueueEstimator $estimator)
    {
    }

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

    // Declared, not built: the remaining routes resolve but answer 501. Task 9 owns entry, uploadAudio, answers, send and
    // sendAll, Task 11 cancel. Each task replaces its own body. The board renders Send all ready already, because poll()
    // must report the count, but the control is inert until sendAll() exists.

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
        $this->authorizeBatch($batch);

        $entries = $this->boardEntries($batch);
        $states = BatchEntryState::forAll($entries);

        return view('assessments.batch.board', [
            'batch' => $batch,
            'entries' => $entries,
            'states' => $states,
            'estimate' => $this->estimator->forBatch($batch),
            'readyToSend' => $this->readyToSend($states),
        ]);
    }

    /**
     * Strictly read-only. Nothing here writes: the batch's own status is kept
     * true by AssessmentObserver, so a batch that finishes while the teacher is
     * away is already correct when they come back. A status that only updated
     * while someone was looking at it would be a rendering artefact.
     */
    public function poll(AssessmentBatch $batch)
    {
        $this->authorizeBatch($batch);

        $states = BatchEntryState::forAll($this->boardEntries($batch));

        return response()->json([
            'entries' => $states,
            'batch_status' => $batch->status,
            'estimate' => $this->estimator->forBatch($batch),
            'ready_to_send' => $this->readyToSend($states),
        ]);
    }

    /** The batch is the teacher's own board; an admin may look at any of them. */
    private function authorizeBatch(AssessmentBatch $batch): void
    {
        $user = auth()->user();

        abort_unless($user->isAdmin() || $batch->teacher_id === $user->id, 403, 'This batch is not yours.');
    }

    /**
     * The entries whose child the batch's teacher can still reach.
     *
     * Scoped against the teacher who owns the batch rather than the auth guard,
     * so an admin looking on sees exactly the board the teacher sees. A child
     * who has moved to another class simply drops off the board instead of
     * crashing it.
     */
    private function boardEntries(AssessmentBatch $batch)
    {
        $teacher = $batch->teacher;

        if (! $teacher) {
            return collect();
        }

        return $batch->entries()
            ->whereIn('learner_id', $teacher->accessibleLearnersQuery()->select('learners.id'))
            ->with(['learner', 'material', 'result', 'verdict'])
            ->orderBy('id')
            ->get()
            // Same ordering as every other learner list in the app.
            ->sortBy(fn (Assessment $entry) => Directory::key($entry->learner?->last_name)
                . ' ' . Directory::key($entry->learner?->first_name))
            ->values();
    }

    /**
     * How many cards Send all ready would actually release: a recorded card
     * whose Send is offered. One still missing its comprehension answers is
     * recorded but not ready, and counting it would promise a send that the
     * lane would refuse.
     */
    private function readyToSend($states): int
    {
        return $states->filter(fn (array $state) => $state['chip'] === 'recorded' && $state['action'] !== null)->count();
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
