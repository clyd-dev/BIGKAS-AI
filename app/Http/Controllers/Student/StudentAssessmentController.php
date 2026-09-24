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
    public function pending(Request $request)
    {
        $learner = $request->attributes->get('learner');
        $assessment = \App\Models\Assessment::where('learner_id', $learner->id)
            ->where('status', \App\Models\Assessment::STATUS_PENDING)
            ->latest()
            ->first();

        return response()->json([
            'has_pending' => (bool)$assessment,
            'assessment_id' => $assessment ? $assessment->id : null,
            'material_title' => $assessment && $assessment->material ? $assessment->material->title : null,
        ]);
    }

    public function read(Request $request, \App\Models\Assessment $assessment)
    {
        $learner = $request->attributes->get('learner');
        if ($assessment->learner_id !== $learner->id || $assessment->status !== \App\Models\Assessment::STATUS_PENDING) {
            return redirect()->route('student.dashboard')->with('error', 'No pending assessment found.');
        }
        
        $material = $assessment->material;
        return view('student.assessment.reading', compact('assessment', 'material', 'learner'));
    }

    public function start(Request $request, \App\Models\Assessment $assessment)
    {
        $learner = $request->attributes->get('learner');
        if ($assessment->learner_id !== $learner->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if ($assessment->status === \App\Models\Assessment::STATUS_PENDING) {
            $assessment->update(['status' => \App\Models\Assessment::STATUS_RECORDING]);
        }
        return response()->json(['success' => true]);
    }

    public function uploadAudio(Request $request, \App\Models\Assessment $assessment)
    {
        $learner = $request->attributes->get('learner');
        if ($assessment->learner_id !== $learner->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $request->validate(['audio' => 'required|file|mimes:mp3,wav,webm,ogg|max:25600']);

        $file = $request->file('audio');
        $filename = "assessment_{$assessment->id}_student_{$learner->id}." . $file->getClientOriginalExtension();
        $path = $file->storeAs('assessments/audio', $filename, 'public');

        $assessment->update([
            'audio_file' => $path,
            'status' => 'audio_uploaded'
        ]);

        return response()->json(['success' => true, 'message' => 'Audio uploaded successfully.']);
    }
}
