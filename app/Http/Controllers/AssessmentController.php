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

class AssessmentController extends Controller
{
    public function index()
    {
        $user = auth()->user();

        $assessments = $user->isAdmin()
            ? Assessment::with(['learner', 'material', 'result', 'assessor'])->latest()->paginate(20)
            : Assessment::forUser($user->id)->with(['learner', 'material', 'result'])->latest()->paginate(20);

        return view('assessments.index', compact('assessments'));
    }

    public function create()
    {
        $user = auth()->user();
        $learners = $user->isAdmin()
            ? Learner::active()->orderBy('last_name')->get()
            : $user->learners()->orderBy('last_name')->get();

        return view('assessments.create', compact('learners'));
    }

    public function start(Learner $learner)
    {
        $materials = ReadingMaterial::active()
            ->where('grade_level', $learner->grade_level)
            ->orderBy('title')
            ->get();

        $easierMaterials = ReadingMaterial::active()
            ->where('grade_level', max(1, $learner->grade_level - 1))
            ->orderBy('title')
            ->get();

        $languages = ['en' => 'English', 'fil' => 'Filipino', 'hil' => 'Hiligaynon'];

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
        $assessment->load(['learner', 'material']);

        return view('assessments.show', [
            'assessment' => $assessment,
            'learner' => $assessment->learner,
            'material' => $assessment->material,
        ]);
    }

    public function uploadAudio(Request $request, Assessment $assessment)
    {
        $request->validate([
            'audio' => 'required|file|mimes:mp3,wav,webm,ogg|max:25600',
        ]);

        $file = $request->file('audio');
        $extension = $file->getClientOriginalExtension() ?: 'webm';
        $filename = "assessment_{$assessment->id}_" . time() . ".{$extension}";

        $file->storeAs('audio', $filename);

        $assessment->update([
            'audio_file' => $filename,
            'status' => Assessment::STATUS_RECORDING,
        ]);

        return response()->json([
            'success' => true,
            'audio_file' => $filename,
            'message' => 'Audio uploaded successfully.',
        ]);
    }

    public function analyze(Assessment $assessment)
    {
        if (!$assessment->hasAudio()) {
            return response()->json(['success' => false, 'message' => 'No audio recording found.'], 400);
        }

        $assessment->updateStatus(Assessment::STATUS_PROCESSING);

        try {
            // Step 1: Transcribe audio
            $sttService = app(SpeechToTextService::class);
            $transcription = $sttService->transcribe(
                $assessment->getAudioPath(),
                $assessment->language
            );

            $assessment->update(['transcription' => $transcription]);

            // Step 2: Analyze reading performance
            $analyzer = app(ReadingAnalyzerService::class);
            $analysis = $analyzer->analyze(
                $transcription,
                $assessment->material->content,
                $transcription['duration'] ?? 60
            );

            // Step 3: Create result
            $result = $assessment->createResult($analysis);

            // Step 4: Get recommendations
            $recommender = app(InterventionRecommenderService::class);
            $recommendations = $recommender->getRecommendations($result, $assessment->learner);

            ActivityLog::log('analyze_assessment', "Completed analysis for assessment #{$assessment->id}", 'assessment', $assessment->id);

            return response()->json([
                'success' => true,
                'result' => $result->toArray(),
                'recommendations' => $recommendations,
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
        $session->startRecording();

        return response()->json(['status' => 'recording']);
    }

    /**
     * Teacher cancels a live session.
     */
    public function cancelSession(AssessmentSession $session)
    {
        $session->cancel();

        return redirect()->route('assessments.index')
            ->with('info', 'Live assessment session cancelled.');
    }

    /**
     * Generate PIN for a learner (teacher action).
     */
    public function generatePin(Learner $learner)
    {
        $pin = Learner::generatePin();
        $learner->update(['pin' => $pin]);

        return back()->with('success', "PIN for {$learner->getFullName()}: {$pin}");
    }
}
