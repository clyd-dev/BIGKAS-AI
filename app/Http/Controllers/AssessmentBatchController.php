<?php

namespace App\Http\Controllers;

use App\Models\Assessment;
use App\Models\AssessmentBatch;
use App\Models\AssessmentSession;
use App\Services\AnalysisQueueEstimator;
use App\Services\BatchEntryState;
use App\Services\BatchRosterBuilder;
use App\Services\ComprehensionService;
use App\Support\Directory;
use App\Traits\AuthorizesLearnerAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

    // Declared, not built: cancel() still answers 501 and belongs to Task 11.
    // Everything else on this controller is built.

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

    /**
     * Conducting a child's reading is the batch owner's own work.
     *
     * Unlike authorizeBatch(), which lets an admin look on at a board, nothing
     * in the panel is reachable by anyone but the teacher who owns the batch:
     * this is the screen that records, answers for and sends a child's reading.
     * Admins browse, teachers assess. The routes already bar admins outright;
     * this says the same rule in the controller, so it cannot be lost to a
     * route edit.
     */
    private function authorizeConduct(AssessmentBatch $batch): void
    {
        abort_unless((int) auth()->id() === (int) $batch->teacher_id, 403, 'This batch is not yours.');
    }

    /**
     * The one entry this panel is about, or an abort.
     *
     * Three separate things have to hold: the batch is this teacher's, the
     * child is actually in this batch (a URL pairing any entry with any batch
     * is not a reading), and the child is still one this teacher can reach.
     */
    private function panelEntry(AssessmentBatch $batch, Assessment $assessment): Assessment
    {
        $this->authorizeConduct($batch);

        abort_unless((int) $assessment->batch_id === (int) $batch->getKey(), 404, 'This child is not in this batch.');
        abort_unless($assessment->learner !== null, 404, 'This child is no longer on the roster.');

        $this->authorizeLearnerAccess($assessment->learner);

        return $assessment;
    }

    /**
     * Commit one recording to the scoring lane.
     *
     * Assessment::scopeAwaitingRescore() claims a batch entry the moment
     * submitted_for_analysis_at is set, so this single column is the whole act
     * of sending. Already sent is left exactly as it was: re-stamping would
     * move a child back to the end of a queue they are already in.
     */
    private function stamp(Assessment $entry): void
    {
        if ($entry->submitted_for_analysis_at !== null) {
            return;
        }

        $entry->forceFill(['submitted_for_analysis_at' => now()])->save();
    }

    /** The entry as the database now has it, with everything a state reads. */
    private function freshEntry(Assessment $entry): Assessment
    {
        return $entry->fresh(['learner', 'material', 'result', 'verdict']);
    }

    /**
     * Save a browser recording against this entry, replacing any earlier one.
     *
     * The extension allow-list is AssessmentController::keepRecording()'s,
     * duplicated rather than shared: that controller is the single-child flow
     * and stays behaviourally untouched, exactly as BatchRosterBuilder already
     * duplicates its validation rules. The reason for the list is the same one
     * either way - storage/app/public/assessments/audio is served publicly, so
     * a client-supplied name like "recording.php" must never be written as
     * given.
     */
    private function keepRecording(Assessment $entry, \Illuminate\Http\UploadedFile $file): void
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $extension = in_array($extension, ['webm', 'weba', 'ogg', 'oga', 'wav', 'mp3', 'm4a', 'mp4'], true)
            ? $extension
            : 'webm';

        $path = $file->storeAs(
            'assessments/audio',
            "assessment_{$entry->getKey()}_" . time() . '_' . Str::random(6) . ".{$extension}",
            'public'
        );

        $previous = $entry->audio_file;

        // A new recording is a new attempt: a child who reads again after a
        // failed scoring run must not stay `failed`, and must not be claimed by
        // the lane on the strength of the old attempt while this one rests
        // unsent.
        $entry->forceFill([
            'audio_file' => $path,
            'status' => Assessment::STATUS_PENDING,
            'submitted_for_analysis_at' => null,
            'analysis_started_at' => null,
            'analysis_duration_ms' => null,
        ])->save();

        if ($previous && $previous !== $path) {
            Storage::disk('public')->delete($previous);
        }
    }

    /**
     * One child's panel: the passage, the recorder, the join code, the player
     * and - when the type needs them - the comprehension questions.
     *
     * The card's chip and its one action are rendered by the board's own
     * _card.blade.php, included here rather than re-written, so the panel can
     * never say something different about a child than the board does.
     */
    public function entry(AssessmentBatch $batch, Assessment $assessment)
    {
        $entry = $this->panelEntry($batch, $assessment);
        $entry->load(['learner', 'material', 'result', 'verdict', 'comprehensionAnswers']);

        // needsComprehensionTest() reads the entry's own material, so the
        // questions are taken from there too: asking a different material's
        // questions than the gate checks for would stall the Send button
        // against questions nobody could answer. The passage may still fall
        // back to the batch default, which is only for display.
        $questions = $entry->needsComprehensionTest()
            ? $entry->material->comprehensionQuestions()->orderBy('sort_order')->get()
            : collect();

        return view('assessments.batch.entry', [
            'batch' => $batch,
            'entry' => $entry,
            'state' => BatchEntryState::for($entry),
            'material' => $entry->material ?? $batch->defaultMaterial,
            'questions' => $questions,
            'answers' => $entry->comprehensionAnswers,
            // Read, never issued: Task 10 owns giving every batch entry a
            // session. Until then this is simply null and the panel says so.
            'session' => AssessmentSession::where('assessment_id', $entry->getKey())->latest('id')->first(),
        ]);
    }

    /**
     * Store the recording and leave it resting at `recorded`.
     *
     * Nothing is sent. The scoring lane runs strictly one recording at a time,
     * so lane time is only ever committed by the teacher pressing Send - after
     * she has listened to what the child actually read.
     */
    public function uploadAudio(Request $request, AssessmentBatch $batch, Assessment $assessment)
    {
        $entry = $this->panelEntry($batch, $assessment);

        // A measured reading is never quietly replaced underneath its own
        // numbers. Follow BatchEntryState's derivation order with a result in
        // place: the card can never read `recorded` again (that branch requires
        // no result), so it would go on showing a reading level and an accuracy
        // figure belonging to a recording that is no longer on disk - the same
        // family of dishonesty the whole board was built to prevent.
        //
        // So the re-record is refused, and the result is left strictly alone:
        // no action on this board may destroy a measured reading, and deleting
        // one cascades into the teacher's verdict and the child's answers and
        // silently recomputes the learner's reading level.
        if ($entry->result !== null) {
            return response()->json([
                'success' => false,
                'message' => 'This reading has already been analysed, so it cannot be recorded over. '
                    . 'Open the result to analyse the saved recording again, or take this child out of the batch '
                    . 'and start a new reading for them.',
            ], 422);
        }

        $request->validate(['audio' => 'required|file|max:25600']);

        $this->keepRecording($entry, $request->file('audio'));

        return response()->json([
            'success' => true,
            'state' => BatchEntryState::for($this->freshEntry($entry)),
        ]);
    }

    /**
     * Record the comprehension answers for this child.
     *
     * Through ComprehensionService::record() and nothing else, so a score
     * cannot be computed two different ways (see that class). An incomplete set
     * is refused by the service with a 422, and nothing is stored.
     */
    public function answers(Request $request, AssessmentBatch $batch, Assessment $assessment)
    {
        $entry = $this->panelEntry($batch, $assessment);

        $request->validate(['answers' => 'required|array']);

        $score = app(ComprehensionService::class)->record($entry, (array) $request->input('answers', []));

        return response()->json([
            'success' => true,
            'comprehension_score' => $score,
            'state' => BatchEntryState::for($this->freshEntry($entry)),
        ]);
    }

    /**
     * Commit this one recording to the scoring lane.
     *
     * The Send gate, spec 4.3: any answers posted with the Send reach
     * ComprehensionService::record() *before* submitted_for_analysis_at is
     * stamped, exactly as AssessmentController::analyze() does - so a dead
     * speech engine can never discard a child's answers. And a comprehension
     * entry with no answers at all is refused outright, which is what makes
     * the ordering structural rather than merely intended: an unanswered entry
     * can never be in the lane.
     */
    public function send(Request $request, AssessmentBatch $batch, Assessment $assessment)
    {
        $entry = $this->panelEntry($batch, $assessment);

        if (! $entry->hasAudio()) {
            return response()->json([
                'success' => false,
                'message' => 'There is no recording to send yet. Record this child\'s reading first.',
            ], 422);
        }

        if ($entry->needsComprehensionTest()) {
            if ($request->filled('answers')) {
                // First, before anything is sent. A refusal here throws and
                // nothing below runs.
                app(ComprehensionService::class)->record($entry, (array) $request->input('answers', []));
            } elseif (! $entry->hasComprehensionAnswers()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Answer the comprehension questions before sending this reading for analysis.',
                ], 422);
            }
        }

        $this->stamp($entry);

        return response()->json([
            'success' => true,
            'state' => BatchEntryState::for($this->freshEntry($entry)),
        ]);
    }

    /**
     * Send every card that is ready, and say plainly what was not sent.
     *
     * "Ready" is decided by BatchEntryState and nothing else - the same
     * definition the board's own counter uses - so the button never promises a
     * send the gate would refuse. A `recorded` card with no action is a card
     * still missing its comprehension answers: it is skipped and counted, never
     * sent with a hole in it.
     *
     * This is the only bulk action in the batch flow. A bulk send costs lane
     * time; a verdict writes a judgement about a child into their permanent
     * record, and that is never done in bulk.
     */
    public function sendAll(Request $request, AssessmentBatch $batch)
    {
        $this->authorizeConduct($batch);

        $entries = $this->boardEntries($batch);
        $states = BatchEntryState::forAll($entries);
        $sent = 0;
        $skipped = 0;

        foreach ($entries as $entry) {
            $state = $states[$entry->getKey()];

            if ($state['chip'] !== 'recorded') {
                continue;
            }

            if ($state['action'] === null) {
                $skipped++;

                continue;
            }

            $this->stamp($entry);
            $sent++;
        }

        $message = $sent === 1
            ? '1 reading sent for analysis.'
            : "{$sent} readings sent for analysis.";

        if ($skipped > 0) {
            $message .= $skipped === 1
                ? ' 1 reading still needs its comprehension answers, so it was not sent.'
                : " {$skipped} readings still need their comprehension answers, so they were not sent.";
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'sent' => $sent,
                'skipped' => $skipped,
                'message' => $message,
            ]);
        }

        return redirect()->route('assessments.batch.board', $batch)
            ->with($sent > 0 ? 'success' : 'warning', $message);
    }

    public function cancel(Request $request, AssessmentBatch $batch)
    {
        abort(501);
    }
}
