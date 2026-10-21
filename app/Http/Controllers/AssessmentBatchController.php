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
            'joinCodes' => $this->joinCodes($entries),
            // The confirmation names what cancelling would keep and stop, and
            // the counts come from BatchEntryState - the same states the cards
            // are drawn from, so the dialog cannot promise something the board
            // does not show.
            'cancelConsequences' => $this->cancelConsequences($this->cancelPlan($states)),
        ]);
    }

    /**
     * entry id => that child's join code, in one query for the whole roster.
     *
     * The card used to look its own code up, which cost a query per card on a
     * board that is otherwise a single lane query. Ordered by id and keyed by
     * assessment, so the newest session wins - the same code the panel shows.
     *
     * @return \Illuminate\Support\Collection<int, string>
     */
    private function joinCodes($entries)
    {
        if ($entries->isEmpty()) {
            return collect();
        }

        return AssessmentSession::whereIn('assessment_id', $entries->modelKeys())
            ->orderBy('id')
            ->pluck('session_code', 'assessment_id');
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
     * One rule, one place: a measured reading cannot be changed from this panel.
     *
     * A result was worked out from one particular recording and one particular
     * set of comprehension answers. Replace either of them and the result goes
     * on reporting figures that the rows beside it no longer say - a reading
     * level and an accuracy for audio that is gone, or a comprehension score
     * that contradicts the stored answers. Those are measurements about a
     * child which someone may act on, and a teacher reading the results screen
     * has no way to tell which half is true.
     *
     * Refused, never recomputed and never deleted: rewriting or destroying a
     * measured reading is the one thing no action on this board may do.
     *
     * This is a single guard rather than a copy per endpoint because the panel
     * has three doors onto the same reading - uploadAudio(), answers() and
     * send(), which also records answers - and a rule written out three times
     * is a rule that will hold in two places. sendAll() needs no call: it only
     * ever stamps a card whose chip is `recorded`, and that chip requires there
     * to be no result, so it cannot reach an analysed entry at all.
     *
     * @return \Illuminate\Http\JsonResponse|null  the refusal, or null when there is nothing to refuse
     */
    private function refuseIfAnalysed(Assessment $entry)
    {
        if ($entry->result === null) {
            return null;
        }

        return response()->json([
            'success' => false,
            'message' => 'This reading has already been analysed, so it cannot be changed from here - its '
                . 'recording and its comprehension answers are what the result was worked out from. '
                . 'Open the result to analyse the saved recording again, or take this child out of the batch '
                . 'and start a new reading for them.',
        ], 422);
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
            // Issued with the batch by BatchRosterBuilder, one per entry; only
            // a batch made before join codes existed has none, and the panel says so.
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

        if ($refusal = $this->refuseIfCancelled($request, $batch)) {
            return $refusal;
        }

        // A measured reading is never quietly replaced underneath its own
        // numbers. Follow BatchEntryState's derivation order with a result in
        // place: the card can never read `recorded` again (that branch requires
        // no result), so it would go on showing a reading level and an accuracy
        // figure belonging to a recording that is no longer on disk. Refused
        // before anything is validated or written - see refuseIfAnalysed().
        if ($refusal = $this->refuseIfAnalysed($entry)) {
            return $refusal;
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
     *
     * Refused once a result exists, for the same reason a re-record is:
     * record() replaces the previous attempt, but the comprehension score
     * already written into the AssessmentResult is not recomputed - so new
     * answers would leave the result reporting a figure that no longer matches
     * the answer rows stored beside it, and the results screen would show one
     * number while the answers say another. That stale number is a measurement
     * about a child which someone may act on.
     *
     * Refused, not recomputed: recomputing means rewriting a measured result in
     * place, and no action on this board may destroy or silently alter a
     * measured reading.
     *
     * The gate is strictly "a result exists", never submitted_for_analysis_at.
     * An entry waiting in the lane has no result to contradict - the scorer
     * reads the answers when it gets to it - so changing them while it waits is
     * harmless, and sometimes exactly what a teacher needs.
     */
    public function answers(Request $request, AssessmentBatch $batch, Assessment $assessment)
    {
        $entry = $this->panelEntry($batch, $assessment);

        if ($refusal = $this->refuseIfCancelled($request, $batch)) {
            return $refusal;
        }

        if ($refusal = $this->refuseIfAnalysed($entry)) {
            return $refusal;
        }

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

        if ($refusal = $this->refuseIfCancelled($request, $batch)) {
            return $refusal;
        }

        // Send is the panel's third door onto the same reading, because it
        // records any answers posted with it. Without this guard a Send
        // carrying new answers would rewrite the answer rows of an analysed
        // reading while its result went on reporting the old score - the two
        // then actively contradicting each other. Refused before
        // ComprehensionService is touched and before anything is stamped.
        if ($refusal = $this->refuseIfAnalysed($entry)) {
            return $refusal;
        }

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

        if ($refusal = $this->refuseIfCancelled($request, $batch)) {
            return $refusal;
        }

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

        // "0 readings sent for analysis." is what a second press of the button
        // used to say, which reads like a failure rather than the plain fact
        // that there was nothing left to send.
        $message = match (true) {
            $sent === 0 && $skipped === 0 => 'Nothing was ready to send - every reading on this board has either been sent already or has no recording yet.',
            $sent === 0 => 'Nothing was sent.',
            $sent === 1 => '1 reading sent for analysis.',
            default => "{$sent} readings sent for analysis.",
        };

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

    /**
     * Take one child off this board.
     *
     * The governing rule of this whole screen lives here: **nothing available
     * on the board may destroy a measured reading.** Deleting an Assessment
     * cascade-deletes its result, its verdict, its comprehension answers and
     * its sessions, and Learner::readingLevelFromAssessments() derives the
     * child’s reading level from their assessments - so a delete would
     * silently change what parents see, what the Practice Center recommends,
     * which interventions are chosen and what the admin chart counts. One
     * misplaced tap on a card cannot be allowed to do that.
     *
     * So the two outcomes are kept strictly apart, and the destructive one is
     * reached only by an allow-list of states that provably hold no
     * measurement:
     *
     *   waiting, recorded        -> the row, its session and its audio are deleted
     *   needs verdict, done      -> batch_id = null and nothing else
     *   failed                   -> batch_id = null, recording kept so Analyze
     *                               still works from the child’s history
     *   transcribing, queued     -> refused; see below
     *
     * Deleting a measured reading for real belongs on the learner’s own
     * history screen, where it is one child at a time and in front of their
     * record - not on a card in a grid of fifteen.
     */
    public function removeEntry(Request $request, AssessmentBatch $batch, Assessment $assessment)
    {
        $entry = $this->panelEntry($batch, $assessment);

        // Re-checked at write time, not merely left un-rendered: the board the
        // teacher tapped may be a minute old and the lane may have picked this
        // reading up since.
        if ($message = $this->runningMessage($entry)) {
            return $this->refuse($request, $batch, $message);
        }

        $chip = BatchEntryState::for($entry)['chip'];
        $name = $entry->learner?->first_name ?? 'This child';

        if ($chip === 'queued') {
            return $this->refuse($request, $batch, 'This reading is in line for analysis. Cancel the analysis '
                . 'first, then take ' . $name . ' out of the batch.');
        }

        // The allow-list, with the measurement checked again on the way in:
        // deleteEntry() refuses outright if either half is untrue.
        if (in_array($chip, ['waiting', 'recorded'], true) && $entry->result === null) {
            $this->deleteEntry($entry);

            $this->restatus($batch);

            return $this->done($request, $batch, $chip === 'waiting'
                ? $name . ' was taken off this board. Nothing had been recorded.'
                : $name . ' was taken off this board, with the recording that had not been sent.');
        }

        // Everything else is detached and nothing else is touched.
        $entry->forceFill(['batch_id' => null])->save();

        $this->restatus($batch);

        return $this->done($request, $batch, $name . ' is no longer on this board. Their reading, its result '
            . 'and your verdict are all kept on their own history.');
    }

    /**
     * Un-send a reading the lane has not started yet.
     *
     * Clearing submitted_for_analysis_at is the whole act: scopeAwaitingRescore()
     * stops claiming the entry, and the card returns to `recorded` with its
     * audio intact.
     *
     * It is only honest while the reading is still waiting its turn. Once
     * Whisper is running there is no way to abort it, so the write is refused
     * rather than leaving the board saying `recorded` while a result is on its
     * way - which is why this re-checks the state instead of trusting that the
     * button was not rendered.
     */
    public function unsendEntry(Request $request, AssessmentBatch $batch, Assessment $assessment)
    {
        $entry = $this->panelEntry($batch, $assessment);

        if ($message = $this->runningMessage($entry)) {
            return $this->refuse($request, $batch, $message);
        }

        // A measured reading is never un-sent: the card would read `recorded`
        // again (BatchEntryState requires no result for that branch), so the
        // board would offer a Send over a reading that already has numbers.
        // The same one guard the panel’s three writing doors use.
        if ($refusal = $this->refuseIfAnalysed($entry)) {
            return $request->expectsJson()
                ? $refusal
                : redirect()->route('assessments.batch.board', $batch)
                    ->with('warning', $refusal->getData()->message);
        }

        $name = $entry->learner?->first_name ?? 'This child';

        if ($entry->submitted_for_analysis_at === null) {
            // A double-tapped Cancel analysis: say so and write nothing.
            return $this->done($request, $batch, 'This reading was already waiting with you, not in the lane.');
        }

        $entry->forceFill(['submitted_for_analysis_at' => null])->save();

        return $this->done($request, $batch, $name . '’s reading was taken out of the lane. The recording is '
            . 'still here - send it again when you are ready.');
    }

    /**
     * Stop this batch.
     *
     * A cancelled batch is the record of what was accomplished before the
     * teacher stopped, so every scored reading stays exactly where it is,
     * batch_id included. Only two things change: entries that were never
     * recorded are deleted with their sessions, and queued entries are
     * un-sent.
     *
     * The status is written **first**. Un-sending an entry saves an Assessment,
     * which fires AssessmentObserver -> AssessmentBatch::refreshStatus(); that
     * recomputes a batch’s status from its entries, and `cancelled` is terminal
     * and set by a person, so refreshStatus() returns early on it. Writing the
     * status first is what makes that early return cover the whole of this
     * method.
     */
    public function cancel(Request $request, AssessmentBatch $batch)
    {
        $this->authorizeConduct($batch);

        if ($batch->status === AssessmentBatch::STATUS_CANCELLED) {
            return $this->refuse($request, $batch, 'This batch was already cancelled - nothing further was changed.');
        }

        // Every entry, not boardEntries(): a child who has moved out of this
        // teacher’s classes drops off the board but must still be taken out of
        // the lane, or the batch she stopped goes on being scored.
        $entries = $batch->entries()->with(['learner', 'material', 'result', 'verdict'])->orderBy('id')->get();
        $plan = $this->cancelPlan(BatchEntryState::forAll($entries));

        $batch->forceFill([
            'status' => AssessmentBatch::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ])->save();

        foreach ($entries as $entry) {
            // Read per entry rather than from the pre-computed plan: these are
            // the states as they are now, at the moment of the write.
            $chip = BatchEntryState::for($entry)['chip'];

            if ($chip === 'waiting' && $entry->result === null) {
                $this->deleteEntry($entry);

                continue;
            }

            if ($chip === 'queued') {
                $entry->forceFill(['submitted_for_analysis_at' => null])->save();
            }

            // recorded, transcribing, failed, needs verdict and done are all
            // left exactly as they are.
        }

        return redirect()->route('assessments.batch.board', $batch)
            ->with('success', 'This batch was cancelled. ' . $this->cancelConsequences($plan, past: true));
    }

    // ── The parts the three actions share ─────────────────────────

    /**
     * Why this reading cannot be touched right now, or null.
     *
     * Read from the two timing columns rather than from the chip: these are
     * what say a Whisper run is in progress, and they are the reason
     * `transcribing` offers no button at all. A button that is merely absent
     * from a page rendered a minute ago is not a guard.
     */
    private function runningMessage(Assessment $entry): ?string
    {
        if ($entry->analysis_started_at === null || $entry->analysis_duration_ms !== null) {
            return null;
        }

        return 'This reading is being analysed now and that cannot be stopped - the recording is already '
            . 'being listened to. Wait for the result; you can take this child out of the batch afterwards.';
    }

    /**
     * Delete one entry outright: the row, its session and its recording.
     *
     * The only destructive path on this board, and it refuses to run on
     * anything measured. That check is here rather than only at the call site
     * on purpose: this is the one method whose mistakes cannot be undone, so it
     * will not destroy a result even if a caller asks it to.
     */
    private function deleteEntry(Assessment $entry): void
    {
        if ($entry->result !== null || $entry->verdict !== null) {
            throw new \LogicException('A measured reading is never deleted from the batch board.');
        }

        $audio = $entry->audio_file;

        // Deleted explicitly rather than left to the foreign key, so this holds
        // the same on every engine the app runs on.
        AssessmentSession::where('assessment_id', $entry->getKey())->delete();
        $entry->delete();

        if ($audio) {
            Storage::disk('public')->delete($audio);
        }
    }

    /**
     * Keep the batch’s own status true after an entry leaves it.
     *
     * AssessmentObserver cannot do this one: a detached entry saves with
     * batch_id already null, and a deleted entry fires no saved event at all,
     * so neither reaches the batch. Found fresh, per R24, and a cancelled batch
     * is left alone by refreshStatus() itself.
     */
    private function restatus(AssessmentBatch $batch): void
    {
        AssessmentBatch::find($batch->getKey())?->refreshStatus();
    }

    /** How many children a cancel would keep, and how many it would stop. */
    private function cancelPlan($states): array
    {
        $chips = collect($states)->map(fn (array $state) => $state['chip']);
        $count = fn (array $wanted) => $chips->filter(fn (string $chip) => in_array($chip, $wanted, true))->count();

        return [
            'assessed' => $count(['done', 'needs verdict']),
            'waiting' => $count(['waiting']),
            'queued' => $count(['queued']),
            'running' => $count(['transcribing']),
            'recorded' => $count(['recorded']),
        ];
    }

    /**
     * What cancelling this batch actually does, in children rather than states.
     *
     * The confirmation names consequences instead of asking "are you sure?",
     * and the flash afterwards is the same sentence in the past tense, from the
     * same counts - so what the teacher agreed to and what she is told happened
     * cannot drift apart.
     */
    private function cancelConsequences(array $plan, bool $past = false): string
    {
        $parts = [];

        $parts[] = match ($plan['assessed']) {
            0 => 'No child on this board has been assessed yet.',
            1 => '1 child already assessed keeps their results.',
            default => $plan['assessed'] . ' children already assessed keep their results.',
        };

        $stopped = $plan['waiting'] + $plan['queued'];

        if ($stopped > 0) {
            $parts[] = $plan['waiting'] . ' waiting and ' . $plan['queued'] . ' queued '
                . ($past ? 'were stopped.' : 'will be stopped.');
        } else {
            $parts[] = $past ? 'Nothing was left to stop.' : 'Nothing is left to stop.';
        }

        if ($plan['recorded'] > 0) {
            $parts[] = $plan['recorded'] . ' ' . ($plan['recorded'] === 1 ? 'recording' : 'recordings')
                . ' already made ' . ($plan['recorded'] === 1 ? 'is' : 'are') . ' kept, unsent.';
        }

        if ($plan['running'] > 0) {
            $parts[] = $plan['running'] . ' being analysed right now will finish - that cannot be stopped.';
        }

        return implode(' ', $parts);
    }

    /** A refusal: the JSON the panel’s fetch expects, or the board with a warning. */
    private function refuse(Request $request, AssessmentBatch $batch, string $message)
    {
        if ($request->expectsJson()) {
            return response()->json(['success' => false, 'message' => $message], 422);
        }

        return redirect()->route('assessments.batch.board', $batch)->with('warning', $message);
    }

    /** The same, for an action that did what it said. */
    private function done(Request $request, AssessmentBatch $batch, string $message)
    {
        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'message' => $message]);
        }

        return redirect()->route('assessments.batch.board', $batch)->with('success', $message);
    }

    /**
     * A batch the teacher stopped takes nothing further in.
     *
     * The teacher-side half of StudentAssessmentController::batchClosed(): the
     * child’s door already refuses a cancelled batch, and a board that went on
     * accepting recordings and sends from the teacher would make the cancel a
     * statement about the student portal rather than about the batch.
     *
     * Separate from refuseIfAnalysed(), which is about one measured reading;
     * this is about the whole batch being closed.
     */
    private function refuseIfCancelled(Request $request, AssessmentBatch $batch)
    {
        if ($batch->status !== AssessmentBatch::STATUS_CANCELLED) {
            return null;
        }

        return $this->refuse($request, $batch, 'This batch was cancelled, so nothing more can be recorded or '
            . 'sent from it. Start a new batch for any child who still needs a reading.');
    }
}
