@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    @php
        $tiles = [
            ['Users',          $stats['total_users'],         'bi-people',         'primary', route('admin.users'),     'Manage users'],
            ['Learners',       $stats['total_learners'],      'bi-person-hearts',  'success', route('learners.index'),  'View learners'],
            ['Assessments',    $stats['total_assessments'],   'bi-clipboard-check','info',    route('assessments.index'), 'View assessments'],
            ['Materials',      $stats['total_materials'],     'bi-journal-text',   'warning', route('admin.materials'), 'View materials'],
            ['Interventions',  $stats['total_interventions'], 'bi-lightbulb',      'secondary', route('admin.interventions'), 'Manage interventions'],
        ];
    @endphp

    {{-- Header --}}
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h4 class="mb-1"><i class="bi bi-speedometer2 me-2"></i>Dashboard</h4>
            <p class="text-muted mb-0 small">
                Welcome back, {{ auth()->user()->first_name ?? auth()->user()->name ?? 'Admin' }}.
                @if($school) <i class="bi bi-building ms-2 me-1"></i>{{ $school->name }} @endif
                <span class="ms-2"><i class="bi bi-calendar3 me-1"></i>{{ now()->format('l, F j, Y') }}</span>
            </p>
        </div>
        <a href="{{ route('admin.phil-iri') }}" class="btn btn-primary btn-sm">
            <i class="bi bi-journal-bookmark-fill me-1"></i> Phil-IRI Forms
        </a>
    </div>

    {{-- Key counts, each linking to where it is managed --}}
    <div class="row g-3 mb-4">
        @foreach($tiles as [$label, $value, $icon, $color, $url, $linkText])
            <div class="col-6 col-md-4 col-xl">
                <a href="{{ $url }}" class="card border-0 shadow-sm h-100 text-decoration-none">
                    <div class="card-body py-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <small class="text-muted fw-bold text-uppercase">{{ $label }}</small>
                            <span class="bg-{{ $color }} bg-opacity-10 text-{{ $color }} rounded d-flex align-items-center justify-content-center" style="width: 36px; height: 36px;">
                                <i class="bi {{ $icon }} fs-5"></i>
                            </span>
                        </div>
                        <div class="display-6 fw-bold text-dark">{{ number_format($value) }}</div>
                        <div class="small text-muted mt-1">{{ $linkText }} <i class="bi bi-arrow-right"></i></div>
                    </div>
                </a>
            </div>
        @endforeach
    </div>

    <div class="row g-3">
        {{-- Left: needs attention + recent activity --}}
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold"><i class="bi bi-bell me-1"></i> Needs your attention</div>
                <div class="list-group list-group-flush">
                    <a href="{{ route('reports.submissions.index') }}" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-inbox me-2 text-primary"></i>Teacher reports waiting for review</span>
                        @if($pendingReports > 0)
                            <span class="badge bg-danger rounded-pill">{{ $pendingReports }}</span>
                        @else
                            <span class="small text-muted">All caught up</span>
                        @endif
                    </a>
                    <a href="{{ route('reports.form2.index') }}" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-file-earmark-check me-2 text-primary"></i>DepEd Form 2 &ndash; School Reading Profile</span>
                        <i class="bi bi-chevron-right text-muted"></i>
                    </a>
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-clock-history me-1"></i> Recent Activity</span>
                    <a href="{{ route('admin.logs') }}" class="small text-decoration-none">View all</a>
                </div>
                <div class="card-body p-0">
                    @forelse($recentActivity as $log)
                        <div class="d-flex align-items-start gap-2 px-3 py-2 border-bottom">
                            <i class="bi bi-circle-fill text-primary mt-2" style="font-size: 0.45rem;"></i>
                            <div class="flex-grow-1" style="min-width: 0;">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="fw-semibold text-truncate small">{{ $log->user?->name ?? 'System' }}</span>
                                    <span class="text-muted ms-2" style="font-size: 0.72rem; white-space: nowrap;">{{ $log->created_at?->diffForHumans() }}</span>
                                </div>
                                <div class="text-muted" style="font-size: 0.8rem;">{{ $log->description ?? $log->action ?? '' }}</div>
                            </div>
                        </div>
                    @empty
                        <div class="text-center text-muted py-5">
                            <i class="bi bi-inbox display-6 d-block mb-2"></i>No activity recorded yet
                        </div>
                    @endforelse
                </div>
            </div>
        </div>

        {{-- Right: school-wide reading weaknesses --}}
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-semibold"><i class="bi bi-pie-chart me-1"></i> School-Wide Reading Weaknesses</div>
                <div class="card-body d-flex align-items-center justify-content-center">
                    @if(array_sum($weaknessData) > 0)
                        <div style="width: 100%; height: 300px;"><canvas id="weaknessChart"></canvas></div>
                    @else
                        <div class="text-center text-muted py-5">
                            <i class="bi bi-pie-chart display-6 d-block mb-2"></i>No analysed assessments yet
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
@if(array_sum($weaknessData) > 0)
<script>
    new Chart(document.getElementById('weaknessChart'), {
        type: 'doughnut',
        data: {
            labels: {!! json_encode($weaknessLabels) !!},
            datasets: [{ data: {!! json_encode($weaknessData) !!}, backgroundColor: ['#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0'], borderWidth: 1 }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
    });
</script>
@endif
@endpush
