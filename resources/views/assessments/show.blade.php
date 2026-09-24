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
                            
                            <button id="btnStartRecording" class="btn btn-danger btn-lg rounded-pill px-4">
                                <i class="bi bi-mic-fill me-2"></i> Start Reading
                            </button>
                            <button id="btnStopRecording" class="btn btn-secondary btn-lg rounded-pill px-4 d-none">
                                <i class="bi bi-stop-fill me-2"></i> Stop Recording
                            </button>
                            
                            <div id="recordingTimer" class="mt-3 fw-bold text-danger">00:00</div>
                            <canvas id="audioVisualizer" width="300" height="60" class="mt-2 bg-light rounded"></canvas>
                            
                            <div class="mt-3">
                                @if($assessment->audio_file)
                                    <audio id="audioPlayback" controls class="w-100 mb-2" src="{{ Storage::url($assessment->audio_file) }}"></audio>
                                @else
                                    <audio id="audioPlayback" controls class="w-100 d-none mb-2"></audio>
                                @endif
                                <button id="btnRetry" class="btn btn-outline-secondary btn-sm d-none">Retry</button>
                                <button id="btnAnalyze" class="btn btn-success btn-sm d-none">
                                    <i class="bi bi-cpu me-1"></i> Analyze Reading
                                </button>
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

                        <hr>

                        {{-- Manual Upload --}}
                        <form method="POST" action="{{ route('assessments.upload-audio', $assessment) }}" enctype="multipart/form-data">
                            @csrf
                            <div class="mb-3">
                                <label class="form-label small">Or upload an audio file:</label>
                                <input type="file" class="form-control form-control-sm" name="audio" accept="audio/*" required>
                            </div>
                            <button type="submit" class="btn btn-sm btn-primary w-100">
                                <i class="bi bi-upload me-1"></i> Upload Audio
                            </button>
                        </form>

                        @if($assessment->status === 'processing')
                            <hr>
                            <form method="POST" action="{{ route('assessments.analyze', $assessment) }}">
                                @csrf
                                <button type="submit" class="btn btn-success w-100">
                                    <i class="bi bi-cpu me-1"></i> Analyze Reading
                                </button>
                            </form>
                        @endif
                    </div>
                </div>
            @endif
        </div>

        {{-- Reading Passage --}}
        <div class="col-md-8">
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
