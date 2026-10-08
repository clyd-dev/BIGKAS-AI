@extends('layouts.app')

@section('title', 'Reports')

@section('content')
    <x-page-header title="Reports" icon="bi-bar-chart">
        <x-slot:actions>
            <a href="{{ route('reports.form2.index') }}" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-file-earmark-check me-1"></i> DepEd Form 2
            </a>
            <a href="{{ route('reports.submissions.index') }}" class="btn btn-primary btn-sm position-relative">
                <i class="bi bi-inbox me-1"></i> Teacher Reports
                @if($pendingReports > 0)
                    <span class="badge bg-danger ms-1">{{ $pendingReports }} new</span>
                @endif
            </a>
        </x-slot:actions>
    </x-page-header>

    {{-- School summary --}}
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center"><div class="card-body">
                <h3 class="text-primary mb-0">{{ $stats['total_learners'] }}</h3><small class="text-muted">Total Learners</small>
            </div></div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center"><div class="card-body">
                <h3 class="text-success mb-0">{{ $stats['assessed'] }}</h3><small class="text-muted">Assessed</small>
            </div></div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center"><div class="card-body">
                <h3 class="text-info mb-0">{{ number_format($stats['avg_accuracy'], 1) }}%</h3><small class="text-muted">Avg Accuracy</small>
            </div></div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center"><div class="card-body">
                <h3 class="text-warning mb-0">{{ round($stats['avg_wpm']) }}</h3><small class="text-muted">Avg WPM</small>
            </div></div>
        </div>
    </div>

    <div class="row g-3">
        {{-- Section table --}}
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-people me-1"></i> Section Reports</h6></div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Grade &amp; Section</th>
                                    <th>Teacher</th>
                                    <th class="text-center">Learners</th>
                                    <th class="text-center">Assessed</th>
                                    <th>Reading Levels</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($sections as $section)
                                    @php $assessed = $section->assessed_count; @endphp
                                    <tr>
                                        <td class="fw-semibold">Grade {{ $section->grade_level }} – {{ $section->section }}</td>
                                        <td>{{ $section->teacher?->name ?? '—' }}</td>
                                        <td class="text-center">{{ $section->learners_count }}</td>
                                        <td class="text-center">{{ $assessed }}</td>
                                        <td style="min-width: 150px;">
                                            @if($assessed > 0)
                                                <div class="progress" style="height: 8px;"
                                                     title="Independent {{ $section->independent_count }} · Instructional {{ $section->instructional_count }} · Frustration {{ $section->frustration_count }}">
                                                    <div class="progress-bar bg-success" style="width: {{ $section->independent_count / $assessed * 100 }}%"></div>
                                                    <div class="progress-bar bg-warning" style="width: {{ $section->instructional_count / $assessed * 100 }}%"></div>
                                                    <div class="progress-bar bg-danger" style="width: {{ $section->frustration_count / $assessed * 100 }}%"></div>
                                                </div>
                                            @else
                                                <span class="small text-muted">Not assessed</span>
                                            @endif
                                        </td>
                                        <td class="text-end">
                                            <a href="{{ route('reports.class', $section) }}" class="btn btn-sm btn-primary px-3">
                                                <i class="bi bi-eye me-1"></i> View
                                            </a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="text-center text-muted py-4">No sections found.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="d-flex flex-wrap justify-content-between align-items-center mt-3 gap-2">
                <div class="small text-muted">
                    @if($sections->total() > 0)
                        Showing {{ $sections->firstItem() }}–{{ $sections->lastItem() }} of {{ $sections->total() }}
                    @endif
                </div>
                {{ $sections->onEachSide(1)->links('partials.pagination') }}
            </div>
        </div>

        {{-- Distribution --}}
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white"><h6 class="mb-0">Reading Level Distribution</h6></div>
                <div class="card-body"><canvas id="distChart" height="220"></canvas></div>
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
                data: [{{ $distribution['independent'] }}, {{ $distribution['instructional'] }}, {{ $distribution['frustration'] }}, {{ $distribution['not_assessed'] }}],
                backgroundColor: ['#198754', '#ffc107', '#dc3545', '#6c757d']
            }]
        },
        options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
    });
</script>
@endpush
@endsection
