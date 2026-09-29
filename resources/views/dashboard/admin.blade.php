{{-- Admin Dashboard Partial --}}
@extends('layouts.app')

@section('title', 'Admin Dashboard')

@section('content')
    {{-- Header --}}
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="bi bi-speedometer2 me-2"></i>Admin Dashboard</h4>
            <p class="text-muted mb-0 small">
                Welcome back, {{ auth()->user()->first_name ?? auth()->user()->name ?? 'Admin' }}! Here is the overall system summary.
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.phil-iri') }}" class="btn btn-primary btn-sm">
                <i class="bi bi-journal-bookmark-fill me-1"></i> Phil-IRI Profile
            </a>
        </div>
    </div>

    {{-- Summary Stats --}}
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100 border-bottom border-primary border-4">
                <div class="card-body py-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <small class="text-muted fw-bold text-uppercase">Total Learners</small>
                        <div class="bg-primary bg-opacity-10 text-primary rounded p-2 d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                            <i class="bi bi-people fs-5"></i>
                        </div>
                    </div>
                    <div class="display-6 fw-bold text-dark">{{ $stats['total_learners'] ?? 0 }}</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100 border-bottom border-success border-4">
                <div class="card-body py-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <small class="text-muted fw-bold text-uppercase">Assessments</small>
                        <div class="bg-success bg-opacity-10 text-success rounded p-2 d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                            <i class="bi bi-clipboard-check fs-5"></i>
                        </div>
                    </div>
                    <div class="display-6 fw-bold text-dark">{{ $stats['total_assessments'] ?? 0 }}</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100 border-bottom border-info border-4">
                <div class="card-body py-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <small class="text-muted fw-bold text-uppercase">Teachers</small>
                        <div class="bg-info bg-opacity-10 text-info rounded p-2 d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                            <i class="bi bi-person-badge fs-5"></i>
                        </div>
                    </div>
                    <div class="display-6 fw-bold text-dark">{{ $stats['total_teachers'] ?? 0 }}</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100 border-bottom border-warning border-4">
                <div class="card-body py-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <small class="text-muted fw-bold text-uppercase">Schools</small>
                        <div class="bg-warning bg-opacity-10 text-warning rounded p-2 d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                            <i class="bi bi-building fs-5"></i>
                        </div>
                    </div>
                    <div class="display-6 fw-bold text-dark">{{ $stats['total_schools'] ?? 0 }}</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Charts Row --}}
    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-3 border-bottom-0">
                    <h6 class="mb-0 fw-semibold text-secondary"><i class="bi bi-pie-chart me-2"></i>Reading Level Distribution</h6>
                </div>
                <div class="card-body d-flex align-items-center justify-content-center pb-4">
                    <canvas id="levelDistributionChart" style="max-height: 280px;"></canvas>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-3 border-bottom-0">
                    <h6 class="mb-0 fw-semibold text-secondary"><i class="bi bi-bar-chart me-2"></i>Assessments This Month</h6>
                </div>
                <div class="card-body d-flex align-items-center justify-content-center pb-4">
                    <canvas id="monthlyAssessmentsChart" style="max-height: 280px;"></canvas>
                </div>
            </div>
        </div>
    </div>

    {{-- Recent Activity --}}
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center py-3">
            <h6 class="mb-0"><i class="bi bi-clock-history me-2"></i>Recent Assessments</h6>
            <a href="{{ route('assessments.index') }}" class="btn btn-sm btn-light text-primary fw-semibold">View All</a>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-bordered mb-0 align-middle">
                    <thead class="table-light text-secondary">
                        <tr>
                            <th class="ps-4">Learner</th>
                            <th>Material</th>
                            <th class="text-center">Accuracy</th>
                            <th class="text-center">WPM</th>
                            <th class="text-center">Level</th>
                            <th class="text-end pe-4">Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($recentAssessments as $assessment)
                            <tr>
                                <td class="ps-4 fw-semibold">{{ $assessment->learner?->getFullName() ?? 'N/A' }}</td>
                                <td>{{ Str::limit($assessment->material?->title ?? 'N/A', 35) }}</td>
                                <td class="text-center">
                                    <span class="fw-semibold {{ $assessment->accuracy_rate >= 97 ? 'text-success' : ($assessment->accuracy_rate >= 90 ? 'text-warning' : 'text-danger') }}">
                                        {{ $assessment->accuracy_rate ?? '-' }}%
                                    </span>
                                </td>
                                <td class="text-center">{{ $assessment->words_per_minute ?? '-' }}</td>
                                <td class="text-center">
                                    @php $level = $assessment->reading_level; @endphp
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
                                <td class="text-end pe-4 small text-muted">{{ $assessment->created_at?->diffForHumans() }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center text-muted py-5">
                                    <i class="bi bi-inbox display-6 d-block mb-3 opacity-50"></i>
                                    No assessments yet
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
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
                    backgroundColor: ['#28a745', '#ffc107', '#dc3545', '#6c757d'],
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: { 
                responsive: true, 
                maintainAspectRatio: true,
                plugins: { 
                    legend: { position: 'bottom', labels: { padding: 15, usePointStyle: true } } 
                } 
            }
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
                    backgroundColor: '#0d6efd',
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                scales: { 
                    x: { grid: { display: false } },
                    y: { beginAtZero: true, ticks: { stepSize: 1 } } 
                },
                plugins: { legend: { display: false } }
            }
        });
    }
});
</script>
@endpush