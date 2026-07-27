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
     * Show the student's reading view for a live assessment session.
     */
    public function show(Request $request, AssessmentSession $session)
    {
        $learner = $request->attributes->get('learner');

        if ($session->learner_id !== $learner->id) {
            abort(403, 'This assessment is not for you.');
        }

        if ($session->isCompleted()) {
            return redirect()->route('student.dashboard')
                ->with('info', 'This assessment has already been completed.');
        }

        $material = $session->material;

        // Mark student as joined if still waiting
        if ($session->isWaiting()) {
            $session->markStudentJoined();
        }

        return view('student.assessment.reading', compact('session', 'material', 'learner'));
    }

    /**
     * Poll endpoint: student sends progress updates, receives session status.
     */
    public function poll(Request $request, AssessmentSession $session)
    {
        $learner = $request->attributes->get('learner');

        if ($session->learner_id !== $learner->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Update student progress if sent
        if ($request->has('progress')) {
            $session->updateStudentProgress($request->input('progress'));
        }

        return response()->json([
            'status' => $session->fresh()->status,
            'elapsed' => $session->reading_started_at
                ? now()->diffInSeconds($session->reading_started_at)
                : 0,
        ]);
    }

    /**
     * Student starts reading (called when student clicks "I'm Ready").
     */
    public function startReading(Request $request, AssessmentSession $session)
    {
        $learner = $request->attributes->get('learner');

        if ($session->learner_id !== $learner->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if ($session->status === 'ready') {
            $session->startReading();
        }

        ActivityLog::create([
            'user_id' => null,
            'action' => 'learner_start_reading',
            'description' => "Learner {$learner->first_name} {$learner->last_name} started reading for assessment session #{$session->id}",
            'subject_type' => 'learner',
            'subject_id' => $learner->id,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'created_at' => now(),
        ]);

        return response()->json(['status' => 'reading']);
    }

    /**
     * Student signals they finished reading.
     */
    public function finishReading(Request $request, AssessmentSession $session)
    {
        $learner = $request->attributes->get('learner');

        if ($session->learner_id !== $learner->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $session->complete();

        // Record activity & check badges
        $learner->recordActivity();
        app(BadgeService::class)->checkAndAward($learner);

        ActivityLog::create([
            'user_id' => null,
            'action' => 'learner_finish_reading',
            'description' => "Learner {$learner->first_name} {$learner->last_name} finished reading for assessment session #{$session->id}",
            'subject_type' => 'learner',
            'subject_id' => $learner->id,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'created_at' => now(),
        ]);

        return response()->json([
            'status' => 'completed',
            'message' => 'Great job! You finished reading!',
        ]);
    }

    /**
     * Upload audio from student device.
     */
    public function uploadAudio(Request $request, AssessmentSession $session)
    {
        $learner = $request->attributes->get('learner');

        if ($session->learner_id !== $learner->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $request->validate([
            'audio' => 'required|file|max:25600', // 25MB
        ]);

        $file = $request->file('audio');
        $filename = "assessment_{$session->assessment_id}_student_{$learner->id}." . $file->getClientOriginalExtension();
        $path = $file->storeAs('assessments/audio', $filename, 'public');

        // Update assessment with audio path
        $session->assessment->update(['audio_file_path' => $path]);

        ActivityLog::create([
            'user_id' => null,
            'action' => 'learner_upload_audio',
            'description' => "Learner {$learner->first_name} {$learner->last_name} uploaded audio for assessment session #{$session->id}",
            'subject_type' => 'learner',
            'subject_id' => $learner->id,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'created_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Audio uploaded successfully.',
        ]);
    }
}
