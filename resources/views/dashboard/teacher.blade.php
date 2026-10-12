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

{{-- ATTENTION & ACTION ROW --}}
<div class="row g-3 mb-4">
    {{-- Students Needing Attention: four signals, most urgent first --}}
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white border-bottom-0 pt-3 pb-2 d-flex justify-content-between align-items-start">
                <div>
                    <h6 class="mb-0 text-danger fw-bold"><i class="bi bi-exclamation-triangle me-1"></i> Students Needing Attention</h6>
                    <small class="text-muted">Frustration, falling accuracy, stalled help, or never assessed</small>
                </div>
                @if($watchlist->isNotEmpty())
                    <span class="badge bg-danger-subtle text-danger">{{ $watchlist->count() }}</span>
                @endif
            </div>
            <div class="card-body pt-2">
                <div class="d-flex flex-column gap-3">
                    @forelse($watchlist as $entry)
                        @php
                            $wLearner = $entry['learner'];
                            $trend    = $entry['trend'];
                            $primary  = $entry['signals']->first();
                        @endphp
                        <div class="border rounded-3 p-3 position-relative">
                            {{-- Name, level and where the accuracy is heading --}}
                            <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                <div class="flex-grow-1" style="min-width:0;">
                                    <a href="{{ route('learners.show', $wLearner) }}" class="fw-semibold text-decoration-none d-block text-truncate">
                                        {{ $wLearner->getFullName() }}
                                    </a>
                                    <div class="d-flex align-items-center gap-2 mt-1 flex-wrap">
                                        @if($entry['level'] === 'frustration')
                                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle">Frustration</span>
                                        @elseif($entry['level'] === 'instructional')
                                            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">Instructional</span>
                                        @elseif($entry['level'] === 'independent')
                                            <span class="badge bg-success-subtle text-success border border-success-subtle">Independent</span>
                                        @else
                                            <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">Not assessed</span>
                                        @endif

                                        @if($trend['direction'] === 'down')
                                            <span class="small text-danger fw-semibold">
                                                <i class="bi bi-arrow-down-right"></i> {{ number_format($trend['delta'], 1) }} pts
                                            </span>
                                        @elseif($trend['direction'] === 'up')
                                            <span class="small text-success fw-semibold">
                                                <i class="bi bi-arrow-up-right"></i> +{{ number_format($trend['delta'], 1) }} pts
                                            </span>
                                        @elseif($trend['direction'] === 'flat')
                                            <span class="small text-muted fw-semibold">
                                                <i class="bi bi-arrow-right"></i> Holding steady
                                            </span>
                                        @endif
                                    </div>
                                </div>
                                @if($trend['latest'] !== null)
                                    <div class="text-end flex-shrink-0">
                                        <div class="fw-bold fs-5 lh-1">{{ round($trend['latest'], 1) }}%</div>
                                        <div class="small text-muted">accuracy</div>
                                    </div>
                                @endif
                            </div>

                            {{-- Why this learner is on the list --}}
                            <div class="bg-light rounded p-2 mb-2">
                                @foreach($entry['signals'] as $signal)
                                    <div class="d-flex gap-2 {{ !$loop->last ? 'mb-2' : '' }}">
                                        <span class="badge bg-{{ $signal['tone'] }}-subtle text-{{ $signal['tone'] === 'secondary' ? 'secondary' : $signal['tone'] }} flex-shrink-0 align-self-start">
                                            {{ $signal['label'] }}
                                        </span>
                                        <span class="small text-muted">{{ $signal['explanation'] }}</span>
                                    </div>
                                @endforeach
                            </div>

                            {{-- What is already assigned, so nobody double-assigns --}}
                            <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap">
                                <small class="text-muted">
                                    @if($entry['open_logs']->isEmpty())
                                        <i class="bi bi-dash-circle me-1"></i> No intervention assigned yet
                                    @else
                                        <i class="bi bi-lightbulb me-1"></i>
                                        {{ $entry['open_logs']->count() }}
                                        {{ Str::plural('intervention', $entry['open_logs']->count()) }} open:
                                        {{ $entry['open_logs']->first()->intervention?->name ?? 'Activity' }}@if($entry['open_logs']->count() > 1) +{{ $entry['open_logs']->count() - 1 }} more @endif
                                    @endif
                                </small>
                                <a href="{{ route('learners.interventions', $wLearner) }}"
                                   class="btn btn-sm {{ $entry['open_logs']->isEmpty() ? 'btn-danger' : 'btn-outline-danger' }} rounded-pill">
                                    {{ $entry['open_logs']->isEmpty() ? 'Assign Help' : 'Track Progress' }}
                                </a>
                            </div>
                        </div>
                    @empty
                        <div class="text-center py-4 text-muted">
                            <i class="bi bi-check-circle text-success fs-3 d-block mb-2"></i>
                            <div class="fw-semibold">Nobody needs chasing right now</div>
                            <small>No frustration readers, no falling scores, no stalled interventions.</small>
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    {{-- Action Center: open interventions the teacher can record against here --}}
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white border-bottom-0 pt-3 pb-2">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <h6 class="mb-0 text-primary fw-bold"><i class="bi bi-list-check me-1"></i> Action Center</h6>
                        <small class="text-muted">Interventions you are tracking</small>
                    </div>
                    @if(($interventionCounts['completed'] ?? 0) > 0)
                        <span class="badge bg-success-subtle text-success">
                            {{ $interventionCounts['completed'] }} completed
                        </span>
                    @endif
                </div>
                @php
                    $notStarted = $interventionCounts['pending'] ?? 0;
                    $underway   = $interventionCounts['in_progress'] ?? 0;
                @endphp
                @if($notStarted + $underway > 0)
                    <div class="d-flex gap-3 mt-2 small">
                        <span class="text-secondary"><i class="bi bi-circle me-1"></i>{{ $notStarted }} not started</span>
                        <span class="text-primary"><i class="bi bi-play-circle me-1"></i>{{ $underway }} in progress</span>
                    </div>
                @endif
            </div>
            <div class="card-body pt-2">
                <div class="d-flex flex-column gap-2">
                    @forelse($openInterventions as $log)
                        <div class="border rounded-3 p-3 {{ $log->isStalled() ? 'border-warning bg-warning-subtle bg-opacity-10' : '' }}">
                            <div class="d-flex justify-content-between align-items-start gap-2">
                                <div class="flex-grow-1" style="min-width:0;">
                                    <div class="fw-semibold text-truncate">
                                        {{ $log->intervention?->name ?? 'Recommended Activity' }}
                                    </div>
                                    <div class="small text-muted text-truncate">
                                        For <a href="{{ route('learners.show', $log->learner) }}" class="text-decoration-none">{{ $log->learner->getFullName() }}</a>
                                    </div>
                                </div>
                                <span class="badge bg-{{ $log->getStatusColor() }}-subtle text-{{ $log->getStatusColor() === 'secondary' ? 'secondary' : $log->getStatusColor() }} flex-shrink-0">
                                    {{ $log->getStatusDisplay() }}
                                </span>
                            </div>

                            {{-- The tracking line: how long this has been sitting --}}
                            <div class="small mt-2 {{ $log->isStalled() ? 'text-warning-emphasis fw-semibold' : 'text-muted' }}">
                                @if($log->isStalled())
                                    <i class="bi bi-exclamation-triangle me-1"></i>
                                @else
                                    <i class="bi bi-clock me-1"></i>
                                @endif
                                {{ $log->trackingSummary() }}
                            </div>

                            <div class="d-flex gap-2 mt-2">
                                <button type="button"
                                        class="btn btn-sm btn-primary rounded-pill"
                                        data-bs-toggle="modal"
                                        data-bs-target="#interventionActionModal"
                                        data-url="{{ route('intervention-logs.update', $log) }}"
                                        data-name="{{ $log->intervention?->name ?? 'Recommended Activity' }}"
                                        data-learner="{{ $log->learner->getFullName() }}"
                                        data-status="{{ $log->status }}"
                                        data-tracking="{{ $log->trackingSummary() }}">
                                    <i class="bi bi-pencil-square me-1"></i> Record progress
                                </button>
                                <a href="{{ route('learners.interventions', $log->learner) }}" class="btn btn-sm btn-light border rounded-pill">
                                    History
                                </a>
                            </div>
                        </div>
                    @empty
                        <div class="text-center py-4 text-muted">
                            <i class="bi bi-cup-hot fs-3 d-block mb-2"></i>
                            <div class="fw-semibold">Nothing open</div>
                            <small>Every assigned intervention has been completed or skipped.</small>
                        </div>
                    @endforelse
                </div>

                @if($openInterventions->isNotEmpty())
                    <a href="{{ route('interventions.index') }}" class="btn btn-sm btn-outline-primary w-100 mt-3">
                        Browse all interventions
                    </a>
                @endif
            </div>
        </div>
    </div>
</div>

@include('dashboard._intervention_action_modal')

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
