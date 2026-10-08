@extends('layouts.app')

@section('title', 'Class Report - ' . $class->getDisplayName())

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-people me-2"></i>Class Report: {{ $class->getDisplayName() }}</h4>
        <a href="{{ route('reports.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Back</a>
    </div>

    {{-- Class Stats --}}
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm text-center">
                <div class="card-body">
                    <h3 class="mb-0 text-primary">{{ $totalLearners ?? 0 }}</h3>
                    <small class="text-muted">Total Learners</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm text-center">
                <div class="card-body">
                    <h3 class="mb-0 text-success">{{ $assessed ?? 0 }}</h3>
                    <small class="text-muted">Assessed</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm text-center">
                <div class="card-body">
                    <h3 class="mb-0 text-info">{{ number_format($avgAccuracy ?? 0, 1) }}%</h3>
                    <small class="text-muted">Avg Accuracy</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm text-center">
                <div class="card-body">
                    <h3 class="mb-0 text-warning">{{ round($avgWpm ?? 0) }}</h3>
                    <small class="text-muted">Avg WPM</small>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white"><h6 class="mb-0">Reading Level Distribution</h6></div>
                <div class="card-body"><canvas id="classDistChart" height="250"></canvas></div>
            </div>
        </div>
        <div class="col-md-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white"><h6 class="mb-0">Learners</h6></div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr><th>Name</th><th>Accuracy</th><th>WPM</th><th>Level</th><th></th></tr>
                            </thead>
                            <tbody>
                                @forelse($learners ?? [] as $learner)
                                    <tr>
                                        <td>{{ $learner['name'] ?? $learner->full_name ?? 'N/A' }}</td>
                                        <td>{{ $learner['accuracy_rate'] ?? $learner->accuracy_rate ?? '-' }}%</td>
                                        <td>{{ $learner['words_per_minute'] ?? $learner->words_per_minute ?? '-' }}</td>
                                        <td>
                                            @php $level = $learner['reading_level'] ?? $learner->reading_level ?? null; @endphp
                                            @if($level === 'independent')
                                                <span class="badge bg-success">Ind.</span>
                                            @elseif($level === 'instructional')
                                                <span class="badge bg-warning text-dark">Inst.</span>
                                            @elseif($level === 'frustration')
                                                <span class="badge bg-danger">Frus.</span>
                                            @else
                                                <span class="badge bg-secondary">N/A</span>
                                            @endif
                                        </td>
                                        <td>
                                            <a href="{{ route('reports.learner', $learner['id'] ?? $learner->id ?? 0) }}" class="btn btn-sm btn-outline-primary">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="text-center text-muted py-3">No learners in this class</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

@push('scripts')
<script>
    new Chart(document.getElementById('classDistChart'), {
        type: 'doughnut',
        data: {
            labels: ['Independent', 'Instructional', 'Frustration', 'Not Assessed'],
            datasets: [{
                data: [
                    {{ $distribution['independent'] ?? 0 }},
                    {{ $distribution['instructional'] ?? 0 }},
                    {{ $distribution['frustration'] ?? 0 }},
                    {{ $distribution['not_assessed'] ?? 0 }}
                ],
                backgroundColor: ['#198754', '#ffc107', '#dc3545', '#6c757d']
            }]
        },
        options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
    });
</script>
@endpush
@endsection
