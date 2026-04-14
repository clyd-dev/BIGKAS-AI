{{-- Teacher / Parent Dashboard Partial --}}

{{-- Stats Cards --}}
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center">
                <i class="bi bi-people display-6 text-primary"></i>
                <h3 class="mt-2 mb-0">{{ $stats['total_learners'] ?? 0 }}</h3>
                <small class="text-muted">My Learners</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center">
                <i class="bi bi-clipboard-check display-6 text-success"></i>
                <h3 class="mt-2 mb-0">{{ $stats['total_assessments'] ?? 0 }}</h3>
                <small class="text-muted">Assessments</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center">
                <i class="bi bi-graph-up display-6 text-info"></i>
                <h3 class="mt-2 mb-0">{{ number_format($stats['avg_accuracy'] ?? 0, 1) }}%</h3>
                <small class="text-muted">Avg Accuracy</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center">
                <i class="bi bi-speedometer2 display-6 text-warning"></i>
                <h3 class="mt-2 mb-0">{{ round($stats['avg_wpm'] ?? 0) }}</h3>
                <small class="text-muted">Avg WPM</small>
            </div>
        </div>
    </div>
</div>

{{-- Quick Actions --}}
<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white">
                <h6 class="mb-0"><i class="bi bi-lightning me-1"></i> Quick Actions</h6>
            </div>
            <div class="card-body">
                <div class="d-grid gap-2">
                    <a href="{{ route('assessments.create') }}" class="btn btn-primary">
                        <i class="bi bi-plus-circle me-1"></i> New Assessment
                    </a>
                    <a href="{{ route('learners.create') }}" class="btn btn-outline-primary">
                        <i class="bi bi-person-plus me-1"></i> Add Learner
                    </a>
                    <a href="{{ route('reports.index') }}" class="btn btn-outline-secondary">
                        <i class="bi bi-bar-chart me-1"></i> View Reports
                    </a>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white">
                <h6 class="mb-0"><i class="bi bi-pie-chart me-1"></i> Reading Level Distribution</h6>
            </div>
            <div class="card-body">
                <canvas id="teacherDistChart" height="200"></canvas>
            </div>
        </div>
    </div>
</div>

{{-- My Learners --}}
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h6 class="mb-0"><i class="bi bi-people me-1"></i> My Learners</h6>
        <a href="{{ route('learners.index') }}" class="btn btn-sm btn-outline-primary">View All</a>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>Grade</th>
                        <th>Reading Level</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($learners ?? [] as $learner)
                        <tr>
                            <td>{{ $learner->full_name }}</td>
                            <td>Grade {{ $learner->grade_level }}</td>
                            <td>
                                @if($learner->reading_level === 'independent')
                                    <span class="badge bg-success">Independent</span>
                                @elseif($learner->reading_level === 'instructional')
                                    <span class="badge bg-warning text-dark">Instructional</span>
                                @elseif($learner->reading_level === 'frustration')
                                    <span class="badge bg-danger">Frustration</span>
                                @else
                                    <span class="badge bg-secondary">Not Assessed</span>
                                @endif
                            </td>
                            <td>
                                <a href="{{ route('assessments.start', $learner) }}" class="btn btn-sm btn-primary" title="Start Assessment">
                                    <i class="bi bi-mic"></i>
                                </a>
                                <a href="{{ route('learners.show', $learner) }}" class="btn btn-sm btn-outline-secondary" title="View">
                                    <i class="bi bi-eye"></i>
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-center text-muted py-4">
                                No learners yet. <a href="{{ route('learners.create') }}">Add your first learner</a>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

@push('scripts')
<script>
    const distCtx = document.getElementById('teacherDistChart');
    if (distCtx) {
        new Chart(distCtx, {
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
    }
</script>
@endpush
