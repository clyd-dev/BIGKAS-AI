@extends('layouts.app')

@section('title', 'Report - ' . $learner->full_name)

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-file-earmark-bar-graph me-2"></i>{{ $learner->full_name }} - Report</h4>
        <div>
            <a href="{{ route('reports.print', $learner) }}" class="btn btn-outline-primary btn-sm" target="_blank">
                <i class="bi bi-printer me-1"></i> Print
            </a>
            <a href="{{ route('reports.pdf', $learner) }}" class="btn btn-outline-danger btn-sm">
                <i class="bi bi-file-earmark-pdf me-1"></i> PDF
            </a>
            <a href="{{ auth()->user()->isParent() ? route('parent.children.profile', $learner) : route('learners.show', $learner) }}" class="btn btn-outline-secondary btn-sm"
               onclick="if (window.history.length > 1) { window.history.back(); return false; }">
                <i class="bi bi-arrow-left me-1"></i> Back
            </a>
        </div>
    </div>

    {{-- Summary --}}
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
                    <div class="small text-muted mt-1">Level</div>
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

    {{-- Charts + Skill Breakdown side by side --}}
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

    {{-- Assessment History Table --}}
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white"><h6 class="mb-0">Assessment History</h6></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr><th>Date</th><th>Material</th><th>Accuracy</th><th>WPM</th><th>Level</th><th>Weakness</th><th></th></tr>
                    </thead>
                    <tbody>
                        @php $weaknessLabels = config('bigkas.weakness_categories', []); @endphp
                        @forelse($assessments ?? [] as $assessment)
                            @php $r = $assessment->result; @endphp
                            <tr>
                                <td>{{ $assessment->created_at?->format('M d, Y') }}</td>
                                <td>{{ $assessment->material?->title ?? 'N/A' }}</td>
                                <td>{{ $r?->accuracy_rate ?? '-' }}%</td>
                                <td>{{ $r?->words_per_minute ?? '-' }}</td>
                                <td>
                                    @if($r?->reading_level === 'independent')
                                        <span class="badge bg-success">Ind.</span>
                                    @elseif($r?->reading_level === 'instructional')
                                        <span class="badge bg-warning text-dark">Inst.</span>
                                    @elseif($r?->reading_level === 'frustration')
                                        <span class="badge bg-danger">Frus.</span>
                                    @else
                                        <span class="badge bg-secondary">-</span>
                                    @endif
                                </td>
                                <td>{{ $weaknessLabels[$r?->primary_weakness]['name'] ?? '-' }}</td>
                                <td><a href="{{ auth()->user()->isParent() ? route('parent.children.assessment-detail', [$learner, $assessment]) : route('assessments.results', $assessment) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a></td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-center text-muted py-3">No assessments</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Pagination --}}
    <div class="d-flex flex-wrap justify-content-between align-items-center mt-3 gap-2">
        <div class="small text-muted">
            @if($assessments->total() > 0)
                Showing {{ $assessments->firstItem() }}–{{ $assessments->lastItem() }} of {{ $assessments->total() }}
            @endif
        </div>
        {{ $assessments->onEachSide(1)->links('partials.pagination') }}
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
