<?php

namespace App\Http\Controllers;

use App\Models\Assessment;
use App\Models\AssessmentSession;
use App\Models\Learner;
use App\Models\ReadingMaterial;
use App\Models\ActivityLog;
use App\Services\SpeechToTextService;
use App\Services\ReadingAnalyzerService;
use App\Services\InterventionRecommenderService;
use Illuminate\Http\Request;
use App\Traits\AuthorizesLearnerAccess;

class AssessmentController extends Controller
{
    use AuthorizesLearnerAccess;
    public function index()
    {
        $user = auth()->user();

        $assessments = $user->isAdmin()
            ? Assessment::with(['learner', 'material', 'result', 'assessor'])->latest()->paginate(20)
            : Assessment::forUser($user)->with(['learner', 'material', 'result'])->latest()->paginate(20);

        return view('assessments.index', compact('assessments'));
    }

    public function create()
    {
        $user = auth()->user();
        $learners = $user->accessibleLearnersQuery()->orderBy('last_name')->get();

        $materials = ReadingMaterial::active()->orderBy('grade_level')->orderBy('title')->get();

        return view('assessments.create', compact('learners', 'materials'));
    }

    public function start(Learner $learner)
    {
        $this->authorizeLearnerAccess($learner);
        $materials = ReadingMaterial::active()
            ->where('grade_level', $learner->grade_level)
            ->orderBy('title')
            ->get();

        $easierMaterials = ReadingMaterial::active()
            ->where('grade_level', max(1, $learner->grade_level - 1))
            ->orderBy('title')
            ->get();

        $languages = ['en' => 'English', 'fil' => 'Filipino'];

        return view('assessments.start', compact('learner', 'materials', 'easierMaterials', 'languages'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'learner_id' => 'required|exists:learners,id',
            'material_id' => 'required|exists:reading_materials,id',
        ]);

        $material = ReadingMaterial::findOrFail($request->material_id);

        $assessment = Assessment::create([
            'learner_id' => $request->learner_id,
            'material_id' => $request->material_id,
            'assessor_id' => auth()->id(),
            'language' => $material->language,
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
        $assessment->load(['learner', 'material']);

        return view('assessments.show', [
            'assessment' => $assessment,
            'learner' => $assessment->learner,
            'material' => $assessment->material,
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

        $assessment->updateStatus(Assessment::STATUS_PROCESSING);

        try {
            // ==========================================
            // REAL: SPEECH TO TEXT (Whisper API)
            // ==========================================
            if ($request->hasFile('audio')) {
                $audioPath = $request->file('audio')->getRealPath();
            } else {
                $audioPath = $assessment->getAudioPath();
            }
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
            $metrics = [
                'wpm' => $analysis['words_per_minute'],
                'accuracy' => $analysis['accuracy_rate'],
                'omissions' => $analysis['omissions'],
                'insertions' => $analysis['insertions'],
                'substitutions' => $analysis['substitutions']
            ];
            
            $classification = $mlService->classify($metrics);
            
            // Map the String predictions from Python back to the Database Integers
            $weaknessMap = [
                'Phonemic Awareness' => 1,
                'Decoding Accuracy' => 2,
                'Oral Reading Fluency' => 3,
                'Comprehension' => 4,
                'Instructional (Mixed)' => 2, // Fallback mapping
                'None (Independent)' => null
            ];
            
            $predictedString = $classification['primary'] ?? '';
            $primaryWeaknessId = $weaknessMap[$predictedString] ?? null;
            
            // Merge ML classification into analysis results to store properly
            $analysis['primary_weakness'] = $primaryWeaknessId ?? $analysis['primary_weakness'];
            $analysis['secondary_weakness'] = null; // Update mapping if secondary model is implemented
            $analysis['confidence_score'] = $classification['confidence'] ?? $analysis['confidence_score'];

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
        } catch (\Exception $e) {
            $assessment->markFailed($e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Analysis failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function results(Assessment $assessment)
    {
        $this->authorizeLearnerAccess($assessment->learner);
        $result = $assessment->result;

        if (!$result) {
            return redirect()->route('assessments.show', $assessment)
                ->with('error', 'Assessment results not available yet.');
        }

        $assessment->load(['learner', 'material']);
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

        // Create the assessment record
        $assessment = Assessment::create([
            'learner_id' => $request->learner_id,
            'material_id' => $request->material_id,
            'assessor_id' => auth()->id(),
            'language' => $material->language,
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
        $learner->update(['pin' => $pin]);

        ActivityLog::log('generate_pin', "Generated PIN for learner: {$learner->first_name} {$learner->last_name}", 'learner', $learner->id);

        return back()->with('success', "PIN for {$learner->getFullName()}: {$pin}");
    }
}

