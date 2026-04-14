@extends('layouts.app')

@section('title', 'My Progress')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-graph-up me-2"></i>My Reading Progress</h4>
    </div>

    @if($learner ?? false)
        {{-- Summary Cards --}}
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="card border-0 shadow-sm text-center">
                    <div class="card-body">
                        @if($learner->reading_level === 'independent')
                            <span class="badge bg-success fs-6 px-3 py-2">Independent</span>
                        @elseif($learner->reading_level === 'instructional')
                            <span class="badge bg-warning text-dark fs-6 px-3 py-2">Instructional</span>
                        @elseif($learner->reading_level === 'frustration')
                            <span class="badge bg-danger fs-6 px-3 py-2">Frustration</span>
                        @else
                            <span class="badge bg-secondary fs-6 px-3 py-2">Not Yet Assessed</span>
                        @endif
                        <div class="small text-muted mt-2">Current Reading Level</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-0 shadow-sm text-center">
                    <div class="card-body">
                        <h3 class="text-primary mb-0">{{ number_format($stats['latest_accuracy'] ?? 0, 1) }}%</h3>
                        <div class="small text-muted">Latest Accuracy</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-0 shadow-sm text-center">
                    <div class="card-body">
                        <h3 class="text-success mb-0">{{ round($stats['latest_wpm'] ?? 0) }}</h3>
                        <div class="small text-muted">Latest WPM</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-0 shadow-sm text-center">
                    <div class="card-body">
                        <h3 class="text-info mb-0">{{ $stats['total_assessments'] ?? 0 }}</h3>
                        <div class="small text-muted">Total Assessments</div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Progress Charts --}}
        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white"><h6 class="mb-0">Accuracy Over Time</h6></div>
                    <div class="card-body"><canvas id="myAccuracyChart" height="250"></canvas></div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white"><h6 class="mb-0">Words Per Minute Over Time</h6></div>
                    <div class="card-body"><canvas id="myWpmChart" height="250"></canvas></div>
                </div>
            </div>
        </div>

        {{-- Skill Breakdown --}}
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white"><h6 class="mb-0">My Reading Skills</h6></div>
            <div class="card-body">
                <div class="row g-4">
                    @foreach(['phonemic' => ['Phonemic Awareness', 'bi-ear', 'Recognizing sounds in words'], 'decoding' => ['Decoding Accuracy', 'bi-puzzle', 'Reading words correctly'], 'fluency' => ['Oral Reading Fluency', 'bi-speedometer2', 'Reading speed and smoothness'], 'comprehension' => ['Reading Comprehension', 'bi-lightbulb', 'Understanding what you read']] as $key => [$label, $icon, $desc])
                        <div class="col-md-3">
                            <div class="text-center">
                                <i class="bi {{ $icon }} display-6 {{ ($skillScores[$key] ?? 0) >= 80 ? 'text-success' : (($skillScores[$key] ?? 0) >= 60 ? 'text-warning' : 'text-danger') }}"></i>
                                <h6 class="mt-2 mb-1">{{ $label }}</h6>
                                <div class="progress mb-1" style="height: 12px;">
                                    <div class="progress-bar {{ ($skillScores[$key] ?? 0) >= 80 ? 'bg-success' : (($skillScores[$key] ?? 0) >= 60 ? 'bg-warning' : 'bg-danger') }}"
                                         style="width: {{ $skillScores[$key] ?? 0 }}%"></div>
                                </div>
                                <p class="fw-bold mb-0">{{ number_format($skillScores[$key] ?? 0, 0) }}%</p>
                                <small class="text-muted">{{ $desc }}</small>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @else
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-5">
                <i class="bi bi-link-45deg display-1 text-muted"></i>
                <h4 class="mt-3">Account Not Linked</h4>
                <p class="text-muted">Your student account hasn't been linked to a learner profile yet. Please ask your teacher.</p>
            </div>
        </div>
    @endif

@push('scripts')
<script>
    @if($learner ?? false)
    const dates = {!! json_encode($progressDates ?? []) !!};
    new Chart(document.getElementById('myAccuracyChart'), {
        type: 'line', data: { labels: dates, datasets: [{ label: 'Accuracy %', data: {!! json_encode($progressAccuracy ?? []) !!}, borderColor: '#0d6efd', backgroundColor: 'rgba(13,110,253,0.1)', fill: true, tension: 0.3 }] },
        options: { responsive: true, scales: { y: { beginAtZero: true, max: 100 } } }
    });
    new Chart(document.getElementById('myWpmChart'), {
        type: 'line', data: { labels: dates, datasets: [{ label: 'WPM', data: {!! json_encode($progressWpm ?? []) !!}, borderColor: '#198754', backgroundColor: 'rgba(25,135,84,0.1)', fill: true, tension: 0.3 }] },
        options: { responsive: true, scales: { y: { beginAtZero: true } } }
    });
    @endif
</script>
@endpush
@endsection
