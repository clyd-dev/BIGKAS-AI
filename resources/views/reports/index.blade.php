@extends(auth()->user()->isParent() ? 'layouts.parent' : 'layouts.app')

@section('title', 'Reports')

@section('content')
    <x-page-header title="Reports" icon="bi-bar-chart">
        <x-slot:actions>
            @if(auth()->user()->isTeacher())
                <a href="{{ route('reports.submissions.index') }}" class="btn btn-primary btn-sm">
                    <i class="bi bi-send me-1"></i> Reports to Principal
                </a>
            @endif
        </x-slot:actions>
    </x-page-header>

    {{-- Quick Stats --}}
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center">
                <div class="card-body">
                    <h3 class="text-primary mb-0">{{ $stats['total_learners'] ?? 0 }}</h3>
                    <small class="text-muted">Total Learners</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center">
                <div class="card-body">
                    <h3 class="text-success mb-0">{{ $stats['assessed'] ?? 0 }}</h3>
                    <small class="text-muted">Assessed</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center">
                <div class="card-body">
                    <h3 class="text-info mb-0">{{ number_format($stats['avg_accuracy'] ?? 0, 1) }}%</h3>
                    <small class="text-muted">Avg Accuracy</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center">
                <div class="card-body">
                    <h3 class="text-warning mb-0">{{ round($stats['avg_wpm'] ?? 0) }}</h3>
                    <small class="text-muted">Avg WPM</small>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        {{-- Individual Learner Reports --}}
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-person me-1"></i> Learner Reports</h6></div>
                <div class="card-body p-0">
                    <div class="list-group list-group-flush">
                        @forelse($learners ?? [] as $learner)
                            <a href="{{ route('reports.learner', $learner) }}" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                                <div>
                                    <strong>{{ $learner->full_name }}</strong>
                                    <br><small class="text-muted">Grade {{ $learner->grade_level }}</small>
                                </div>
                                <div class="text-end">
                                    @if($learner->reading_level === 'independent')
                                        <span class="badge bg-success">Independent</span>
                                    @elseif($learner->reading_level === 'instructional')
                                        <span class="badge bg-warning text-dark">Instructional</span>
                                    @elseif($learner->reading_level === 'frustration')
                                        <span class="badge bg-danger">Frustration</span>
                                    @else
                                        <span class="badge bg-secondary">N/A</span>
                                    @endif
                                </div>
                            </a>
                        @empty
                            <div class="list-group-item text-center text-muted py-3">No learners found</div>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>

        {{-- Class Reports --}}
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-people me-1"></i> Class Reports</h6></div>
                <div class="card-body p-0">
                    <div class="list-group list-group-flush">
                        @forelse($classes ?? [] as $class)
                            <a href="{{ route('reports.class', $class) }}" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                                <div>
                                    <strong>Grade {{ $class->grade_level }} &ndash; {{ $class->section }}</strong>
                                    <br><small class="text-muted">Grade {{ $class->grade_level }} &middot; {{ $class->school?->name ?? '' }}</small>
                                </div>
                                <span class="badge bg-primary rounded-pill">{{ $class->learners_count ?? 0 }} learners</span>
                            </a>
                        @empty
                            <div class="list-group-item text-center text-muted py-3">No classes found</div>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>

        {{-- Distribution Chart --}}
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white"><h6 class="mb-0">Reading Level Distribution</h6></div>
                <div class="card-body">
                    <canvas id="distChart" height="220"></canvas>
                </div>
            </div>
        </div>
    </div>

@push('scripts')
<script>
    new Chart(document.getElementById('distChart'), {
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
