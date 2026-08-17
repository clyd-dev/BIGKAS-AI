@extends('layouts.app')

@section('title', 'Admin Panel')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-gear me-2"></i>Admin Panel</h4>
        <small class="text-muted">{{ now()->format('l, F j, Y') }}</small>
    </div>

    {{-- Row 1: Core stats --}}
    <div class="row g-3 mb-3">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm bg-primary text-white h-100">
                <div class="card-body text-center">
                    <i class="bi bi-people display-6"></i>
                    <h3 class="mt-2 mb-0">{{ $stats['total_users'] ?? 0 }}</h3>
                    <small>Total Users</small>
                </div>
                <a href="{{ route('admin.users') }}" class="card-footer text-white text-center text-decoration-none bg-transparent border-top border-white border-opacity-25 small">
                    Manage Users <i class="bi bi-arrow-right ms-1"></i>
                </a>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm bg-success text-white h-100">
                <div class="card-body text-center">
                    <i class="bi bi-building display-6"></i>
                    <h6 class="mt-2 mb-0 fw-bold" style="font-size:0.85rem; line-height:1.3;">{{ $schoolName ?? 'Old Sagay Elementary School' }}</h6>
                    <small class="opacity-75">Current School</small>
                </div>
                <a href="{{ route('admin.schools') }}" class="card-footer text-white text-center text-decoration-none bg-transparent border-top border-white border-opacity-25 small">
                    Manage School <i class="bi bi-arrow-right ms-1"></i>
                </a>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm bg-info text-white h-100">
                <div class="card-body text-center">
                    <i class="bi bi-lightbulb display-6"></i>
                    <h3 class="mt-2 mb-0">{{ $stats['total_interventions'] ?? 0 }}</h3>
                    <small>Interventions</small>
                </div>
                <a href="{{ route('admin.interventions') }}" class="card-footer text-white text-center text-decoration-none bg-transparent border-top border-white border-opacity-25 small">
                    Manage Interventions <i class="bi bi-arrow-right ms-1"></i>
                </a>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm bg-warning text-dark h-100">
                <div class="card-body text-center">
                    <i class="bi bi-journal-text display-6"></i>
                    <h3 class="mt-2 mb-0">{{ $stats['total_materials'] ?? 0 }}</h3>
                    <small>Materials</small>
                </div>
                <a href="{{ route('admin.materials') }}" class="card-footer text-dark text-center text-decoration-none bg-transparent border-top border-dark border-opacity-25 small">
                    View Materials <i class="bi bi-arrow-right ms-1"></i>
                </a>
            </div>
        </div>
    </div>

    {{-- Row 2: Learner + portal stats --}}
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center">
                    <i class="bi bi-person-hearts display-6 text-secondary"></i>
                    <h3 class="mt-2 mb-0">{{ $stats['total_learners'] ?? 0 }}</h3>
                    <small class="text-muted">Learners</small>
                </div>
                <a href="{{ route('admin.learner-portal') }}" class="card-footer text-center text-decoration-none text-muted small bg-light">
                    Learner Portal <i class="bi bi-arrow-right ms-1"></i>
                </a>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center">
                    <i class="bi bi-clipboard2-pulse display-6 text-danger"></i>
                    <h3 class="mt-2 mb-0 text-danger">{{ $stats['frustration_learners'] ?? 0 }}</h3>
                    <small class="text-muted">Frustration Level</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center">
                    <i class="bi bi-graph-up display-6 text-warning"></i>
                    <h3 class="mt-2 mb-0 text-warning">{{ $stats['instructional_learners'] ?? 0 }}</h3>
                    <small class="text-muted">Instructional Level</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center">
                    <i class="bi bi-star-fill display-6 text-success"></i>
                    <h3 class="mt-2 mb-0 text-success">{{ $stats['independent_learners'] ?? 0 }}</h3>
                    <small class="text-muted">Independent Level</small>
                </div>
            </div>
        </div>
    </div>

    {{-- Row 3: ML Analytics & Assessment Trends --}}
    <div class="row g-3 mb-4">
        <div class="col-md-5">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-pie-chart me-1"></i> School-Wide Reading Weaknesses
                </div>
                <div class="card-body">
                    <canvas id="weaknessChart" style="max-height: 300px;"></canvas>
                </div>
            </div>
        </div>
        <div class="col-md-7">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-graph-up-arrow me-1"></i> Assessments Conducted (This Year)
                </div>
                <div class="card-body">
                    <canvas id="assessmentChart" style="max-height: 300px;"></canvas>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        {{-- System Information --}}
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-info-circle me-1"></i> System Information
                </div>
                <div class="card-body">
                    <table class="table table-sm table-borderless mb-0">
                        <tr><th class="text-muted" width="45%">App</th><td>BIGKAS-AI v1.0</td></tr>
                        <tr><th class="text-muted">Laravel</th><td>{{ app()->version() }}</td></tr>
                        <tr><th class="text-muted">PHP</th><td>{{ phpversion() }}</td></tr>
                        <tr><th class="text-muted">Database</th><td>{{ config('database.default') }}</td></tr>
                        <tr><th class="text-muted">Timezone</th><td>{{ config('app.timezone') }}</td></tr>
                        <tr><th class="text-muted">Teachers</th><td>{{ $stats['total_teachers'] ?? 0 }}</td></tr>
                        <tr><th class="text-muted">Parents</th><td>{{ $stats['total_parents'] ?? 0 }}</td></tr>
                        <tr><th class="text-muted">Assessments</th><td>{{ $stats['total_assessments'] ?? 0 }}</td></tr>
                    </table>
                </div>
                <div class="card-footer bg-white border-top">
                    <div class="d-grid gap-2">
                        <a href="{{ route('admin.settings') }}" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-sliders me-1"></i> System Settings
                        </a>
                        <a href="{{ route('admin.badges') }}" class="btn btn-sm btn-outline-warning">
                            <i class="bi bi-award me-1"></i> Manage Badges
                        </a>
                    </div>
                </div>
            </div>
        </div>

        {{-- Recent Activity Log --}}
        <div class="col-md-8">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-clock-history me-1"></i> Recent Activity</span>
                    <small class="text-muted">Last 20 events</small>
                </div>
                <div class="card-body p-0" style="max-height: 420px; overflow-y: auto;">
                    @forelse($recentActivity ?? [] as $log)
                        <div class="d-flex align-items-start gap-2 px-3 py-2 border-bottom">
                            <div class="mt-1">
                                <i class="bi bi-circle-fill text-primary" style="font-size: 0.45rem;"></i>
                            </div>
                            <div class="flex-grow-1" style="min-width: 0;">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="fw-semibold text-truncate small">{{ $log->user?->name ?? 'System' }}</span>
                                    <span class="text-muted" style="font-size: 0.72rem; white-space: nowrap; margin-left: 0.5rem;">
                                        {{ $log->created_at?->diffForHumans() }}
                                    </span>
                                </div>
                                <div class="text-muted" style="font-size: 0.8rem;">
                                    {{ $log->description ?? $log->action ?? '' }}
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="text-center text-muted py-5">
                            <i class="bi bi-inbox display-6 d-block mb-2"></i>
                            No activity recorded yet
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Weakness Pie Chart
        const weaknessCtx = document.getElementById('weaknessChart').getContext('2d');
        new Chart(weaknessCtx, {
            type: 'doughnut',
            data: {
                labels: {!! json_encode($chartData['weaknesses']['labels'] ?? []) !!},
                datasets: [{
                    data: {!! json_encode($chartData['weaknesses']['data'] ?? []) !!},
                    backgroundColor: [
                        '#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0'
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'right' }
                }
            }
        });

        // Assessments Line Chart
        const assessmentCtx = document.getElementById('assessmentChart').getContext('2d');
        new Chart(assessmentCtx, {
            type: 'line',
            data: {
                labels: {!! json_encode($chartData['assessments']['labels'] ?? []) !!},
                datasets: [{
                    label: 'Assessments',
                    data: {!! json_encode($chartData['assessments']['data'] ?? []) !!},
                    borderColor: '#36A2EB',
                    backgroundColor: 'rgba(54, 162, 235, 0.1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.3
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { stepSize: 1 }
                    }
                }
            }
        });
    });
</script>
@endpush

