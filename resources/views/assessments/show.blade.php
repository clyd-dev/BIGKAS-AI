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
                            <span class="badge {{ $assessment->status === 'completed' ? 'bg-success' : ($assessment->status === 'audio_uploaded' ? 'bg-info' : 'bg-secondary') }}">
                                {{ ucfirst(str_replace('_', ' ', $assessment->status)) }}
                            </span>
                        </td></tr>
                    </table>
                </div>
            </div>

            {{-- Audio Upload --}}
            @if($assessment->status === 'pending' || $assessment->status === 'audio_uploaded')
                <div class="card border-0 shadow-sm mt-3">
                    <div class="card-header bg-white"><h6 class="mb-0">Audio Recording</h6></div>
                    <div class="card-body">
                        {{-- Browser Audio Recorder --}}
                        <div id="audioRecorder" class="text-center mb-3">
                            <button id="recordBtn" class="btn btn-danger btn-lg rounded-circle" style="width: 80px; height: 80px;">
                                <i class="bi bi-mic-fill fs-3"></i>
                            </button>
                            <div id="recordingTimer" class="mt-2 fw-bold text-danger d-none">00:00</div>
                            <p class="small text-muted mt-2">Click to start/stop recording</p>
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

                        @if($assessment->status === 'audio_uploaded')
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
        if (typeof initAudioRecorder === 'function') {
            initAudioRecorder('{{ route("assessments.upload-audio", $assessment) }}', '{{ csrf_token() }}');
        }
    });
</script>
@endpush
@endsection
