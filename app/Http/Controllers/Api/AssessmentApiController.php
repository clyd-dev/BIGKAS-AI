<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\Learner;
use App\Models\ReadingMaterial;
use App\Services\InterventionRecommenderService;
use App\Services\MLClassificationService;
use App\Services\ReadingAnalyzerService;
use App\Services\SpeechToTextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssessmentApiController extends Controller
{
    public function __construct(
        protected SpeechToTextService $sttService,
        protected ReadingAnalyzerService $analyzerService,
        protected MLClassificationService $mlService,
        protected InterventionRecommenderService $recommenderService,
    ) {}

    /**
     * List assessments for the authenticated user
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $assessments = Assessment::with(['learner', 'material'])
            ->where('administered_by', $user->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'learner_name' => $a->learner?->full_name,
                'material_title' => $a->material?->title,
                'status' => $a->status,
                'language' => $a->language,
                'assessment_type' => $a->assessment_type,
                'created_at' => $a->created_at,
            ]);

        return $this->success(['assessments' => $assessments]);
    }

    /**
     * Create a new assessment session
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'learner_id' => 'required|exists:learners,id',
            'material_id' => 'required|exists:reading_materials,id',
            'language' => 'nullable|string',
            'assessment_type' => 'nullable|in:oral_reading,comprehension,combined',
        ]);

        $material = ReadingMaterial::findOrFail($validated['material_id']);
        $learner = Learner::findOrFail($validated['learner_id']);

        $assessment = Assessment::create([
            'learner_id' => $learner->id,
            'material_id' => $material->id,
            'administered_by' => $request->user()->id,
            'language' => $validated['language'] ?? $material->language,
            'status' => 'pending',
            'assessment_type' => $validated['assessment_type'] ?? 'oral_reading',
        ]);

        return $this->success([
            'assessment_id' => $assessment->id,
            'learner' => $learner->full_name,
            'material' => $material->title,
            'status' => 'pending',
        ], 'Assessment session created', 201);
    }

    /**
     * Show assessment details
     */
    public function show(Assessment $assessment): JsonResponse
    {
        $assessment->load(['learner', 'material']);

        return $this->success([
            'assessment' => [
                'id' => $assessment->id,
                'status' => $assessment->status,
                'language' => $assessment->language,
                'assessment_type' => $assessment->assessment_type,
                'created_at' => $assessment->created_at,
            ],
            'learner' => $assessment->learner ? [
                'id' => $assessment->learner->id,
                'name' => $assessment->learner->full_name,
            ] : null,
            'material' => $assessment->material ? [
                'id' => $assessment->material->id,
                'title' => $assessment->material->title,
                'content' => $assessment->material->content,
                'word_count' => $assessment->material->word_count,
            ] : null,
        ]);
    }

    /**
     * Upload audio recording for assessment
     */
    public function uploadAudio(Request $request, Assessment $assessment): JsonResponse
    {
        $request->validate([
            'audio' => 'required|file|mimes:webm,wav,mp3,ogg,m4a|max:20480',
        ]);

        $file = $request->file('audio');
        $filename = 'assessment_' . $assessment->id . '_' . time() . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs('audio', $filename, 'public');

        $assessment->update([
            'audio_file_path' => $path,
            'status' => 'audio_uploaded',
        ]);

        return $this->success([
            'assessment_id' => $assessment->id,
            'audio_path' => $path,
            'status' => 'audio_uploaded',
        ], 'Audio uploaded successfully');
    }

    /**
     * Analyze assessment (STT + text comparison + ML classification)
     */
    public function analyze(Assessment $assessment): JsonResponse
    {
        $material = $assessment->material;
        if (! $material) {
            return $this->error('Associated material not found', 404);
        }

        // Step 1: Speech-to-Text
        $audioPath = storage_path('app/public/' . ($assessment->audio_file_path ?? ''));
        $language = $assessment->language ?? 'english';
        $transcription = $this->sttService->transcribe($audioPath, $language);

        if (! ($transcription['success'] ?? false)) {
            return $this->error('Transcription failed: ' . ($transcription['error'] ?? 'Unknown error'), 500);
        }

        $transcribedText = $transcription['text'];
        $duration = $transcription['duration'] ?? 0;

        // Step 2: Reading Analysis
        $analysis = $this->analyzerService->analyze(
            ['text' => $transcribedText, 'words' => $transcription['words'] ?? []],
            $material->content,
            (float) $duration
        );

        // Step 3: ML Classification
        $classification = $this->mlService->classify([
            'accuracy_rate' => $analysis['accuracy_rate'],
            'words_per_minute' => $analysis['words_per_minute'],
            'error_count' => $analysis['error_count'],
            'substitution_count' => $analysis['substitutions'],
            'omission_count' => $analysis['omissions'],
            'insertion_count' => $analysis['insertions'],
            'self_correction_count' => $analysis['self_corrections'] ?? 0,
        ]);

        // Step 4: Determine reading level (Phil-IRI)
        $readingLevel = $analysis['reading_level'];

        // Step 5: Save results
        $result = AssessmentResult::create([
            'assessment_id' => $assessment->id,
            'transcribed_text' => $transcribedText,
            'accuracy_rate' => $analysis['accuracy_rate'],
            'words_per_minute' => $analysis['words_per_minute'],
            'reading_level' => $readingLevel,
            'error_count' => $analysis['error_count'],
            'substitution_count' => $analysis['substitutions'],
            'omission_count' => $analysis['omissions'],
            'insertion_count' => $analysis['insertions'],
            'self_correction_count' => $analysis['self_corrections'] ?? 0,
            'primary_weakness' => $classification['primary_weakness'] ?? null,
            'weakness_confidence' => $classification['confidence'] ?? null,
            'ml_classification_data' => $classification,
            'word_comparison_data' => $analysis['word_comparison'] ?? [],
            'duration_seconds' => $duration,
        ]);

        // Update assessment status
        $assessment->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        // Update learner reading level
        $learner = $assessment->learner;
        if ($learner) {
            $learner->update(['reading_level' => $readingLevel]);
        }

        // Step 6: Get intervention recommendations
        $recommendations = $this->recommenderService->recommend(
            $classification['primary_weakness'] ?? 1,
            $readingLevel,
            $learner->grade_level ?? 1
        );

        return $this->success([
            'result_id' => $result->id,
            'transcription' => $transcribedText,
            'accuracy_rate' => $analysis['accuracy_rate'],
            'words_per_minute' => $analysis['words_per_minute'],
            'reading_level' => $readingLevel,
            'errors' => [
                'total' => $analysis['error_count'],
                'substitutions' => $analysis['substitutions'],
                'omissions' => $analysis['omissions'],
                'insertions' => $analysis['insertions'],
            ],
            'classification' => $classification,
            'recommendations' => $recommendations,
        ], 'Analysis completed successfully');
    }

    /**
     * Get assessment results
     */
    public function results(Assessment $assessment): JsonResponse
    {
        $result = $assessment->results()->latest()->first();

        if (! $result) {
            return $this->error('No results found for this assessment', 404);
        }

        return $this->success([
            'assessment_id' => $assessment->id,
            'result' => $result,
        ]);
    }

    // ----- JSON helper methods -----

    protected function success(array $data, string $message = 'Success', int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data], $status);
    }

    protected function error(string $message, int $status): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message], $status);
    }
}
