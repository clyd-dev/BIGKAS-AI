@extends('layouts.student')

@section('title', 'Reading Assessment')

@section('content')

    {{-- Session Status Header --}}
    <div class="kid-card kid-card-colored kid-card-purple mb-3">
        <div class="d-flex align-items-center justify-content-between">
            <div>
                <h5 class="fw-bold mb-1">Reading Assessment</h5>
                <p class="mb-0" style="opacity: 0.9; font-size: 0.85rem;">
                    {{ $material->title ?? 'Reading Passage' }}
                </p>
            </div>
            <div class="text-end">
                <div class="badge bg-light text-dark px-3 py-2 fw-bold" id="sessionStatus">
                    {{ ucfirst($session->status) }}
                </div>
                <div class="mt-1" style="font-size: 0.8rem; opacity: 0.9;">
                    <i class="bi bi-clock"></i>
                    <span id="elapsedTimer">0:00</span>
                </div>
            </div>
        </div>
    </div>

    {{-- Waiting State --}}
    <div id="stateWaiting" class="{{ in_array($session->status, ['waiting', 'ready']) ? '' : 'd-none' }}">
        <div class="kid-card text-center py-5">
            <div style="font-size: 4rem;" class="mb-3">⏳</div>
            @if($session->status === 'waiting')
                <h4 class="fw-bold">Connecting...</h4>
                <p class="text-muted">Please wait while we connect with your teacher.</p>
            @else
                <h4 class="fw-bold">Ready to Read!</h4>
                <p class="text-muted mb-4">Your teacher is connected. When you're ready, tap the button below.</p>
                <button class="btn btn-kid btn-kid-success btn-lg px-5" id="btnReady" onclick="startReading()">
                    I'm Ready! 🚀
                </button>
            @endif
        </div>
    </div>

    {{-- Reading State --}}
    <div id="stateReading" class="{{ in_array($session->status, ['reading', 'recording']) ? '' : 'd-none' }}">
        {{-- Reading Passage --}}
        <div class="reading-passage mb-3" id="readingPassage">
            @foreach(preg_split('/\s+/', $material->content ?? '') as $index => $word)
                <span class="word" data-index="{{ $index }}">{{ $word }}</span>
            @endforeach
        </div>

        {{-- Word Tracker (tap to advance) --}}
        <div class="text-center text-muted mb-2" style="font-size: 0.8rem;">
            <i class="bi bi-hand-index"></i> Tap each word as you read it
        </div>

        {{-- Recording Controls --}}
        <div class="kid-card text-center py-3">
            <p class="fw-bold mb-2" id="recordLabel">
                <span class="text-success"><i class="bi bi-mic-fill"></i> Your teacher may be recording</span>
            </p>

            {{-- Student can also record from their device --}}
            <div class="d-flex justify-content-center gap-3 align-items-center">
                <button class="record-btn-student" id="btnRecord" onclick="toggleStudentRecording()"
                        style="background: var(--kid-primary-light); border-color: var(--kid-primary); color: var(--kid-primary);"
                        title="Record from your device">
                    <i class="bi bi-mic-fill"></i>
                </button>
            </div>
            <p class="text-muted mt-2 mb-0" style="font-size: 0.75rem;" id="recordHint">
                Tap to record from your device (optional)
            </p>
        </div>

        {{-- Finish Button --}}
        <div class="text-center mt-3">
            <button class="btn btn-kid btn-kid-warning btn-lg px-5" id="btnFinish" onclick="finishReading()">
                I'm Done Reading ✅
            </button>
        </div>
    </div>

    {{-- Completed State --}}
    <div id="stateCompleted" class="{{ $session->status === 'completed' ? '' : 'd-none' }}">
        <div class="kid-card text-center py-5">
            <div style="font-size: 4rem;" class="mb-3">🌟</div>
            <h4 class="fw-bold text-success">Great Job!</h4>
            <p class="text-muted mb-4">You finished reading! Your teacher will review your results.</p>
            <a href="{{ route('student.dashboard') }}" class="btn btn-kid btn-kid-primary">
                Back to Home
            </a>
        </div>
    </div>

@endsection

@push('scripts')
<script>
    const SESSION_ID = {{ $session->id }};
    const POLL_URL = '{{ route('student.assessment.poll', $session) }}';
    const START_URL = '{{ route('student.assessment.start-reading', $session) }}';
    const FINISH_URL = '{{ route('student.assessment.finish', $session) }}';
    const UPLOAD_URL = '{{ route('student.assessment.upload-audio', $session) }}';

    let currentWordIndex = 0;
    let timerInterval = null;
    let elapsedSeconds = {{ $session->reading_started_at ? now()->diffInSeconds($session->reading_started_at) : 0 }};
    let mediaRecorder = null;
    let audioChunks = [];
    let isRecording = false;

    // ── Timer ──
    function startTimer() {
        timerInterval = setInterval(() => {
            elapsedSeconds++;
            const mins = Math.floor(elapsedSeconds / 60);
            const secs = elapsedSeconds % 60;
            document.getElementById('elapsedTimer').textContent =
                `${mins}:${secs.toString().padStart(2, '0')}`;
        }, 1000);
    }

    // ── Word Tracking ──
    function initWordTracking() {
        const words = document.querySelectorAll('#readingPassage .word');
        if (!words.length) return;

        // Highlight first word
        words[0]?.classList.add('current');

        words.forEach((word, idx) => {
            word.addEventListener('click', () => {
                // Mark all words up to and including clicked as read
                words.forEach((w, i) => {
                    w.classList.remove('current');
                    if (i <= idx) {
                        w.classList.add('read');
                    }
                });
                // Set next word as current
                if (idx + 1 < words.length) {
                    words[idx + 1].classList.add('current');
                    currentWordIndex = idx + 1;

                    // Auto-scroll to keep current word visible
                    words[idx + 1].scrollIntoView({ behavior: 'smooth', block: 'center' });
                } else {
                    currentWordIndex = words.length;
                }
            });
        });
    }

    // ── Start Reading ──
    async function startReading() {
        const btn = document.getElementById('btnReady');
        btn.disabled = true;
        btn.textContent = 'Starting...';

        try {
            await StudentPortal.post(START_URL);
            showState('reading');
            startTimer();
            initWordTracking();
        } catch (e) {
            console.error('Start error:', e);
            btn.disabled = false;
            btn.textContent = "I'm Ready! 🚀";
        }
    }

    // ── Finish Reading ──
    async function finishReading() {
        const btn = document.getElementById('btnFinish');
        btn.disabled = true;
        btn.textContent = 'Saving...';

        // Stop recording if active
        if (isRecording && mediaRecorder) {
            mediaRecorder.stop();
        }

        try {
            const data = await StudentPortal.post(FINISH_URL);
            if (timerInterval) clearInterval(timerInterval);

            showState('completed');
            StudentPortal.celebrate('Great Job!', 'You finished reading!', '🌟');
        } catch (e) {
            console.error('Finish error:', e);
            btn.disabled = false;
            btn.textContent = "I'm Done Reading ✅";
        }
    }

    // ── Student Recording ──
    async function toggleStudentRecording() {
        const btn = document.getElementById('btnRecord');
        const hint = document.getElementById('recordHint');

        if (isRecording) {
            // Stop
            mediaRecorder.stop();
            btn.classList.remove('recording');
            btn.innerHTML = '<i class="bi bi-mic-fill"></i>';
            btn.style.background = 'var(--kid-primary-light)';
            btn.style.borderColor = 'var(--kid-primary)';
            btn.style.color = 'var(--kid-primary)';
            hint.textContent = 'Recording saved!';
            isRecording = false;
        } else {
            // Start
            try {
                const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                mediaRecorder = new MediaRecorder(stream);
                audioChunks = [];

                mediaRecorder.ondataavailable = (e) => {
                    if (e.data.size > 0) audioChunks.push(e.data);
                };

                mediaRecorder.onstop = () => {
                    stream.getTracks().forEach(t => t.stop());
                    const blob = new Blob(audioChunks, { type: 'audio/webm' });
                    uploadAudio(blob);
                };

                mediaRecorder.start();
                isRecording = true;
                btn.classList.add('recording');
                btn.innerHTML = '<i class="bi bi-stop-fill"></i>';
                hint.textContent = 'Recording... tap to stop';
            } catch (e) {
                hint.textContent = 'Could not access microphone';
                console.error('Mic error:', e);
            }
        }
    }

    async function uploadAudio(blob) {
        const formData = new FormData();
        formData.append('audio', blob, 'student_recording.webm');

        try {
            await fetch(UPLOAD_URL, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': StudentPortal.csrfToken,
                    'Accept': 'application/json',
                },
                body: formData,
            });
        } catch (e) {
            console.error('Upload error:', e);
        }
    }

    // ── State Management ──
    function showState(state) {
        document.getElementById('stateWaiting').classList.add('d-none');
        document.getElementById('stateReading').classList.add('d-none');
        document.getElementById('stateCompleted').classList.add('d-none');

        const el = document.getElementById('state' + state.charAt(0).toUpperCase() + state.slice(1));
        if (el) el.classList.remove('d-none');

        document.getElementById('sessionStatus').textContent = state.charAt(0).toUpperCase() + state.slice(1);
    }

    // ── Polling ──
    function startPolling() {
        initAssessmentPolling(SESSION_ID, POLL_URL);
    }

    // ── Init ──
    document.addEventListener('DOMContentLoaded', () => {
        const status = '{{ $session->status }}';

        if (status === 'reading' || status === 'recording') {
            startTimer();
            initWordTracking();
        }

        if (status !== 'completed') {
            startPolling();
        }
    });
</script>
@endpush
