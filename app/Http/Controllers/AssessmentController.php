<?php

namespace App\Http\Controllers;

use App\Models\Assessment;
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

        return view('assessment.index', compact('assessments'));
    }

    public function create()
    {
        $user = auth()->user();
        $learners = $user->isAdmin()
            ? Learner::active()->orderBy('last_name')->get()
            : $user->learners()->orderBy('last_name')->get();

        return view('assessment.create', compact('learners'));
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

        return view('assessment.start', compact('learner', 'materials', 'easierMaterials', 'languages'));
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

        return view('assessment.show', [
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

        return view('assessment.results', [
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
}
