{{-- Admin Dashboard Partial --}}

{{-- Stats Cards --}}
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card border-0 shadow-sm bg-primary text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h6 class="text-white-50">Total Learners</h6>
                        <h3 class="mb-0">{{ $stats['total_learners'] ?? 0 }}</h3>
                    </div>
                    <i class="bi bi-people display-6 opacity-50"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm bg-success text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h6 class="text-white-50">Total Assessments</h6>
                        <h3 class="mb-0">{{ $stats['total_assessments'] ?? 0 }}</h3>
                    </div>
                    <i class="bi bi-clipboard-check display-6 opacity-50"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm bg-info text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h6 class="text-white-50">Teachers</h6>
                        <h3 class="mb-0">{{ $stats['total_teachers'] ?? 0 }}</h3>
                    </div>
                    <i class="bi bi-person-badge display-6 opacity-50"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm bg-warning text-dark">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h6 class="text-dark opacity-75">Schools</h6>
                        <h3 class="mb-0">{{ $stats['total_schools'] ?? 0 }}</h3>
                    </div>
                    <i class="bi bi-building display-6 opacity-50"></i>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Charts Row --}}
<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white">
                <h6 class="mb-0"><i class="bi bi-pie-chart me-1"></i> Reading Level Distribution</h6>
            </div>
            <div class="card-body">
                <canvas id="levelDistributionChart" height="250"></canvas>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white">
                <h6 class="mb-0"><i class="bi bi-bar-chart me-1"></i> Assessments This Month</h6>
            </div>
            <div class="card-body">
                <canvas id="monthlyAssessmentsChart" height="250"></canvas>
            </div>
        </div>
    </div>
</div>

{{-- Recent Activity --}}
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h6 class="mb-0"><i class="bi bi-clock-history me-1"></i> Recent Assessments</h6>
        <a href="{{ route('assessments.index') }}" class="btn btn-sm btn-outline-primary">View All</a>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Learner</th>
                        <th>Material</th>
                        <th>Accuracy</th>
                        <th>WPM</th>
                        <th>Level</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($recentAssessments ?? [] as $assessment)
                        <tr>
                            <td>{{ $assessment->learner?->full_name ?? 'N/A' }}</td>
                            <td>{{ $assessment->material?->title ?? 'N/A' }}</td>
                            <td>{{ $assessment->results->first()?->accuracy_rate ?? '-' }}%</td>
                            <td>{{ $assessment->results->first()?->words_per_minute ?? '-' }}</td>
                            <td>
                                @php $level = $assessment->results->first()?->reading_level; @endphp
                                @if($level === 'independent')
                                    <span class="badge bg-success">Independent</span>
                                @elseif($level === 'instructional')
                                    <span class="badge bg-warning text-dark">Instructional</span>
                                @elseif($level === 'frustration')
                                    <span class="badge bg-danger">Frustration</span>
                                @else
                                    <span class="badge bg-secondary">Pending</span>
                                @endif
                            </td>
                            <td>{{ $assessment->created_at?->diffForHumans() }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">No assessments yet</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

@push('scripts')
<script>
    // Reading Level Distribution Chart
    const distCtx = document.getElementById('levelDistributionChart');
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

    // Monthly Assessments Chart
    const monthCtx = document.getElementById('monthlyAssessmentsChart');
    if (monthCtx) {
        new Chart(monthCtx, {
            type: 'bar',
            data: {
                labels: {!! json_encode($monthlyLabels ?? ['Week 1', 'Week 2', 'Week 3', 'Week 4']) !!},
                datasets: [{
                    label: 'Assessments',
                    data: {!! json_encode($monthlyCounts ?? [0, 0, 0, 0]) !!},
                    backgroundColor: '#0d6efd'
                }]
            },
            options: {
                responsive: true,
                scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } },
                plugins: { legend: { display: false } }
            }
        });
    }
</script>
@endpush
