# Dual-Mode Virtual & Local Reading Assessment Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Connect the Teacher's "Reading Assessment" page with the Learner Portal to allow remote/virtual assessment recording on the student's device, or fallback to local recording on the teacher's device.

**Architecture:** Use Javascript polling against the existing `Assessment` model to synchronize state between the teacher's dashboard and the learner's dashboard. A dual-mode system removes the need for a separate `AssessmentSession` entity.

**Tech Stack:** Laravel, Blade, JavaScript (vanilla/AJAX)

## Global Constraints
- Laravel naming conventions for routes and controllers.
- Polling intervals should be 3-5 seconds.
- Audio constraints: max 25MB, formats: mp3, wav, webm, ogg.

---

### Task 1: Update Teacher Controller & Route for Status Polling

**Files:**
- Modify: `routes/web.php`
- Modify: `app/Http/Controllers/AssessmentController.php`

**Interfaces:**
- Produces: `GET /assessments/{assessment}/status` returning `{"status": "pending|recording|audio_uploaded|completed"}`.

- [ ] **Step 1: Add Route**
Modify `routes/web.php` under the assessments group:
```php
Route::get('/assessments/{assessment}/status', [AssessmentController::class, 'status'])->name('assessments.status');
```

- [ ] **Step 2: Add status method to Controller**
Modify `app/Http/Controllers/AssessmentController.php`:
```php
public function status(Assessment $assessment)
{
    $this->authorizeLearnerAccess($assessment->learner);
    return response()->json(['status' => $assessment->status]);
}
```

- [ ] **Step 3: Commit**
```bash
git add routes/web.php app/Http/Controllers/AssessmentController.php
git commit -m "feat: add teacher polling endpoint for assessment status"
```

---

### Task 2: Refactor Student Assessment Routes and Controller

**Files:**
- Modify: `routes/web.php`
- Modify: `app/Http/Controllers/Student/StudentAssessmentController.php`

**Interfaces:**
- Consumes: the `student.auth` middleware.
- Produces: `GET /student/assessment/pending`, `GET /student/assessment/{assessment}/read`, `POST /student/assessment/{assessment}/start`, `POST /student/assessment/{assessment}/upload-audio`.

- [ ] **Step 1: Update Routes**
Modify `routes/web.php` in the `student.auth` group to remove the old `{session}` routes and add:
```php
Route::get('/assessment/pending', [StudentAssessmentController::class, 'pending'])->name('assessment.pending');
Route::get('/assessment/{assessment}/read', [StudentAssessmentController::class, 'read'])->name('assessment.read');
Route::post('/assessment/{assessment}/start', [StudentAssessmentController::class, 'start'])->name('assessment.start');
Route::post('/assessment/{assessment}/upload-audio', [StudentAssessmentController::class, 'uploadAudio'])->name('assessment.upload-audio');
```

- [ ] **Step 2: Update Controller Methods**
Modify `app/Http/Controllers/Student/StudentAssessmentController.php`:
Remove all existing methods in `StudentAssessmentController` and replace them with:
```php
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
```

- [ ] **Step 3: Commit**
```bash
git add routes/web.php app/Http/Controllers/Student/StudentAssessmentController.php
git commit -m "feat: refactor student assessment endpoints for dual-mode"
```

---

### Task 3: Learner Dashboard Polling UI

**Files:**
- Modify: `resources/views/student/dashboard.blade.php`

**Interfaces:**
- Consumes: `GET /student/assessment/pending`

- [ ] **Step 1: Add Polling Script to Dashboard**
At the bottom of `resources/views/student/dashboard.blade.php`, inside `@push('scripts')`:
```html
<script>
document.addEventListener('DOMContentLoaded', function() {
    let checkInterval = setInterval(checkPendingAssessment, 3000);

    function checkPendingAssessment() {
        fetch('{{ route("student.assessment.pending") }}', {
            headers: { 'Accept': 'application/json' }
        })
        .then(res => res.json())
        .then(data => {
            if (data.has_pending) {
                clearInterval(checkInterval); // Stop polling once found
                if (confirm('Your teacher has an assessment ready for you: ' + data.material_title + '\n\nClick OK to start!')) {
                    window.location.href = '/student/assessment/' + data.assessment_id + '/read';
                }
            }
        });
    }
});
</script>
```

- [ ] **Step 2: Commit**
```bash
git add resources/views/student/dashboard.blade.php
git commit -m "feat: add learner portal polling for pending assessments"
```

---

### Task 4: Learner Reading View Update

**Files:**
- Modify/Create: `resources/views/student/assessment/reading.blade.php`

**Interfaces:**
- Consumes: the `assessment` and `material` passed from Task 2.
- Consumes: existing JS logic from `public/js/audio-recorder.js` if applicable, or write inline.

- [ ] **Step 1: Build the Learner Reading View**
Update `resources/views/student/assessment/reading.blade.php`:
```html
@extends('layouts.student')
@section('title', 'Reading Assessment')
@section('content')
<div class="container mt-4 text-center">
    <h2>{{ $material->title }}</h2>
    
    <div id="setup-section">
        <p>When you are ready, click Start Recording and read the passage below.</p>
        <button id="btnStartLearner" class="btn btn-danger btn-lg rounded-pill px-4">
            <i class="bi bi-mic-fill"></i> Start Recording
        </button>
    </div>

    <div id="recording-section" class="d-none mt-3">
        <h4 class="text-danger blink">🔴 Recording...</h4>
        <div class="reading-passage p-4 bg-light rounded text-start mx-auto mt-4" style="max-width: 800px; font-size: 1.5rem; line-height: 2;">
            {{ $material->content }}
        </div>
        <button id="btnFinishLearner" class="btn btn-success btn-lg mt-4 px-4 rounded-pill">
            <i class="bi bi-check-circle"></i> I'm Done!
        </button>
    </div>

    <div id="uploading-section" class="d-none mt-3">
        <h4>Uploading your reading...</h4>
        <div class="spinner-border text-primary" role="status"></div>
    </div>
</div>

<script>
    let mediaRecorder;
    let audioChunks = [];

    document.getElementById('btnStartLearner').addEventListener('click', async () => {
        // 1. Tell server we started
        await fetch(`{{ route('student.assessment.start', $assessment->id) }}`, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Accept': 'application/json'
            }
        });

        // 2. Start recording
        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        mediaRecorder = new MediaRecorder(stream);
        mediaRecorder.ondataavailable = e => { if (e.data.size > 0) audioChunks.push(e.data); };
        mediaRecorder.start();

        document.getElementById('setup-section').classList.add('d-none');
        document.getElementById('recording-section').classList.remove('d-none');
    });

    document.getElementById('btnFinishLearner').addEventListener('click', () => {
        mediaRecorder.stop();
        document.getElementById('recording-section').classList.add('d-none');
        document.getElementById('uploading-section').classList.remove('d-none');

        mediaRecorder.onstop = async () => {
            const audioBlob = new Blob(audioChunks, { type: 'audio/webm' });
            const formData = new FormData();
            formData.append('audio', audioBlob, 'recording.webm');

            await fetch(`{{ route('student.assessment.upload-audio', $assessment->id) }}`, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json'
                },
                body: formData
            });

            alert('Great job! Your reading was sent to your teacher.');
            window.location.href = '{{ route("student.dashboard") }}';
        };
    });
</script>
@endsection
```

- [ ] **Step 2: Commit**
```bash
git add resources/views/student/assessment/reading.blade.php
git commit -m "feat: add learner reading interface for recording audio"
```

---

### Task 5: Teacher Assessment Polling UI

**Files:**
- Modify: `resources/views/assessments/show.blade.php`

**Interfaces:**
- Consumes: `GET /assessments/{assessment}/status`

- [ ] **Step 1: Add JS Poller for Dual Mode Updates**
In `resources/views/assessments/show.blade.php`, at the bottom of the `@push('scripts')` section:
```html
<script>
document.addEventListener('DOMContentLoaded', function() {
    const assessmentId = document.getElementById('assessmentId').value;
    let currentStatus = '{{ $assessment->status }}';

    if (currentStatus === 'pending' || currentStatus === 'recording') {
        let checkInterval = setInterval(() => {
            fetch(`/assessments/${assessmentId}/status`, {
                headers: { 'Accept': 'application/json' }
            })
            .then(res => res.json())
            .then(data => {
                if (data.status !== currentStatus) {
                    currentStatus = data.status;
                    if (currentStatus === 'recording') {
                        // Remote student started recording
                        document.getElementById('btnStartRecording').classList.add('d-none');
                        document.getElementById('btnStartRecording').insertAdjacentHTML('afterend', '<div class="alert alert-info">Student is currently recording remotely...</div>');
                    } else if (currentStatus === 'audio_uploaded') {
                        clearInterval(checkInterval);
                        window.location.reload(); // Reload to show Analyze button and audio playback
                    }
                }
            });
        }, 3000);
    }
});
</script>
```

- [ ] **Step 2: Commit**
```bash
git add resources/views/assessments/show.blade.php
git commit -m "feat: add teacher dashboard polling for remote student updates"
```
