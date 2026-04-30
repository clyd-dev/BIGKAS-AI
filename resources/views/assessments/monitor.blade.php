@extends('layouts.app')

@section('title', 'Monitor Assessment')

@section('content')

<div class="row">
    <div class="col-lg-8">
        {{-- Session Info Header --}}
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <h4 class="fw-bold mb-1">
                            <i class="bi bi-broadcast text-primary me-2"></i>
                            Live Assessment Session
                        </h4>
                        <p class="text-muted mb-0">
                            {{ $learner->getFullName() }} &middot; Grade {{ $learner->grade_level }}
                        </p>
                    </div>
                    <div class="text-end">
                        <span class="badge fs-6 px-3 py-2" id="statusBadge"
                              style="background: #6C63FF;">
                            {{ ucfirst($session->status) }}
                        </span>
                        <div class="mt-1">
                            <small class="text-muted">Session Code:</small>
                            <span class="fw-bold text-primary">{{ $session->session_code }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Reading Material --}}
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white border-bottom">
                <h6 class="fw-bold mb-0">
                    <i class="bi bi-book me-1"></i>
                    {{ $material->title ?? 'Reading Material' }}
                    <span class="badge bg-secondary ms-2">Grade {{ $material->grade_level }}</span>
                </h6>
            </div>
            <div class="card-body">
                <div style="font-size: 1.1rem; line-height: 2; max-height: 350px; overflow-y: auto;" id="materialContent">
                    {{ $material->content ?? '' }}
                </div>
            </div>
        </div>

        {{-- Teacher Audio Recording --}}
        <div class="card border-0 shadow-sm mb-3" id="recordingCard">
            <div class="card-body text-center">
                <h6 class="fw-bold mb-3">
                    <i class="bi bi-mic-fill text-danger me-1"></i> Teacher Recording
                </h6>
                <p class="text-muted mb-3" style="font-size: 0.85rem;">
                    Record the student's reading from your device, or let the student record from theirs.
                </p>
                <button class="btn btn-danger btn-lg rounded-pill px-4" id="btnTeacherRecord" onclick="toggleTeacherRecording()">
                    <i class="bi bi-mic-fill me-1"></i>
                    <span id="recordBtnText">Start Recording</span>
                </button>
                <div id="recordingTimer" class="mt-2 text-danger fw-bold d-none">
                    <i class="bi bi-record-circle"></i> Recording: <span id="recTimer">0:00</span>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        {{-- Student Status Panel --}}
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white border-bottom">
                <h6 class="fw-bold mb-0">
                    <i class="bi bi-person-fill me-1"></i> Student Status
                </h6>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <small class="text-muted d-block">Student</small>
                    <strong>{{ $learner->getFullName() }}</strong>
                </div>
                <div class="mb-3">
                    <small class="text-muted d-block">PIN</small>
                    <strong class="text-primary">{{ $learner->pin ?? 'Not Set' }}</strong>
                </div>
                <div class="mb-3">
                    <small class="text-muted d-block">Connection Status</small>
                    <span id="connectionStatus">
                        <span class="badge bg-warning">Waiting for student...</span>
                    </span>
                </div>
                <div class="mb-3">
                    <small class="text-muted d-block">Elapsed Time</small>
                    <strong id="elapsedTime" class="fs-5">0:00</strong>
                </div>
                <div>
                    <small class="text-muted d-block">Session Status</small>
                    <strong id="sessionStatusText">{{ ucfirst($session->status) }}</strong>
                </div>
            </div>
        </div>

        {{-- Instructions for Student --}}
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white border-bottom">
                <h6 class="fw-bold mb-0">
                    <i class="bi bi-info-circle me-1"></i> Student Login Instructions
                </h6>
            </div>
            <div class="card-body">
                <ol class="mb-0" style="font-size: 0.85rem;">
                    <li class="mb-2">
                        Student goes to <strong class="text-primary">/student/login</strong>
                    </li>
                    <li class="mb-2">
                        Enters PIN: <strong class="text-primary">{{ $learner->pin ?? 'Generate PIN first!' }}</strong>
                    </li>
                    <li class="mb-2">
                        The assessment will appear on their dashboard
                    </li>
                    <li>
                        Student taps "Start Reading" when ready
                    </li>
                </ol>
            </div>
        </div>

        {{-- Actions --}}
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <h6 class="fw-bold mb-3">Actions</h6>

                <button class="btn btn-primary w-100 mb-2" id="btnStartRecording"
                        onclick="signalStartRecording()" disabled>
                    <i class="bi bi-play-fill me-1"></i> Signal Start Recording
                </button>

                <form method="POST" action="{{ route('assessments.session.cancel', $session) }}"
                      onsubmit="return confirm('Are you sure you want to cancel this session?');">
                    @csrf
                    <button type="submit" class="btn btn-outline-danger w-100">
                        <i class="bi bi-x-circle me-1"></i> Cancel Session
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

@endsection

@push('scripts')
<script>
    const POLL_URL = '{{ route("assessments.session.poll", $session) }}';
    const START_RECORDING_URL = '{{ route("assessments.session.start-recording", $session) }}';
    const UPLOAD_URL = '{{ route("assessments.upload-audio", $session->assessment_id) }}';

    let pollInterval = null;
    let elapsedSeconds = {{ $session->reading_started_at ? now()->diffInSeconds($session->reading_started_at) : 0 }};
    let timerInterval = null;
    let teacherRecorder = null;
    let teacherAudioChunks = [];
    let isTeacherRecording = false;
    let recTimerInterval = null;
    let recSeconds = 0;

    const statusColors = {
        waiting: '#ffc107',
        ready: '#17a2b8',
        reading: '#6C63FF',
        recording: '#dc3545',
        completed: '#28a745',
        cancelled: '#6c757d',
    };

    // ── Polling ──
    function startPolling() {
        pollInterval = setInterval(async () => {
            try {
                const response = await fetch(POLL_URL, {
                    headers: { 'Accept': 'application/json' }
                });
                const data = await response.json();

                updateUI(data);
            } catch (e) {
                console.error('Poll error:', e);
            }
        }, 3000);
    }

    function updateUI(data) {
        // Status badge
        const badge = document.getElementById('statusBadge');
        badge.textContent = data.status.charAt(0).toUpperCase() + data.status.slice(1);
        badge.style.background = statusColors[data.status] || '#6c757d';

        document.getElementById('sessionStatusText').textContent =
            data.status.charAt(0).toUpperCase() + data.status.slice(1);

        // Connection status
        const conn = document.getElementById('connectionStatus');
        if (data.student_joined) {
            conn.innerHTML = `<span class="badge bg-success">Connected</span>
                <small class="text-muted ms-1">${data.student_joined_at || ''}</small>`;
        }

        // Enable actions when student joins
        if (data.status === 'ready' || data.status === 'reading') {
            document.getElementById('btnStartRecording').disabled = false;
        }

        // Elapsed time
        if (data.elapsed > 0) {
            elapsedSeconds = data.elapsed;
            if (!timerInterval) startTimer();
        }

        // Completed
        if (data.status === 'completed') {
            if (pollInterval) clearInterval(pollInterval);
            if (timerInterval) clearInterval(timerInterval);
            conn.innerHTML = '<span class="badge bg-success">Session Completed</span>';
        }

        // Cancelled
        if (data.status === 'cancelled') {
            if (pollInterval) clearInterval(pollInterval);
            if (timerInterval) clearInterval(timerInterval);
        }
    }

    function startTimer() {
        timerInterval = setInterval(() => {
            elapsedSeconds++;
            const mins = Math.floor(elapsedSeconds / 60);
            const secs = elapsedSeconds % 60;
            document.getElementById('elapsedTime').textContent =
                `${mins}:${secs.toString().padStart(2, '0')}`;
        }, 1000);
    }

    // ── Signal Start Recording ──
    async function signalStartRecording() {
        try {
            const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
            await fetch(START_RECORDING_URL, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                },
            });
        } catch (e) {
            console.error('Start recording signal error:', e);
        }
    }

    // ── Teacher Audio Recording ──
    async function toggleTeacherRecording() {
        const btn = document.getElementById('btnTeacherRecord');
        const btnText = document.getElementById('recordBtnText');
        const timerDiv = document.getElementById('recordingTimer');

        if (isTeacherRecording) {
            // Stop
            teacherRecorder.stop();
            isTeacherRecording = false;
            btnText.textContent = 'Start Recording';
            btn.classList.remove('btn-outline-danger');
            btn.classList.add('btn-danger');
            timerDiv.classList.add('d-none');
            if (recTimerInterval) clearInterval(recTimerInterval);
        } else {
            // Start
            try {
                const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                teacherRecorder = new MediaRecorder(stream);
                teacherAudioChunks = [];

                teacherRecorder.ondataavailable = (e) => {
                    if (e.data.size > 0) teacherAudioChunks.push(e.data);
                };

                teacherRecorder.onstop = () => {
                    stream.getTracks().forEach(t => t.stop());
                    const blob = new Blob(teacherAudioChunks, { type: 'audio/webm' });
                    uploadTeacherAudio(blob);
                };

                teacherRecorder.start();
                isTeacherRecording = true;
                btnText.textContent = 'Stop Recording';
                btn.classList.remove('btn-danger');
                btn.classList.add('btn-outline-danger');
                timerDiv.classList.remove('d-none');

                recSeconds = 0;
                recTimerInterval = setInterval(() => {
                    recSeconds++;
                    const m = Math.floor(recSeconds / 60);
                    const s = recSeconds % 60;
                    document.getElementById('recTimer').textContent =
                        `${m}:${s.toString().padStart(2, '0')}`;
                }, 1000);
            } catch (e) {
                alert('Could not access microphone. Please check permissions.');
                console.error('Mic error:', e);
            }
        }
    }

    async function uploadTeacherAudio(blob) {
        const formData = new FormData();
        formData.append('audio', blob, 'teacher_recording.webm');

        const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
        try {
            const resp = await fetch(UPLOAD_URL, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                },
                body: formData,
            });
            const data = await resp.json();
            if (data.success) {
                alert('Audio uploaded successfully!');
            }
        } catch (e) {
            console.error('Upload error:', e);
            alert('Failed to upload audio. Please try again.');
        }
    }

    // Init
    document.addEventListener('DOMContentLoaded', () => {
        const status = '{{ $session->status }}';
        if (status !== 'completed' && status !== 'cancelled') {
            startPolling();
        }
        if (status === 'reading' || status === 'recording') {
            startTimer();
            document.getElementById('btnStartRecording').disabled = false;
        }
    });
</script>
@endpush
