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

    @if($questions->isNotEmpty())
        {{-- Comprehension questions: answered before anything is sent to the teacher --}}
        <div id="questions-section" class="d-none mt-3">
            <h4 class="mb-1">Now answer the questions 📖</h4>
            <p class="text-muted">Pick the best answer for each one.</p>

            <div id="questionsAlert" class="alert alert-warning d-none mx-auto" style="max-width: 800px;">
                Please answer every question before sending.
            </div>

            <div class="mx-auto text-start" style="max-width: 800px;">
                @foreach($questions as $i => $question)
                    <div class="card shadow-sm mb-3 question-card" data-question-id="{{ $question->id }}">
                        <div class="card-body">
                            <p class="fw-bold mb-3" style="font-size: 1.25rem;">
                                {{ $i + 1 }}. {{ $question->question }}
                            </p>
                            @foreach($question->getOptions() as $letter => $text)
                                <label class="d-block border rounded p-3 mb-2 answer-option" style="cursor: pointer; font-size: 1.1rem;">
                                    <input type="radio" class="form-check-input me-2"
                                           name="answer_{{ $question->id }}" value="{{ $letter }}">
                                    <strong class="me-1">{{ $letter }}.</strong> {{ $text }}
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>

            <button id="btnSendLearner" class="btn btn-primary btn-lg mt-3 px-4 rounded-pill">
                <i class="bi bi-send"></i> Send to Teacher
            </button>
        </div>
    @endif

    <div id="uploading-section" class="d-none mt-3">
        <h4>Sending your work...</h4>
        <div class="spinner-border text-primary" role="status"></div>
    </div>
</div>

<script>
    const HAS_QUESTIONS = @json($questions->isNotEmpty());
    const QUESTION_IDS = @json($questions->pluck('id'));

    let mediaRecorder;
    let audioChunks = [];
    let audioBlob = null;

    const setupSection = document.getElementById('setup-section');
    const recordingSection = document.getElementById('recording-section');
    const questionsSection = document.getElementById('questions-section');
    const uploadingSection = document.getElementById('uploading-section');

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

        mediaRecorder.onstop = () => {
            audioBlob = new Blob(audioChunks, { type: 'audio/webm' });

            // With questions, the learner answers them before anything is sent.
            if (HAS_QUESTIONS) {
                questionsSection.classList.remove('d-none');
            } else {
                sendWork();
            }
        };

        mediaRecorder.start();

        setupSection.classList.add('d-none');
        recordingSection.classList.remove('d-none');
    });

    document.getElementById('btnFinishLearner').addEventListener('click', () => {
        recordingSection.classList.add('d-none');
        mediaRecorder.stop();
    });

    if (HAS_QUESTIONS) {
        // Highlight the chosen option so it's obvious on a small screen.
        document.querySelectorAll('.answer-option input').forEach(input => {
            input.addEventListener('change', () => {
                const card = input.closest('.question-card');
                card.querySelectorAll('.answer-option').forEach(opt => {
                    opt.classList.remove('border-primary', 'bg-primary-subtle');
                });
                input.closest('.answer-option').classList.add('border-primary', 'bg-primary-subtle');
            });
        });

        document.getElementById('btnSendLearner').addEventListener('click', () => {
            const unanswered = QUESTION_IDS.filter(
                id => !document.querySelector(`input[name="answer_${id}"]:checked`)
            );

            if (unanswered.length > 0) {
                document.getElementById('questionsAlert').classList.remove('d-none');
                return;
            }

            questionsSection.classList.add('d-none');
            sendWork();
        });
    }

    async function sendWork() {
        uploadingSection.classList.remove('d-none');

        const formData = new FormData();
        formData.append('audio', audioBlob, 'recording.webm');

        QUESTION_IDS.forEach(id => {
            const picked = document.querySelector(`input[name="answer_${id}"]:checked`);
            if (picked) formData.append(`answers[${id}]`, picked.value);
        });

        try {
            let response = await fetch(`{{ route('student.assessment.upload-audio', $assessment->id) }}`, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json'
                },
                body: formData
            });

            let data = await response.json();

            if (response.ok) {
                alert('Great job! Your work was sent to your teacher.');
                window.location.href = '{{ route("student.dashboard") }}';
            } else {
                alert('Error sending: ' + (data.message || 'Unknown error'));
                uploadingSection.classList.add('d-none');
                if (HAS_QUESTIONS) questionsSection.classList.remove('d-none');
            }
        } catch (err) {
            alert('Sending failed: ' + err.message);
            uploadingSection.classList.add('d-none');
            if (HAS_QUESTIONS) questionsSection.classList.remove('d-none');
        }
    }
</script>
@endsection
