<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AssessmentSession;
use App\Models\Learner;
use App\Services\BadgeService;
use Illuminate\Http\Request;

class StudentAssessmentController extends Controller
{
    /**
     * The one reading this child is being offered, if any.
     *
     * The single definition of "there is something to read": the poll below and
     * the dashboard card both ask it, so the card can never promise a reading
     * the poll would not hand over.
     */
    public static function pendingFor(Learner $learner): ?\App\Models\Assessment
    {
        return \App\Models\Assessment::where('learner_id', $learner->id)
            ->where('status', \App\Models\Assessment::STATUS_PENDING)
            // A cancelled batch's entries are no longer offered to the child.
            ->whereDoesntHave('batch', fn ($b) => $b->where('status', \App\Models\AssessmentBatch::STATUS_CANCELLED))
            ->latest()
            ->first();
    }

    public function pending(Request $request)
    {
        $learner = $request->attributes->get('learner');
        $assessment = self::pendingFor($learner);

        return response()->json([
            'has_pending' => (bool)$assessment,
            'assessment_id' => $assessment ? $assessment->id : null,
            'material_title' => $assessment && $assessment->material ? $assessment->material->title : null,
        ]);
    }

    public function read(Request $request, \App\Models\Assessment $assessment)
    {
        $learner = $request->attributes->get('learner');
        if ($assessment->learner_id !== $learner->id
            || $assessment->status !== \App\Models\Assessment::STATUS_PENDING
            || $this->batchClosed($assessment)) {
            return redirect()->route('student.dashboard')->with('error', 'No pending assessment found.');
        }

        $material = $assessment->material;

        // Questions are asked on this same screen after the learner finishes
        // reading, before anything is sent to the teacher.
        $questions = $assessment->needsComprehensionTest()
            ? $material->comprehensionQuestions
            : collect();

        return view('student.assessment.reading', compact('assessment', 'material', 'learner', 'questions'));
    }

    public function start(Request $request, \App\Models\Assessment $assessment)
    {
        $learner = $request->attributes->get('learner');
        if ($assessment->learner_id !== $learner->id || $this->batchClosed($assessment)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Nothing is changed about a reading that is already on its way - see
        // refuseIfSent(), the student door's half of the teacher side's
        // refuseIfAnalysed().
        if ($refusal = $this->refuseIfSent($assessment)) {
            return $refusal;
        }

        if ($assessment->status === \App\Models\Assessment::STATUS_PENDING) {
            $assessment->update(['status' => \App\Models\Assessment::STATUS_RECORDING]);
        }
        return response()->json(['success' => true]);
    }

    public function uploadAudio(Request $request, \App\Models\Assessment $assessment)
    {
        $learner = $request->attributes->get('learner');
        if ($assessment->learner_id !== $learner->id || $this->batchClosed($assessment)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Before anything is validated, stored or written - the same position
        // the teacher side's refuseIfAnalysed() holds, so the recording, the
        // comprehension answers and the send stamp are all behind one guard.
        if ($refusal = $this->refuseIfSent($assessment)) {
            return $refusal;
        }

        $request->validate(['audio' => 'required|file|max:25600']); // Relaxed mimes for browser-recorded blobs

        // A comprehension assessment can't be handed to the teacher without the
        // learner's answers — they're recorded first, so a failed upload never
        // loses them.
        if ($assessment->needsComprehensionTest()) {
            app(\App\Services\ComprehensionService::class)
                ->record($assessment, $request->input('answers', []));
        }

        $file = $request->file('audio');
        $filename = "assessment_{$assessment->id}_student_{$learner->id}." . ($file->getClientOriginalExtension() ?: 'webm');
        $path = $file->storeAs('assessments/audio', $filename, 'public');

        if ($assessment->batch_id !== null) {
            // A batch entry rests at `recorded` for the teacher to hear and send,
            // exactly as when the teacher records it (AssessmentBatchController::
            // keepRecording()). `processing` would be claimed by the rescore lane
            // fifteen minutes later, sending the reading without the teacher.
            $assessment->forceFill([
                'audio_file' => $path,
                'status' => \App\Models\Assessment::STATUS_PENDING,
                'submitted_for_analysis_at' => null,
                'analysis_started_at' => null,
                'analysis_duration_ms' => null,
            ])->save();
        } else {
            $assessment->update([
                'audio_file' => $path,
                'status' => \App\Models\Assessment::STATUS_PROCESSING
            ]);
        }

        return response()->json(['success' => true, 'message' => 'Audio uploaded successfully.']);
    }

    /**
     * A reading that is already on its way is not changed from here.
     *
     * The teacher side's AssessmentBatchController::refuseIfAnalysed() says the
     * same thing about the same two writes - the recording and the comprehension
     * answers - so the child's door and the teacher's door are one guard said
     * twice. Two cases close it:
     *
     *  - a result is in place: the numbers on the teacher's screen were worked
     *    out from this recording and these answers, and replacing either would
     *    leave the result describing something that is no longer there;
     *  - a batch entry the teacher has sent: re-recording nulls
     *    submitted_for_analysis_at, which would pull the reading out of the
     *    scoring lane silently and leave it never scored and never complained
     *    about.
     *
     * A child who has recorded but has not been sent is not refused: a noisy
     * first take must still be re-recordable.
     */
    private function refuseIfSent(\App\Models\Assessment $assessment)
    {
        $sent = $assessment->batch_id !== null && $assessment->submitted_for_analysis_at !== null;

        if ($assessment->result === null && ! $sent) {
            return null;
        }

        return response()->json([
            'error' => 'This reading has already been sent to your teacher.',
        ], 409);
    }

    /**
     * A batch entry whose batch the teacher has cancelled admits no child. An
     * entry outside any batch is never closed by this.
     */
    private function batchClosed(\App\Models\Assessment $assessment): bool
    {
        return $assessment->batch_id !== null
            && $assessment->batch?->status === \App\Models\AssessmentBatch::STATUS_CANCELLED;
    }
}
