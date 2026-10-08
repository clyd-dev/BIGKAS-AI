{{-- Teacher / Parent Dashboard Partial --}}
@php $assigned = auth()->user()->hasAssignedClass(); @endphp
@include('partials.unassigned-teacher')

{{-- Stats Cards --}}
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center">
                <i class="bi bi-people display-6 text-primary"></i>
                <h3 class="mt-2 mb-0">{{ $stats['total_learners'] ?? 0 }}</h3>
                <small class="text-muted">My Learners</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center">
                <i class="bi bi-clipboard-check display-6 text-success"></i>
                <h3 class="mt-2 mb-0">{{ $stats['total_assessments'] ?? 0 }}</h3>
                <small class="text-muted">Assessments</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center">
                <i class="bi bi-graph-up display-6 text-info"></i>
                <h3 class="mt-2 mb-0">{{ number_format($stats['avg_accuracy'] ?? 0, 1) }}%</h3>
                <small class="text-muted">Avg Accuracy</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center">
                <i class="bi bi-speedometer2 display-6 text-warning"></i>
                <h3 class="mt-2 mb-0">{{ round($stats['avg_wpm'] ?? 0) }}</h3>
                <small class="text-muted">Avg WPM</small>
            </div>
        </div>
    </div>
</div>

{{-- ACTIONABLE INTELLIGENCE ROW (New) --}}
<div class="row g-3 mb-4">
    {{-- At-Risk Students Widget --}}
    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white border-bottom-0 pt-3 pb-0">
                <h6 class="mb-0 text-danger fw-bold"><i class="bi bi-exclamation-triangle me-1"></i> Students Needing Attention</h6>
                <small class="text-muted">Frustration level readers & ML Diagnosis</small>
            </div>
            <div class="card-body">
                <ul class="list-group list-group-flush">
                    @forelse($atRiskLearners as $learner)
                        @php $latestResult = $learner->assessments->first()?->result; @endphp
                        <li class="list-group-item px-0 d-flex justify-content-between align-items-center">
                            <div>
                                <strong class="d-block">{{ $learner->getFullName() }}</strong>
                                <small class="text-muted">
                                    ML Flag: 
                                    @if($latestResult && $latestResult->getPrimaryWeaknessInfo())
                                        <span class="badge bg-danger-subtle text-danger">{{ $latestResult->getPrimaryWeaknessInfo()['name'] }}</span>
                                    @else
                                        <span class="badge bg-secondary-subtle text-secondary">Awaiting Assessment</span>
                                    @endif
                                </small>
                            </div>
                            <a href="{{ route('learners.interventions', $learner) }}" class="btn btn-sm btn-outline-danger rounded-pill">
                                Assign Help
                            </a>
                        </li>
                    @empty
                        <li class="list-group-item px-0 text-center py-4 text-muted border-0">
                            <i class="bi bi-check-circle text-success fs-4 d-block mb-2"></i>
                            Great job! No students are currently marked at frustration level.
                        </li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>

    {{-- Pending Interventions Action Center --}}
    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white border-bottom-0 pt-3 pb-0">
                <h6 class="mb-0 text-primary fw-bold"><i class="bi bi-list-check me-1"></i> Action Center</h6>
                <small class="text-muted">Pending interventions waiting for review</small>
            </div>
            <div class="card-body">
                <ul class="list-group list-group-flush">
                    @forelse($pendingInterventions as $log)
                        <li class="list-group-item px-0 d-flex justify-content-between align-items-center">
                            <div>
                                <strong class="d-block text-truncate" style="max-width: 200px;">
                                    {{ $log->intervention->title ?? 'Recommended Activity' }}
                                </strong>
                                <small class="text-muted">For: {{ $log->learner->getFullName() }}</small>
                            </div>
                            <button class="btn btn-sm btn-primary rounded-pill" data-bs-toggle="modal" data-bs-target="#reviewInterventionModal{{ $log->id }}">
                                Review
                            </button>
                        </li>
                    @empty
                        <li class="list-group-item px-0 text-center py-4 text-muted border-0">
                            <i class="bi bi-cup-hot fs-4 d-block mb-2"></i>
                            All caught up! No pending actions required.
                        </li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
</div>

{{-- Quick Actions & Chart --}}
<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white">
                <h6 class="mb-0"><i class="bi bi-lightning me-1"></i> Quick Actions</h6>
            </div>
            <div class="card-body d-flex flex-column justify-content-center">
                <div class="d-grid gap-3">
                    @if($assigned)
                    <a href="{{ route('assessments.create') }}" class="btn btn-primary py-2 shadow-sm">
                        <i class="bi bi-mic me-2"></i> Start Live Assessment
                    </a>
                    @endif
                    <div class="row g-2">
                        @if($assigned)
                        <div class="col-6">
                            <a href="{{ route('learners.create') }}" class="btn btn-outline-primary w-100">
                                <i class="bi bi-person-plus me-1"></i> Add Learner
                            </a>
                        </div>
                        @endif
                        <div class="col-6">
                            <a href="{{ route('reports.index') }}" class="btn btn-outline-secondary w-100">
                                <i class="bi bi-bar-chart me-1"></i> Reports
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white">
                <h6 class="mb-0"><i class="bi bi-pie-chart me-1"></i> Reading Level Distribution</h6>
            </div>
            <div class="card-body d-flex align-items-center justify-content-center">
                <div style="height: 180px; width: 100%;">
                    <canvas id="teacherDistChart"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Class Roster --}}
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
        <h6 class="mb-0 fw-bold"><i class="bi bi-people me-1"></i> Class Roster</h6>
        <div class="d-flex gap-2">
            {{-- Placeholder for future search/filter --}}
            <input type="text" class="form-control form-control-sm" placeholder="Search learners..." disabled>
            <a href="{{ route('learners.index') }}" class="btn btn-sm btn-outline-primary whitespace-nowrap">View All</a>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Name</th>
                        <th>Grade</th>
                        <th>Reading Level</th>
                        <th class="text-end pe-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($learners as $learner)
                        <tr>
                            <td class="ps-3 fw-medium">{{ $learner->getFullName() }}</td>
                            <td>Grade {{ $learner->grade_level }}</td>
                            <td>
                                @if($learner->reading_level === 'independent')
                                    <span class="badge bg-success-subtle text-success border border-success-subtle">Independent</span>
                                @elseif($learner->reading_level === 'instructional')
                                    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">Instructional</span>
                                @elseif($learner->reading_level === 'frustration')
                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle">Frustration</span>
                                @else
                                    <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">Not Assessed</span>
                                @endif
                            </td>
                            <td class="text-end pe-3">
                                @if($assigned)
                                <a href="{{ route('assessments.start', $learner) }}" class="btn btn-sm btn-primary rounded-circle shadow-sm" title="Start Assessment" style="width: 32px; height: 32px; padding: 4px;">
                                    <i class="bi bi-mic"></i>
                                </a>
                                @endif
                                <a href="{{ route('learners.show', $learner) }}" class="btn btn-sm btn-light rounded-circle shadow-sm border" title="View Profile" style="width: 32px; height: 32px; padding: 4px;">
                                    <i class="bi bi-eye"></i>
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-center text-muted py-5">
                                <div class="mb-3"><i class="bi bi-person-x fs-1"></i></div>
                                <h6>No learners found</h6>
                                <p class="small">Add your first student to get started.</p>
                                @if($assigned)
                                    <a href="{{ route('learners.create') }}" class="btn btn-sm btn-primary mt-2">Add Learner</a>
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    {{-- Scalability Fix: Pagination Links --}}
    @if($learners->hasPages())
        <div class="card-footer bg-white border-top-0 pt-3">
            {{ $learners->links() }}
        </div>
    @endif
</div>

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function() {
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
                        backgroundColor: ['#198754', '#ffc107', '#dc3545', '#6c757d'],
                        borderWidth: 0,
                        hoverOffset: 4
                    }]
                },
                options: { 
                    responsive: true, 
                    maintainAspectRatio: false,
                    plugins: { 
                        legend: { position: 'right', labels: { boxWidth: 12, usePointStyle: true } } 
                    },
                    cutout: '70%'
                }
            });
        }
    });
</script>
@endpush
