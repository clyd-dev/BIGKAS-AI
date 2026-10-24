<?php

namespace App\Http\Controllers;

use App\Models\Assessment;
use App\Models\AssessmentSession;
use App\Models\Learner;
use App\Support\Directory;
use App\Models\ReadingMaterial;
use App\Models\ActivityLog;
use App\Services\SpeechToTextService;
use App\Services\ReadingAnalyzerService;
use App\Services\InterventionRecommenderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Traits\AuthorizesLearnerAccess;

class AssessmentController extends Controller
{
    use AuthorizesLearnerAccess;
    public function index()
    {
        $user = auth()->user();

        if ($user->isAdmin()) {
            // Admin (principal): every assessed learner in the school with a
            // summary of activity. Assessor details live in the history page.
            $query = Learner::with('schoolClass')
                ->whereHas('assessments')
                ->withCount([
                    'assessments',
                    'assessments as completed_count' => fn ($q) => $q->where('status', Assessment::STATUS_COMPLETED),
                ])
                ->withMax('assessments as last_assessed_at', 'created_at');

            if (request()->filled('search')) {
                // Names are encrypted, so the match is made in PHP.
                $query->whereIn('learners.id', Directory::matchingLearnerIds($query, request('search')) ?: [0]);
            }
            if (request()->filled('class_id')) {
                $query->where('class_id', request('class_id'));
            }

            $learners   = Directory::paginate(Directory::sortLearners($query->get()), 10);
            $allClasses = \App\Models\SchoolClass::orderBy('grade_level')->orderBy('section')->get();

            return view('assessments.admin-index', compact('learners', 'allClasses'));
        }

        // Teacher: one row per learner who has taken at least one assessment,
        // instead of an ever-growing list of every individual assessment.
        $learnerQuery = $user->accessibleLearnersQuery()->whereHas('assessments');

        if (request()->filled('search')) {
            $s = request('search');
            $learnerQuery->whereIn('learners.id', Directory::matchingLearnerIds($learnerQuery, $s) ?: [0]);
        }
        if (request()->filled('reading_level')) {
            $learnerQuery->where('reading_level', request('reading_level'));
        }

        $learners = Directory::paginate(Directory::sortLearners($learnerQuery->withCount('assessments')->get()), 10);

        // The group assessment she has not finished - the one she is trying to
        // get back to. One query, and only while the feature is on; with the
        // flag off this page is exactly what it was. Nothing about the
        // single-child flow below changes either way.
        $openBatches = collect();

        if (config('assessment.batch_enabled')) {
            $openBatches = \App\Models\AssessmentBatch::where('teacher_id', $user->id)
                ->whereIn('status', [
                    \App\Models\AssessmentBatch::STATUS_COLLECTING,
                    \App\Models\AssessmentBatch::STATUS_ANALYZING,
                ])
                ->orderByDesc('id')
                ->get();
        }

        return view('assessments.index', compact('learners', 'openBatches'));
    }

    /**
     * Per-learner assessment history (date, assessor, summary per assessment),
     * reached from the teacher's or admin's Assessments list.
     */
    public function learnerHistory(Learner $learner)
    {
        $this->authorizeLearnerAccess($learner);

        $learner->load('schoolClass');
        $assessments = $learner->assessments()
            ->with(['assessor', 'material', 'result', 'verdict'])
            ->latest()
            ->paginate(10)
            ->withQueryString();

        return view('assessments.learner-history', compact('learner', 'assessments'));
    }

    public function create()
    {
        $user = auth()->user();
        $learners = Directory::sortLearners($user->accessibleLearnersQuery()->get());

        // Told before the child reads, not after: can the analysis service
        // classify right now? Recording is never blocked either way.
        $mlStatus = app(\App\Services\MLClassificationService::class)->serviceStatus();

        // Materials are loaded progressively via materials.options once the
        // teacher picks learner → language → assessment type.
        return view('assessments.create', compact('learners', 'mlStatus'));
    }

    public function start(Learner $learner)
    {
        $this->authorizeLearnerAccess($learner);

        $mlStatus = app(\App\Services\MLClassificationService::class)->serviceStatus();

        // Materials are loaded progressively via materials.options once the
        // teacher picks language → assessment type.
        return view('assessments.start', compact('learner', 'mlStatus'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'learner_id' => 'required|exists:learners,id',
            'material_id' => 'required|exists:reading_materials,id',
            'language' => 'required|in:en,fil',
            'assessment_type' => 'required|in:oral_reading,comprehension,combined',
            'allow_fallback' => 'nullable|boolean',
        ]);

        $learner = Learner::findOrFail($validated['learner_id']);
        $this->authorizeLearnerAccess($learner);

        $material = ReadingMaterial::active()->findOrFail($validated['material_id']);

        // Language must match the chosen material.
        if ($material->language !== $validated['language']) {
            return back()
                ->withErrors(['material_id' => 'The selected material is not in the chosen language.'])
                ->withInput();
        }

        // Grade policy: exact grade first; adjacent grades (±1) only when the
        // teacher explicitly picked a cross-grade fallback option.
        $gradeDiff = abs($material->grade_level - $learner->grade_level);
        if ($gradeDiff > 0 && ! $request->boolean('allow_fallback')) {
            return back()
                ->withErrors(['material_id' => 'This material is for a different grade level. Pick a Grade ' . $learner->grade_level . ' material or choose a cross-grade fallback option.'])
                ->withInput();
        }
        if ($gradeDiff > 1) {
            return back()
                ->withErrors(['material_id' => 'Cross-grade fallback is limited to one grade above or below the learner’s grade.'])
                ->withInput();
        }

        // Assessment type filters by comprehension-question availability.
        if (in_array($validated['assessment_type'], ['comprehension', 'combined'], true)
            && ! $material->comprehensionQuestions()->exists()) {
            return back()
                ->withErrors(['material_id' => 'Comprehension assessments require a material with comprehension questions.'])
                ->withInput();
        }

        $assessment = Assessment::create([
            'learner_id' => $validated['learner_id'],
            'material_id' => $validated['material_id'],
            'assessor_id' => auth()->id(),
            'language' => $validated['language'],
            'assessment_type' => $validated['assessment_type'],
            'status' => Assessment::STATUS_PENDING,
        ]);

        ActivityLog::log('create_assessment', "Started assessment for learner #{$request->learner_id}", 'assessment', $assessment->id);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'assessment_id' => $assessment->id,
                'material' => [
                    'title' => $material->title,
                    'content' => $material->content,
                    'word_count' => $material->word_count,
                ],
                'message' => 'Assessment created. Ready to record.',
            ]);
        }

        return redirect()->route('assessments.show', $assessment);
    }

    public function show(Assessment $assessment)
    {
        $this->authorizeLearnerAccess($assessment->learner);
        $assessment->load(['learner', 'material.comprehensionQuestions', 'comprehensionAnswers']);

        return view('assessments.show', [
            'assessment' => $assessment,
            'learner' => $assessment->learner,
            'material' => $assessment->material,
            'questions' => $assessment->needsComprehensionTest()
                ? $assessment->material->comprehensionQuestions
                : collect(),
            'submittedAnswers' => $assessment->comprehensionAnswers,
        ]);
    }

    /**
     * Destroy one reading, on the learner's own history screen.
     *
     * The only place in the application where a measured reading can be
     * destroyed. The batch board deliberately refuses to: there a teacher is
     * moving through fifteen cards and one misplaced tap would be one child's
     * record gone, so a scored card leaves that board by having batch_id set to
     * null and nothing else. Here she is in front of one child's own history,
     * one row at a time, behind a confirmation that shows the reading.
     *
     * What this costs, so the guards below read as necessary rather than
     * ceremonial: the row's result, verdict, comprehension answers and sessions
     * all go with it, and Learner::readingLevelFromAssessments() derives the
     * child's reading level from their assessments - so the delete changes what
     * the parent portal shows, what the Practice Center recommends, which
     * interventions are chosen for that child and what the admin chart counts.
     * Hence: a real confirmation, an audit line that outlives the row, and a
     * recompute rather than a stale level.
     *
     * Outside the batch feature and outside config('assessment.batch_enabled').
     */
    public function destroy(Request $request, Assessment $assessment)
    {
        $this->authorizeLearnerAccess($assessment->learner);

        // Deleting is the assessing teacher's act, per the
        // LearnerAdminReadOnlyTest precedent, and this guard is here as well as
        // on the route's teacher-only group so that it travels with the method
        // if the route is ever moved.
        //
        // Stated positively - only a teacher - rather than as a list of who is
        // turned away, so that a role added later is refused by default instead
        // of admitted by omission. It is needed because
        // authorizeLearnerAccess() admits the admin (principal) to every
        // learner by design: it is written for reading.
        abort_unless(auth()->user()->isTeacher(), 403, 'Deleting a reading is the assessing teacher’s decision.');

        $learner = $assessment->learner;
        $assessment->loadMissing(['result', 'verdict', 'material']);

        // A measured reading takes the learner's typed name, so a click cannot
        // destroy it and neither can a confirm-through.
        //
        // The gate is the result, not only the verdict. A verdict proves a
        // teacher invested attention; the *result* is the thing worth
        // protecting - it is what Learner::readingLevelFromAssessments() reads,
        // what the parent portal shows and what Practice Center recommends
        // from. A scored reading nobody has reviewed yet still moves all three,
        // so it is no less dangerous to lose than a reviewed one.
        if (($assessment->result !== null || $assessment->verdict !== null)
            && ! $this->typedNameMatches($request->input('learner_name'), $learner)) {
            return back()->withErrors([
                // The reason given is the true one - the reading is scored, so
                // it counts towards the level - and the name asked for is the
                // one the modal shows beneath the box, so the message and the
                // field cannot disagree about what to type.
                'learner_name' => 'This reading has been scored, so it counts towards '
                    . $learner->first_name . '’s reading level. To delete it, type '
                    . $learner->first_name . ' ' . $learner->last_name . ' to confirm.',
            ]);
        }

        $key = $assessment->getKey();
        $audio = $assessment->audio_file;
        $batchId = $assessment->batch_id;
        $narrative = $this->deletionNarrative($assessment, $learner);

        \Illuminate\Support\Facades\DB::transaction(function () use ($assessment, $key, $narrative, $learner) {
            // Written before the row goes, and inside the transaction so a
            // failure part-way cannot leave a log line claiming a deletion
            // that was rolled back. The subject is the learner, not the
            // assessment: an assessment id is a dangling pointer the moment
            // this commits, and the description carries the rest.
            ActivityLog::log('Assessment Deleted', $narrative, 'learner', $learner->getKey());

            // The children are deleted explicitly rather than left to the
            // foreign keys, so this holds the same on every engine the app
            // runs on - the same precedent as the batch board's deleteEntry().
            \App\Models\ComprehensionAnswer::where('assessment_id', $key)->delete();
            \App\Models\AssessmentVerdict::where('assessment_id', $key)->delete();
            \App\Models\AssessmentResult::where('assessment_id', $key)->delete();
            AssessmentSession::where('assessment_id', $key)->delete();

            $assessment->delete();
        });

        // The level must never reflect a reading that is gone. Same mechanism
        // as learners:recompute-levels - Learner::updateReadingLevel() over
        // readingLevelFromAssessments() - so the rule has one definition and
        // cannot drift between the command and this screen. When no usable
        // reading is left the level is cleared rather than kept.
        //
        // Before the file delete, deliberately. These are both after the
        // commit, so order decides which survives a failure in the other: a
        // disk error must not be able to leave the child's level describing a
        // reading that no longer exists. An orphaned audio file is untidy; a
        // stale reading level is what the parent sees.
        $learner->updateReadingLevel();

        // A deleted file is the one thing a rollback cannot bring back, so it
        // goes last. Left behind it would be a child's voice that nothing on
        // record refers to any more.
        if ($audio) {
            Storage::disk('public')->delete($audio);
        }

        // A reading still sitting on a batch board leaves a board deriving its
        // status from a card that no longer exists. A delete fires no saved
        // event, so AssessmentObserver cannot do this one.
        if ($batchId) {
            \App\Models\AssessmentBatch::find($batchId)?->refreshStatus();
        }

        return redirect()->route('assessments.learner-history', $learner)
            ->with('success', 'That reading was deleted, with its result, any verdict over it and its '
                . 'comprehension answers. ' . $learner->getFullName() . '’s reading level is now '
                . ($learner->fresh()->reading_level
                    ? ucfirst($learner->fresh()->reading_level)
                    : 'not set, because no reading on record supports one') . '.');
    }

    /**
     * Does what the teacher typed name this child?
     *
     * Forgiving about what hands do to a name that is on the screen in front of
     * them - leading and trailing space, doubled spaces, case, and the full
     * stop or apostrophe in "Juan D. Cruz" or "O’Brien" - and forgiving about
     * the middle initial, since the heading shows it but asking a teacher to
     * reproduce it is asking her to fight the form rather than read it. So both
     * "Juan D. Cruz" and "Juan Cruz" are accepted for the same child.
     *
     * Not forgiving about anything else. Nothing typed, a first name alone, a
     * surname alone or another child's name are all refused, which is the point:
     * a blind confirm-through must not pass.
     */
    private function typedNameMatches(mixed $typed, Learner $learner): bool
    {
        // `mixed`, not `?string`: a form field can arrive as an array
        // (learner_name[]=x) and a typed signature would make that a
        // TypeError - a 500 where the honest answer is "that is not the
        // child's name". Nothing was destroyed either way, but a crash is not
        // a refusal.
        if (! is_string($typed) || trim($typed) === '') {
            return false;
        }

        $normalise = function (string $name): string {
            // Punctuation inside a name is dropped before comparing, not the
            // name's words: what is required is the whole name.
            $name = str_replace(['.', "'", '’', '`', '-'], '', $name);

            return trim(preg_replace('/\s+/u', ' ', mb_strtolower($name)));
        };

        return in_array($normalise($typed), [
            $normalise($learner->getFullName()),
            $normalise($learner->first_name . ' ' . $learner->last_name),
        ], true);
    }

    /**
     * The audit line, built while the assessment still exists.
     *
     * It has to still mean something once it does not: "deleted assessment 41"
     * is useless the moment 41 is gone. So the child, the date, the material,
     * the level and the score are in the text itself, and the consequences are
     * named rather than left to be inferred from the action word.
     */
    private function deletionNarrative(Assessment $assessment, Learner $learner): string
    {
        $accuracy = $assessment->effectiveAccuracy();
        $level = $assessment->effectiveReadingLevel();

        return sprintf(
            'Deleted the %s reading of %s — material “%s”, level %s, accuracy %s. '
            . 'Its result, any verdict over it and its comprehension answers were deleted with it. '
            . 'The learner’s reading level was %s before the deletion and was recomputed from the readings that remain.',
            $assessment->created_at?->format('M d, Y') ?? 'undated',
            $learner->getFullName(),
            $assessment->material?->title ?? 'unknown',
            $level !== null ? ucfirst($level) : 'none recorded',
            $accuracy !== null ? number_format((float) $accuracy, 1) . '%' : 'not measured',
            $learner->reading_level !== null ? ucfirst($learner->reading_level) : 'not set'
        );
    }

    public function status(Assessment $assessment)
    {
        $this->authorizeLearnerAccess($assessment->learner);
        return response()->json(['status' => $assessment->status]);
    }

    /**
     * The convergence guard on the single-child doors.
     *
     * The same shape and the same position as the batch side's
     * AssessmentBatchController::refuseIfAnalysed() and the student side's
     * StudentAssessmentController::refuseIfSent(): called before anything is
     * validated, written or deleted, returning a response to send straight back
     * when it refuses and null when there is nothing to refuse.
     *
     * What it protects is the governing principle of the batch feature: a
     * recording rests at `recorded` and consumes no analysis time until the
     * teacher listens and deliberately sends it. These two actions predate the
     * feature and know nothing about it - uploadAudio() would leave `processing`
     * with no send stamp, which scopeAwaitingRescore() claims once it is stale,
     * and retry() would delete an unsent recording off the disk - and both are
     * reachable from a learner's history, so a teacher gets here by following a
     * link rather than by meaning any harm. She is pointed at the screen the
     * action really belongs to instead.
     *
     * A single-child reading has batch_id === null and is handed straight back
     * to the identical path: nothing about it changes.
     */
    private function refuseIfInBatch(Request $request, Assessment $assessment)
    {
        if ($assessment->batch_id === null) {
            return null;
        }

        $message = 'This reading belongs to a group assessment, so it is conducted from the batch board: '
            . 'each child’s recording waits there until you listen to it and send it yourself.';

        // The recorder on the single-child screen posts here with fetch, so a
        // redirect would be swallowed; it gets the refusal as a 409 it can read,
        // the same code the student door answers an already-sent reading with.
        if ($request->expectsJson()) {
            return response()->json(['success' => false, 'message' => $message], 409);
        }

        // With the feature switched off the batch routes 404, so sending her
        // there would strand her. The learner's own history is where the link
        // she followed came from, it is flag-independent, and - unlike going
        // back to this reading's page - it does not offer the same button
        // again. She is told plainly why there is nothing she can do here.
        if (! config('assessment.batch_enabled')) {
            return redirect()->route('assessments.learner-history', $assessment->learner_id)
                ->with('error', $message . ' Group assessment is switched off on this system, so the board '
                    . 'cannot be opened - ask your administrator to switch it on.');
        }

        return redirect()
            ->route('assessments.batch.entry', [$assessment->batch_id, $assessment])
            ->with('error', $message);
    }

    public function uploadAudio(Request $request, Assessment $assessment)
    {
        $this->authorizeLearnerAccess($assessment->learner);

        // Before the validation and before any file is written - see
        // refuseIfInBatch().
        if ($refusal = $this->refuseIfInBatch($request, $assessment)) {
            return $refusal;
        }

        $request->validate([
            'audio' => 'required|file|max:25600',
        ]);

        $file = $request->file('audio');
        $extension = $file->getClientOriginalExtension() ?: 'webm';
        $filename = "assessment_{$assessment->id}_" . time() . ".{$extension}";

        $path = $file->storeAs('assessments/audio', $filename, 'public');

        $assessment->update([
            'audio_file' => $path,
            'status' => Assessment::STATUS_PROCESSING,
        ]);

        ActivityLog::log('upload_audio', "Uploaded audio for assessment #{$assessment->id}", 'assessment', $assessment->id);

        return response()->json([
            'success' => true,
            'audio_file' => $filename,
            'message' => 'Audio uploaded successfully.',
        ]);
    }

    public function retry(Request $request, Assessment $assessment)
    {
        $this->authorizeLearnerAccess($assessment->learner);

        // Before storage is touched: one click here used to delete a batch
        // entry's unsent recording. See refuseIfInBatch().
        if ($refusal = $this->refuseIfInBatch($request, $assessment)) {
            return $refusal;
        }

        // Delete the existing audio file if it exists
        if ($assessment->audio_file && Storage::disk('public')->exists($assessment->audio_file)) {
            Storage::disk('public')->delete($assessment->audio_file);
        }

        $assessment->update([
            'audio_file' => null,
            'status' => Assessment::STATUS_PENDING,
        ]);

        return redirect()->route('assessments.show', $assessment);
    }

    /**
     * Run the analysis again on the recording already saved for this assessment.
     *
     * For a result the model could not classify: once the model is back the
     * teacher gets a real classification without the child reading again. The
     * newer result replaces the old one (see Assessment::createResult).
     */
    public function reanalyze(Request $request, Assessment $assessment, \App\Services\MLClassificationService $mlService)
    {
        $this->authorizeLearnerAccess($assessment->learner);

        $back = redirect()->route('assessments.results', $assessment);

        if (! $assessment->hasAudio()) {
            return $back->with('error', 'There is no saved recording to analyze again. Start a new assessment instead.');
        }

        // The same pipeline as a first analysis, with no new upload so it reads
        // the saved copy.
        $payload = $this->analyze($request, $assessment, $mlService)->getData(true);

        if (! ($payload['success'] ?? false)) {
            return $back->with('error', $payload['message'] ?? 'The reading could not be analyzed again.');
        }

        return match ($assessment->fresh('result')->result?->classification_status) {
            'classified' => $back->with('success', 'Analyzed again. The model classified this reading.'),
            'model_unsupported' => $back->with('error', "Analyzed again, but the model's suggestion is not supported by the measurements, so no weakness was classified."),
            default => $back->with('error', 'Analyzed again, but the analysis model is still not running, so no weakness was classified.'),
        };
    }

    /**
     * The conditional guard on the one door that scores in the request (R51).
     *
     * analyze() runs the whole pipeline synchronously, so pointed at a batch
     * entry it does two things at once that the feature exists to prevent: it
     * scores a reading the teacher never sent, and it takes the host’s single
     * scoring slot while the rest of the batch waits in a strictly serial lane.
     * There is no button for it on that screen any more - R48 gated the conduct
     * card - but the route is still a route.
     *
     * It cannot simply be shut, because reanalyze() calls analyze() and
     * re-analysing a batch result the model could not classify is the only way
     * out of the circular unclassified state (R45). So the rule is conditional
     * and the condition is the measurement itself:
     *
     *   no result  -> a Send bypass. Refused.
     *   a result   -> the Send already happened and the reading has already been
     *                 measured; this is a re-analysis of something the teacher
     *                 asked for. Permitted.
     *
     * Always a JSON 409, never a redirect: analyze() answers JSON on every
     * other path, its callers are fetch, and reanalyze() reads this response
     * with getData() - a redirect here would crash the teacher’s re-analysis
     * instead of explaining itself to her.
     *
     * A single-child reading has batch_id === null and is handed straight back
     * to the identical path.
     */
    private function refuseIfUnmeasuredBatchEntry(Assessment $assessment): ?\Illuminate\Http\JsonResponse
    {
        if ($assessment->batch_id === null || $assessment->result !== null) {
            return null;
        }

        return response()->json([
            'success' => false,
            'message' => 'This reading belongs to a group assessment and has not been sent for analysis yet, '
                . 'so it is not analysed from here: send it from the batch board when you have listened to it, '
                . 'and it is scored in turn with the rest of the group.',
        ], 409);
    }

    public function analyze(Request $request, Assessment $assessment, \App\Services\MLClassificationService $mlService)
    {
        $this->authorizeLearnerAccess($assessment->learner);

        // Before the comprehension write and before the scorer: see
        // refuseIfUnmeasuredBatchEntry().
        if ($refusal = $this->refuseIfUnmeasuredBatchEntry($assessment)) {
            return $refusal;
        }
        // For testing the ML pipeline, we accept the audio directly in the analyze endpoint.
        if (!$request->hasFile('audio') && !$assessment->hasAudio()) {
            return response()->json(['success' => false, 'message' => 'No audio recording provided.'], 400);
        }

        // Comprehension test: recorded before any transcription or ML runs, so a
        // failure further down never costs the learner's answers. Answers the
        // learner already submitted from their own portal are kept as-is.
        $comprehensionScore = null;

        if ($assessment->needsComprehensionTest()) {
            if ($request->filled('answers')) {
                $comprehensionScore = app(\App\Services\ComprehensionService::class)
                    ->record($assessment, $request->input('answers', []));
            } elseif ($assessment->hasComprehensionAnswers()) {
                $comprehensionScore = $assessment->comprehensionScore();
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Please complete the comprehension questions before analyzing.',
                ], 422);
            }
        }

        // A recording made in the browser only exists as PHP's temporary upload,
        // which is deleted the moment this request ends. Keep it before doing
        // anything else, so the teacher can always play it back later — and so a
        // failure further down doesn't lose it.
        if ($request->hasFile('audio')) {
            $this->keepRecording($assessment, $request->file('audio'));
        }

        try {
            app(\App\Services\AssessmentScorer::class)->score(
                $assessment,
                $request->input('reference_text'),
                $comprehensionScore
            );

            ActivityLog::log('analyze_assessment', "Completed analysis for assessment #{$assessment->id}", 'assessment', $assessment->id);

            // Return redirect URL to JS instead of just the JSON metrics
            return response()->json([
                'success' => true,
                'redirect_url' => route('assessments.results', $assessment),
                'message' => 'Analysis complete!',
            ]);
        } catch (\App\Exceptions\SpeechServiceUnavailable $e) {
            // No engine transcribed the audio, so nothing was scored. The
            // recording is already saved; analysing again is all it takes.
            $assessment->markFailed($e->getMessage());
            Log::error("Assessment #{$assessment->id}: " . $e->getMessage(), ['assessment_id' => $assessment->id]);

            return response()->json([
                'success' => false,
                'message' => $e->forTeacher(),
            ], 503);
        } catch (\Exception $e) {
            $assessment->markFailed($e->getMessage());

            // The teacher gets something they can act on; the detail (which may
            // name database tables or internal services) goes to the log only.
            Log::error("Assessment #{$assessment->id} analysis failed: " . $e->getMessage(), [
                'assessment_id' => $assessment->id,
                'exception' => $e,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'The recording was saved, but the analysis could not be completed. '
                    . 'Please try Analyze again — if it keeps failing, report assessment #' . $assessment->id . '.',
            ], 500);
        }
    }

    /**
     * Save a browser recording against the assessment, replacing any earlier one.
     *
     * The file's extension is chosen from a short list rather than taken from
     * the client's file name: this folder is served publicly, so a name like
     * "recording.php" must never be written as-is.
     */
    private function keepRecording(Assessment $assessment, \Illuminate\Http\UploadedFile $file): void
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $extension = in_array($extension, ['webm', 'weba', 'ogg', 'oga', 'wav', 'mp3', 'm4a', 'mp4'], true)
            ? $extension
            : 'webm';

        $path = $file->storeAs('assessments/audio', "assessment_{$assessment->id}_" . time() . ".{$extension}", 'public');

        $previous = $assessment->audio_file;
        $assessment->update(['audio_file' => $path]);

        if ($previous && $previous !== $path) {
            Storage::disk('public')->delete($previous);
        }
    }

    public function results(Assessment $assessment)
    {
        $this->authorizeLearnerAccess($assessment->learner);
        $result = $assessment->result;

        if (!$result) {
            if (auth()->user()->isAdmin()) {
                return redirect()->route('assessments.index')
                    ->with('error', 'Assessment results not available yet.');
            }

            return redirect()->route('assessments.show', $assessment)
                ->with('error', 'Assessment results not available yet.');
        }

        $assessment->load(['learner', 'material', 'comprehensionAnswers', 'verdict.decidedBy']);
        $recommendations = $assessment->getRecommendedInterventions();
        $comparison = $result->getComparisonWithPrevious();

        return view('assessments.results', [
            'assessment' => $assessment,
            'result' => $result,
            'learner' => $assessment->learner,
            'material' => $assessment->material,
            'recommendations' => $recommendations,
            'comparison' => $comparison,
            'readingLevelInfo' => $result->getReadingLevelInfo(),
            'errorBreakdown' => $result->getErrorBreakdown(),
            'skillScores' => $result->getSkillScores(),
            // Decision support: how this result was produced and what to watch for.
            'advisor' => app(\App\Services\AnalysisAdvisorService::class)->for($assessment),
            // Why the reading landed at its level, in the teacher's terms.
            'interpretation' => app(\App\Services\ReadingInterpretationService::class)->for($assessment),
            'weaknessWhy' => app(\App\Services\ReadingInterpretationService::class)->weakness($assessment),
        ]);
    }

    // ── Live Assessment Session (Teacher <-> Student) ──

    /**
     * Create a live assessment session for a student.
     */
    public function createSession(Request $request)
    {
        $request->validate([
            'learner_id' => 'required|exists:learners,id',
            'material_id' => 'required|exists:reading_materials,id',
        ]);

        $material = ReadingMaterial::findOrFail($request->material_id);
        $learner = Learner::findOrFail($request->learner_id);
        $this->authorizeLearnerAccess($learner);

        // Create the assessment record. A live session has no separate type
        // picker, so the material's own type decides whether a comprehension
        // test follows the reading.
        $assessment = Assessment::create([
            'learner_id' => $request->learner_id,
            'material_id' => $request->material_id,
            'assessor_id' => auth()->id(),
            'language' => $material->language,
            'assessment_type' => $material->isComprehensionType()
                ? Assessment::TYPE_COMPREHENSION
                : Assessment::TYPE_ORAL_READING,
            'status' => Assessment::STATUS_PENDING,
        ]);

        // Create the live session
        $session = AssessmentSession::create([
            'assessment_id' => $assessment->id,
            'learner_id' => $request->learner_id,
            'teacher_id' => auth()->id(),
            'material_id' => $request->material_id,
            'session_code' => AssessmentSession::generateCode(),
            'status' => 'waiting',
            'started_at' => now(),
        ]);

        ActivityLog::log('create_live_session', "Started live session for learner #{$request->learner_id}", 'assessment_session', $session->id);

        return redirect()->route('assessments.session.monitor', $session);
    }

    /**
     * Teacher's real-time monitoring view for a live session.
     */
    public function monitorSession(AssessmentSession $session)
    {
        $this->authorizeLearnerAccess($session->learner);
        $session->load(['learner', 'material', 'assessment']);

        return view('assessments.monitor', [
            'session' => $session,
            'learner' => $session->learner,
            'material' => $session->material,
        ]);
    }

    /**
     * Polling endpoint: teacher gets student status updates.
     */
    public function pollSession(AssessmentSession $session)
    {
        $this->authorizeLearnerAccess($session->learner);
        $session->refresh();

        return response()->json([
            'status' => $session->status,
            'student_joined' => $session->student_joined_at !== null,
            'student_joined_at' => $session->student_joined_at?->diffForHumans(),
            'reading_started_at' => $session->reading_started_at?->toIso8601String(),
            'elapsed' => $session->reading_started_at
                ? now()->diffInSeconds($session->reading_started_at)
                : 0,
            'student_progress' => $session->student_progress,
        ]);
    }

    /**
     * Teacher signals to start recording.
     */
    public function sessionStartRecording(AssessmentSession $session)
    {
        $this->authorizeLearnerAccess($session->learner);
        $session->startRecording();

        ActivityLog::log('start_recording', "Started recording for session #{$session->id}", 'assessment_session', $session->id);

        return response()->json(['status' => 'recording']);
    }

    /**
     * Teacher cancels a live session.
     */
    public function cancelSession(AssessmentSession $session)
    {
        $this->authorizeLearnerAccess($session->learner);
        $session->cancel();

        ActivityLog::log('cancel_session', "Cancelled assessment session #{$session->id}", 'assessment_session', $session->id);

        return redirect()->route('assessments.index')
            ->with('info', 'Live assessment session cancelled.');
    }

    /**
     * Generate PIN for a learner (teacher action).
     */
    public function generatePin(Learner $learner)
    {
        $this->authorizeLearnerAccess($learner);
        $pin = Learner::generatePin();
        // pin is guarded — explicit assignment only (hashed cast still applies).
        $learner->pin = $pin;
        // pin_created_at is a non-sensitive timestamp — stamp explicitly
        // alongside every new PIN issuance.
        $learner->pin_created_at = now();
        $learner->save();

        ActivityLog::log('generate_pin', "Generated PIN for learner: {$learner->first_name} {$learner->last_name}", 'learner', $learner->id);

        return back()->with('success', "A new PIN was issued for {$learner->getFullName()}.");
    }
}

