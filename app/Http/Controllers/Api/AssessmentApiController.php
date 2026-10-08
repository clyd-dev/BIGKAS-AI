<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Models\Learner;
use App\Models\ReadingMaterial;
use App\Services\InterventionRecommenderService;
use App\Services\MLClassificationService;
use App\Services\ReadingAnalyzerService;
use App\Services\SpeechToTextService;
use App\Traits\AuthorizesLearnerAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssessmentApiController extends Controller
{
    use AuthorizesLearnerAccess;

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
            ->where('assessor_id', $user->id)
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

        $this->authorizeLearnerAccess($learner);

        $assessment = Assessment::create([
            'learner_id' => $learner->id,
            'material_id' => $material->id,
            'assessor_id' => $request->user()->id,
            'language' => $validated['language'] ?? $material->language,
            'status' => Assessment::STATUS_PENDING,
            'assessment_type' => $validated['assessment_type'] ?? Assessment::TYPE_ORAL_READING,
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
        $this->authorizeLearnerAccess($assessment->learner);
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
        $this->authorizeLearnerAccess($assessment->learner);

        $request->validate([
            'audio' => 'required|file|mimes:webm,wav,mp3,ogg,m4a|max:20480',
        ]);

        $file = $request->file('audio');
        $filename = 'assessment_' . $assessment->id . '_' . time() . '.' . $file->getClientOriginalExtension();

        // Same disk and folder the web flow uses, so getAudioPath() resolves it.
        $path = $file->storeAs('assessments/audio', $filename, 'public');

        $assessment->update([
            'audio_file' => $path,
            'status' => Assessment::STATUS_PROCESSING,
        ]);

        return $this->success([
            'assessment_id' => $assessment->id,
            'audio_path' => $path,
            'status' => $assessment->status,
        ], 'Audio uploaded successfully');
    }

    /**
     * Analyze assessment (STT + text comparison + ML classification)
     */
    public function analyze(Request $request, Assessment $assessment): JsonResponse
    {
        $this->authorizeLearnerAccess($assessment->learner);

        $material = $assessment->material;
        if (! $material) {
            return $this->error('Associated material not found', 404);
        }

        if (! $assessment->hasAudio()) {
            return $this->error('No audio recording found for this assessment', 422);
        }

        // Comprehension test, when this assessment has one. Recorded before any
        // transcription so a later failure can't cost the learner's answers.
        $comprehensionScore = null;

        if ($assessment->needsComprehensionTest()) {
            if ($request->filled('answers')) {
                $comprehensionScore = app(\App\Services\ComprehensionService::class)
                    ->record($assessment, $request->input('answers', []));
            } elseif ($assessment->hasComprehensionAnswers()) {
                $comprehensionScore = $assessment->comprehensionScore();
            } else {
                return $this->error('Comprehension answers are required for this assessment', 422);
            }
        }

        try {
            // Step 1: Speech-to-Text
            $transcription = $this->sttService->transcribe(
                $assessment->getAudioPath(),
                $assessment->language ?? 'en'
            );

            $assessment->update(['transcription' => $transcription]);

            $transcribedText = $transcription['text'] ?? '';
            $duration = $transcription['duration'] ?? 60;

            // Step 2: Reading Analysis
            $analysis = $this->analyzerService->analyze($transcription, $material->content, (float) $duration);

            // Step 3: ML Classification — the service expects the 12 rate
            // features the analyzer prepares, not raw counts.
            $classification = $this->mlService->classify($analysis['ml_features']);

            $analysis['primary_weakness'] = MLClassificationService::mapWeaknessToId($classification['primary'] ?? '')
                ?? $analysis['primary_weakness'];
            $analysis['confidence_score'] = $classification['confidence'] ?? $analysis['confidence_score'];

            if ($comprehensionScore !== null) {
                $analysis['comprehension_score'] = $comprehensionScore;
            }

            // Step 4: Save results via the same mapping the web flow uses.
            $result = $assessment->createResult($analysis);

            $assessment->markCompleted();

            $learner = $assessment->learner;
            $learner?->update(['reading_level' => $result->reading_level]);

            // Step 5: Intervention recommendations
            $recommendations = $learner
                ? $this->recommenderService->getRecommendations($result, $learner, $request->user()->role)
                : [];

            return $this->success([
                'result_id' => $result->id,
                'transcription' => $transcribedText,
                'accuracy_rate' => $result->accuracy_rate,
                'words_per_minute' => $result->words_per_minute,
                'reading_level' => $result->reading_level,
                'comprehension_score' => $result->comprehension_score,
                'errors' => [
                    'total' => $result->error_count,
                    'substitutions' => $result->substitutions,
                    'omissions' => $result->omissions,
                    'insertions' => $result->insertions,
                ],
                'classification' => $classification,
                'recommendations' => $recommendations,
            ], 'Analysis completed successfully');
        } catch (\Exception $e) {
            $assessment->markFailed($e->getMessage());

            return $this->error('Analysis failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get assessment results
     */
    public function results(Assessment $assessment): JsonResponse
    {
        $this->authorizeLearnerAccess($assessment->learner);

        $result = $assessment->result;

        if (! $result) {
            return $this->error('No results found for this assessment', 404);
        }

        return $this->success([
            'assessment_id' => $assessment->id,
            'result' => $result,
            'comprehension_answers' => $assessment->comprehensionAnswers->map(fn ($a) => [
                'question' => $a->question_text,
                'answer' => $a->selected_text,
                'correct_answer' => $a->correct_text,
                'is_correct' => $a->is_correct,
            ]),
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
