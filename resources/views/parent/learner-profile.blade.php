@extends('layouts.app')

@section('title', $learner->full_name . ' - Reading Profile')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-person-lines-fill me-2"></i>{{ $learner->full_name }}</h4>
        <div>
            <a href="{{ route('parent.children.assessments', $learner) }}" class="btn btn-outline-success btn-sm">
                <i class="bi bi-clipboard-data me-1"></i>Assessment Results
            </a>
            <a href="{{ route('parent.children.interventions', $learner) }}" class="btn btn-outline-warning btn-sm">
                <i class="bi bi-lightbulb me-1"></i>Home Activities
            </a>
            <a href="{{ route('parent.dashboard') }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i>Back
            </a>
        </div>
    </div>

    {{-- Profile Info + Reading Level --}}
    <div class="row g-3 mb-4">
        <div class="col-md-2">
            <div class="card border-0 shadow-sm text-center">
                <div class="card-body py-3">
                    @if($learner->reading_level === 'independent')
                        <span class="badge bg-success fs-6 px-3 py-2">Independent</span>
                    @elseif($learner->reading_level === 'instructional')
                        <span class="badge bg-warning text-dark fs-6 px-3 py-2">Instructional</span>
                    @elseif($learner->reading_level === 'frustration')
                        <span class="badge bg-danger fs-6 px-3 py-2">Frustration</span>
                    @else
                        <span class="badge bg-secondary fs-6 px-3 py-2">N/A</span>
                    @endif
                    <div class="small text-muted mt-1">Reading Level</div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card border-0 shadow-sm text-center">
                <div class="card-body py-3">
                    <h4 class="mb-0">{{ number_format($stats['avg_accuracy'] ?? 0, 1) }}%</h4>
                    <div class="small text-muted">Avg Accuracy</div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card border-0 shadow-sm text-center">
                <div class="card-body py-3">
                    <h4 class="mb-0">{{ round($stats['avg_wpm'] ?? 0) }}</h4>
                    <div class="small text-muted">Avg WPM</div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card border-0 shadow-sm text-center">
                <div class="card-body py-3">
                    <h4 class="mb-0">{{ $stats['total_assessments'] ?? 0 }}</h4>
                    <div class="small text-muted">Assessments</div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card border-0 shadow-sm text-center">
                <div class="card-body py-3">
                    <h4 class="mb-0">Grade {{ $learner->grade_level }}</h4>
                    <div class="small text-muted">Grade Level</div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card border-0 shadow-sm text-center">
                <div class="card-body py-3">
                    <h4 class="mb-0">{{ ucfirst($learner->mother_tongue ?? 'N/A') }}</h4>
                    <div class="small text-muted">Mother Tongue</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Progress Charts + Skill Breakdown side by side --}}
    <div class="row g-3 mb-4">
        {{-- Charts --}}
        <div class="col-md-6">
            <div class="row g-3">
                <div class="col-md-6">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-white"><h6 class="mb-0">Accuracy Trend</h6></div>
                        <div class="card-body"><canvas id="accuracyTrend" height="250"></canvas></div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-white"><h6 class="mb-0">WPM Trend</h6></div>
                        <div class="card-body"><canvas id="wpmTrend" height="250"></canvas></div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Skill Breakdown --}}
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white"><h6 class="mb-0">Skill Breakdown</h6></div>
                <div class="card-body">
                    <div class="row g-3">
                        @foreach(['phonemic' => 'Phonemic Awareness', 'decoding' => 'Decoding Accuracy', 'fluency' => 'Oral Reading Fluency', 'comprehension' => 'Reading Comprehension'] as $key => $label)
                            <div class="col-md-6">
                                <h6 class="small text-muted text-center">{{ $label }}</h6>
                                <div class="progress mb-1" style="height: 10px;">
                                    <div class="progress-bar {{ ($skillScores[$key] ?? 0) >= 80 ? 'bg-success' : (($skillScores[$key] ?? 0) >= 60 ? 'bg-warning' : 'bg-danger') }}"
                                        style="width: {{ $skillScores[$key] ?? 0 }}%"></div>
                                </div>
                                <p class="text-center fw-bold small mb-0">{{ number_format($skillScores[$key] ?? 0, 1) }}%</p>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        {{-- Recent Interventions --}}
        <div class="col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><i class="bi bi-lightbulb me-1"></i> Recent Interventions</h6>
                    <a href="{{ route('parent.children.interventions', $learner) }}" class="btn btn-sm btn-outline-primary">View All</a>
                </div>
                <div class="card-body p-0">
                    <div class="list-group list-group-flush">
                        @forelse($interventionLogs as $log)
                            <div class="list-group-item">
                                <div class="d-flex justify-content-between">
                                    <strong class="small">{{ $log->intervention?->name ?? 'N/A' }}</strong>
                                    <span class="badge {{ $log->status === 'completed' ? 'bg-success' : ($log->status === 'in_progress' ? 'bg-primary' : 'bg-secondary') }}">
                                        {{ ucfirst(str_replace('_', ' ', $log->status)) }}
                                    </span>
                                </div>
                                <small class="text-muted">{{ $log->created_at?->diffForHumans() }}</small>
                            </div>
                        @empty
                            <div class="list-group-item text-muted small text-center">No interventions yet</div>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>

        {{-- Recent Practice Sessions --}}
        <div class="col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h6 class="mb-0"><i class="bi bi-controller me-1"></i> Recent Practice Sessions</h6>
                </div>
                <div class="card-body p-0">
                    <div class="list-group list-group-flush">
                        @forelse($recentPractice as $session)
                            <div class="list-group-item">
                                <div class="d-flex justify-content-between">
                                    <strong class="small">{{ ucfirst(str_replace('_', ' ', $session->session_type)) }}</strong>
                                    @if($session->score !== null)
                                        <span class="badge {{ $session->score >= 80 ? 'bg-success' : ($session->score >= 60 ? 'bg-warning text-dark' : 'bg-danger') }}">
                                            {{ $session->score }}%
                                        </span>
                                    @endif
                                </div>
                                <small class="text-muted">
                                    {{ $session->time_spent ? gmdate('i:s', $session->time_spent) : '-' }}
                                    &middot; {{ $session->created_at?->diffForHumans() }}
                                </small>
                            </div>
                        @empty
                            <div class="list-group-item text-muted small text-center">No practice sessions yet</div>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    </div>

@push('scripts')
<script>
    const dates = {!! json_encode($progressDates ?? []) !!};
    new Chart(document.getElementById('accuracyTrend'), {
        type: 'line', data: { labels: dates, datasets: [{ label: 'Accuracy %', data: {!! json_encode($progressAccuracy ?? []) !!}, borderColor: '#0d6efd', backgroundColor: 'rgba(13,110,253,0.1)', fill: true, tension: 0.3 }] },
        options: { responsive: true, scales: { y: { beginAtZero: true, max: 100 } } }
    });
    new Chart(document.getElementById('wpmTrend'), {
        type: 'line', data: { labels: dates, datasets: [{ label: 'WPM', data: {!! json_encode($progressWpm ?? []) !!}, borderColor: '#198754', backgroundColor: 'rgba(25,135,84,0.1)', fill: true, tension: 0.3 }] },
        options: { responsive: true, scales: { y: { beginAtZero: true } } }
    });
</script>
@endpush
@endsection
