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

        return view('assessments.index', compact('learners'));
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

        // Materials are loaded progressively via materials.options once the
        // teacher picks learner → language → assessment type.
        return view('assessments.create', compact('learners'));
    }

    public function start(Learner $learner)
    {
        $this->authorizeLearnerAccess($learner);

        // Materials are loaded progressively via materials.options once the
        // teacher picks language → assessment type.
        return view('assessments.start', compact('learner'));
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
                ->withErrors(['material_id' => 'Cross-grade fallback is limited to one grade above or below the learner\u2019s grade.'])
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

    public function status(Assessment $assessment)
    {
        $this->authorizeLearnerAccess($assessment->learner);
        return response()->json(['status' => $assessment->status]);
    }

    public function uploadAudio(Request $request, Assessment $assessment)
    {
        $this->authorizeLearnerAccess($assessment->learner);
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

    public function retry(Assessment $assessment)
    {
        $this->authorizeLearnerAccess($assessment->learner);

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

    public function analyze(Request $request, Assessment $assessment, \App\Services\MLClassificationService $mlService)
    {
        $this->authorizeLearnerAccess($assessment->learner);
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

        $assessment->updateStatus(Assessment::STATUS_PROCESSING);

        try {
            // ==========================================
            // REAL: SPEECH TO TEXT (Whisper API)
            // ==========================================
            // Always the saved copy, so what was played back is what was analysed.
            $audioPath = $assessment->getAudioPath();
            $sttService = app(SpeechToTextService::class);
            // Uses your OpenAI key in .env, or falls back to mock if not configured
            $transcription = $sttService->transcribe($audioPath, $assessment->language ?? 'en');
            
            $assessment->update(['transcription' => $transcription]);

            // ==========================================
            // REAL: TEXT ALIGNMENT & METRICS
            // ==========================================
            $referenceText = $request->input('reference_text', $assessment->material?->content ?? '');
            $analyzer = app(ReadingAnalyzerService::class);
            $analysis = $analyzer->analyze(
                $transcription,
                $referenceText,
                $transcription['duration'] ?? 60
            );

            // ==========================================
            // REAL: ML CLASSIFICATION (Calling your Python API!)
            // ==========================================
            $classification = $mlService->classify($analysis['ml_features']);
            
            // Map the String predictions from Python back to the Database Integers
            // A weakness is only stored when the measured evidence shows it, so
            // the result never names a skill the teacher's own rows call fine.
            $primaryWeaknessId = \App\Services\MLClassificationService::resolveWeaknessId(
                $classification,
                \App\Services\WeaknessEvidence::statuses(
                    $analysis,
                    $assessment->learner?->grade_level,
                    $comprehensionScore ?? $assessment->comprehensionScore()
                ),
                $analysis['primary_weakness'] ?? null
            );

            // Merge ML classification into analysis results to store properly
            $analysis['primary_weakness'] = $primaryWeaknessId;
            $analysis['secondary_weakness'] = null; // Update mapping if secondary model is implemented
            $analysis['confidence_score'] = $classification['confidence'] ?? $analysis['confidence_score'];

            // Real comprehension score from the question set, when one was taken.
            // The ML feature vector is deliberately left untouched.
            if ($comprehensionScore !== null) {
                $analysis['comprehension_score'] = $comprehensionScore;
            }

            // Record which engines actually produced this result, so the teacher
            // can see whether to trust it (see AnalysisAdvisorService).
            $analysis['provenance'] = $this->buildProvenance($transcription, $classification);

            // ==========================================
            // SAVE TO DATABASE
            // ==========================================
            $result = $assessment->createResult($analysis);
            
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

    /**
     * Provenance of one analysis run: which speech engine and which classifier
     * produced it, and how confident the transcription was.
     */
    private function buildProvenance(array $transcription, array $classification): array
    {
        $confidences = array_filter(
            array_column($transcription['words'] ?? [], 'confidence'),
            fn ($v) => $v !== null
        );

        // Read the shipped metadata rather than calling the ML service again —
        // analyze() is already the slowest request in the app.
        $metadataPath = base_path('ml-service/model_metadata.json');
        $metadata = is_file($metadataPath)
            ? (json_decode((string) file_get_contents($metadataPath), true) ?: [])
            : [];

        return [
            'stt_engine' => $transcription['engine'] ?? 'unknown',
            'stt_is_mock' => (bool) ($transcription['is_mock'] ?? false),
            'mean_word_confidence' => $confidences ? round(array_sum($confidences) / count($confidences), 3) : null,
            'classifier' => ($classification['is_fallback'] ?? false) ? 'rule_based' : 'ml',
            'model_version' => $metadata['version'] ?? null,
            'analysed_at' => now()->toIso8601String(),
        ];
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

