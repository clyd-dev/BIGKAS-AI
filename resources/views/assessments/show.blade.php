@extends('layouts.app')

@section('title', 'Assessment - ' . ($assessment->learner?->full_name ?? 'N/A'))

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-mic me-2"></i>Reading Assessment</h4>
        <a href="{{ route('assessments.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Back</a>
    </div>

    <div class="row g-3">
        {{-- Assessment Info --}}
        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white"><h6 class="mb-0">Assessment Details</h6></div>
                <div class="card-body">
                    <table class="table table-borderless table-sm mb-0">
                        <tr><th class="text-muted">Learner</th><td>{{ $assessment->learner?->full_name ?? 'N/A' }}</td></tr>
                        <tr><th class="text-muted">Material</th><td>{{ $assessment->material?->title ?? 'N/A' }}</td></tr>
                        <tr><th class="text-muted">Language</th><td>{{ ucfirst($assessment->language) }}</td></tr>
                        <tr><th class="text-muted">Type</th><td>{{ ucfirst(str_replace('_', ' ', $assessment->assessment_type)) }}</td></tr>
                        <tr><th class="text-muted">Status</th><td>
                            <span class="badge {{ $assessment->status === 'completed' ? 'bg-success' : ($assessment->status === 'processing' ? 'bg-info' : 'bg-secondary') }}">
                                {{ ucfirst(str_replace('_', ' ', $assessment->status)) }}
                            </span>
                        </td></tr>
                    </table>
                </div>
            </div>

            {{-- Audio Upload --}}
            @if($assessment->status === 'pending' || $assessment->status === 'processing')
                <div class="card border-0 shadow-sm mt-3">
                    <div class="card-header bg-white"><h6 class="mb-0">Audio Recording</h6></div>
                    <div class="card-body">
                        {{-- Browser Audio Recorder --}}
                        <div id="audioRecorder" class="text-center mb-3">
                            <input type="hidden" id="assessmentId" value="{{ $assessment->id }}">
                            
                            @if($assessment->status === 'pending')
                                <button id="btnStartRecording" class="btn btn-danger btn-lg rounded-pill px-4">
                                    <i class="bi bi-mic-fill me-2"></i> Start Reading
                                </button>
                                <button id="btnStopRecording" class="btn btn-secondary btn-lg rounded-pill px-4 d-none">
                                    <i class="bi bi-stop-fill me-2"></i> Stop Recording
                                </button>
                                
                                <div id="recordingTimer" class="mt-3 fw-bold text-danger">00:00</div>
                                <canvas id="audioVisualizer" width="300" height="60" class="mt-2 bg-light rounded"></canvas>
                            @endif
                            
                            <div class="mt-3">
                                @if($assessment->audio_file)
                                    <audio id="audioPlayback" controls class="w-100 mb-2" src="{{ $assessment->getAudioUrl() }}"></audio>
                                @else
                                    <audio id="audioPlayback" controls class="w-100 d-none mb-2"></audio>
                                @endif
                                
                                @if($assessment->status === 'processing')
                                    <form method="POST" action="{{ route('assessments.retry', $assessment) }}" class="d-inline">
                                        @csrf
                                        <button type="submit" class="btn btn-outline-secondary btn-sm" onclick="return confirm('Are you sure you want to discard this recording and retry?')">Retry</button>
                                    </form>
                                    <button id="btnAnalyze" class="btn btn-success btn-sm">
                                        <i class="bi bi-cpu me-1"></i> Analyze
                                    </button>
                                @else
                                    <button id="btnRetry" class="btn btn-outline-secondary btn-sm d-none">Retry</button>
                                    <button id="btnAnalyze" class="btn btn-success btn-sm d-none">
                                        <i class="bi bi-cpu me-1"></i> Analyze
                                    </button>
                                @endif
                            </div>
                        </div>

                        {{-- AI Results Area --}}
                        <div id="resultsArea" class="mt-4 p-3 border border-primary rounded bg-light d-none">
                            <h5 class="text-primary"><i class="bi bi-robot me-2"></i>AI Assessment Results</h5>
                            <div class="d-flex justify-content-between mt-3">
                                <div><small class="text-muted">Words Per Minute</small><br><strong class="fs-5" id="resWpm"></strong></div>
                                <div><small class="text-muted">Accuracy</small><br><strong class="fs-5" id="resAccuracy"></strong>%</div>
                            </div>
                            <hr>
                            <div class="mb-2">
                                <small class="text-muted">Diagnosed Weakness:</small>
                                <span id="resWeakness" class="badge bg-warning text-dark ms-2 fs-6"></span>
                            </div>
                            <div>
                                <small class="text-muted">AI Confidence:</small>
                                <span id="resConfidence" class="ms-2 fw-bold text-success"></span>%
                            </div>
                        </div>

                        {{--
                            Manual upload is an alternative to recording here, for
                            when the child can't read at this device. It's offered
                            only before any audio exists, and hidden as soon as
                            recording starts.
                        --}}
                        @if($assessment->status === 'pending' && !$assessment->audio_file)
                            <div id="uploadAudioSection">
                                <hr>
                                <form method="POST" action="{{ route('assessments.upload-audio', $assessment) }}" enctype="multipart/form-data">
                                    @csrf
                                    <div class="mb-2">
                                        <label class="form-label small mb-1">Can't record here? Upload an audio file instead:</label>
                                        <input type="file" class="form-control form-control-sm" name="audio" accept="audio/*" required>
                                    </div>
                                    <button type="submit" class="btn btn-sm btn-outline-primary w-100">
                                        <i class="bi bi-upload me-1"></i> Upload Audio
                                    </button>
                                </form>
                            </div>
                        @endif
                    </div>
                </div>
            @endif
        </div>

        {{-- Reading Passage + Comprehension --}}
        <div class="col-md-8">
            @if($questions->isNotEmpty())
                {{--
                    Comprehension test. Hidden until the recording stops, then the
                    teacher marks what the child answered (or hands over the
                    device). If the learner already answered from their own
                    portal, it renders read-only instead.
                --}}
                <div id="comprehensionPanel"
                     class="card border-0 shadow-sm mb-3 {{ $submittedAnswers->isEmpty() && $assessment->status !== 'processing' ? 'd-none' : '' }}"
                     data-prefilled="{{ $submittedAnswers->isNotEmpty() ? '1' : '0' }}">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <h6 class="mb-0"><i class="bi bi-patch-question me-1"></i> Comprehension Questions</h6>
                        @if($submittedAnswers->isNotEmpty())
                            <span class="badge bg-info">Answered by learner — {{ $assessment->comprehensionScore() }}%</span>
                        @else
                            <span class="badge bg-warning text-dark">Required before analyzing</span>
                        @endif
                    </div>
                    <div class="card-body">
                        @if($submittedAnswers->isNotEmpty())
                            <ol class="mb-0">
                                @foreach($submittedAnswers as $answer)
                                    <li class="mb-2">
                                        <div>{{ $answer->question_text }}</div>
                                        <div class="small">
                                            @if($answer->is_correct)
                                                <span class="text-success"><i class="bi bi-check-circle me-1"></i>{{ $answer->selected_text }}</span>
                                            @else
                                                <span class="text-danger"><i class="bi bi-x-circle me-1"></i>{{ $answer->selected_text ?? 'No answer' }}</span>
                                                <span class="text-muted ms-2">Correct: {{ $answer->correct_text }}</span>
                                            @endif
                                        </div>
                                    </li>
                                @endforeach
                            </ol>
                        @else
                            <p class="small text-muted">
                                Ask each question aloud and mark what the child answered — or hand them the device to tap their own answers.
                            </p>
                            @foreach($questions as $i => $question)
                                <div class="mb-3 pb-2 border-bottom comprehension-question" data-question-id="{{ $question->id }}">
                                    <p class="fw-semibold mb-2">{{ $i + 1 }}. {{ $question->question }}</p>
                                    <div class="d-flex flex-column gap-1">
                                        @foreach($question->getOptions() as $letter => $text)
                                            <label class="border rounded px-3 py-2 comprehension-option" style="cursor: pointer;">
                                                <input class="form-check-input me-2" type="radio"
                                                       name="comprehension[{{ $question->id }}]" value="{{ $letter }}">
                                                <strong class="me-1">{{ $letter }}.</strong> {{ $text }}
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                            <div id="comprehensionGateMsg" class="small text-muted">
                                Answer all {{ $questions->count() }} questions to enable Analyze.
                            </div>
                        @endif
                    </div>
                </div>
            @endif

            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h6 class="mb-0">Reading Passage</h6>
                    <span class="badge bg-light text-dark">{{ $assessment->material?->word_count ?? 0 }} words</span>
                </div>
                <div class="card-body">
                    <div class="reading-passage p-4 bg-light rounded" style="font-size: 1.3rem; line-height: 2;">
                        {{ $assessment->material?->content ?? 'No passage loaded.' }}
                    </div>
                </div>
            </div>
        </div>
    </div>

@push('scripts')
<script src="{{ asset('js/audio-recorder.js') }}"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Pass the CSRF token to our JS file
        window.BIGKAS_CSRF = '{{ csrf_token() }}';

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
                        } else if (currentStatus === 'processing') {
                            clearInterval(checkInterval);
                            window.location.reload(); // Reload to show Analyze button and audio playback
                        }
                    }
                });
            }, 3000);
        }
    });
</script>
@endpush
@endsection
