{{-- Student Dashboard Partial --}}

{{-- Welcome Card --}}
<div class="card border-0 shadow-sm bg-primary text-white mb-4">
    <div class="card-body py-4">
        <div class="row align-items-center">
            <div class="col-md-8">
                <h4>Hello, {{ Auth::user()->name }}!</h4>
                <p class="mb-0 opacity-75">Keep reading and practicing to improve your skills.</p>
            </div>
            <div class="col-md-4 text-end">
                <i class="bi bi-book display-3 opacity-50"></i>
            </div>
        </div>
    </div>
</div>

@if($linkedLearner ?? false)
    {{-- Stats Cards --}}
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm text-center">
                <div class="card-body">
                    <i class="bi bi-star display-6 text-warning"></i>
                    <h5 class="mt-2 mb-0">
                        @if($linkedLearner->reading_level === 'independent')
                            <span class="text-success">Independent</span>
                        @elseif($linkedLearner->reading_level === 'instructional')
                            <span class="text-warning">Instructional</span>
                        @elseif($linkedLearner->reading_level === 'frustration')
                            <span class="text-danger">Frustration</span>
                        @else
                            <span class="text-muted">Not Yet Assessed</span>
                        @endif
                    </h5>
                    <small class="text-muted">Reading Level</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm text-center">
                <div class="card-body">
                    <i class="bi bi-clipboard-check display-6 text-primary"></i>
                    <h3 class="mt-2 mb-0">{{ $stats['total_assessments'] ?? 0 }}</h3>
                    <small class="text-muted">Assessments Taken</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm text-center">
                <div class="card-body">
                    <i class="bi bi-bullseye display-6 text-success"></i>
                    <h3 class="mt-2 mb-0">{{ number_format($stats['latest_accuracy'] ?? 0, 1) }}%</h3>
                    <small class="text-muted">Latest Accuracy</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm text-center">
                <div class="card-body">
                    <i class="bi bi-controller display-6 text-info"></i>
                    <h3 class="mt-2 mb-0">{{ $stats['practice_sessions'] ?? 0 }}</h3>
                    <small class="text-muted">Practice Sessions</small>
                </div>
            </div>
        </div>
    </div>

    {{-- Quick Actions --}}
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <a href="{{ route('practice.index') }}" class="card border-0 shadow-sm text-decoration-none h-100">
                <div class="card-body text-center py-4">
                    <i class="bi bi-controller display-4 text-primary"></i>
                    <h5 class="mt-3">Practice Center</h5>
                    <p class="text-muted small mb-0">Practice phonics, sight words, and reading</p>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a href="{{ route('student.progress') }}" class="card border-0 shadow-sm text-decoration-none h-100">
                <div class="card-body text-center py-4">
                    <i class="bi bi-graph-up display-4 text-success"></i>
                    <h5 class="mt-3">My Progress</h5>
                    <p class="text-muted small mb-0">View your reading improvement over time</p>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a href="{{ route('student.assessments') }}" class="card border-0 shadow-sm text-decoration-none h-100">
                <div class="card-body text-center py-4">
                    <i class="bi bi-clipboard-data display-4 text-warning"></i>
                    <h5 class="mt-3">My Assessments</h5>
                    <p class="text-muted small mb-0">Review past assessment results</p>
                </div>
            </a>
        </div>
    </div>

    {{-- Progress Chart --}}
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white">
            <h6 class="mb-0"><i class="bi bi-graph-up me-1"></i> My Reading Progress</h6>
        </div>
        <div class="card-body">
            <canvas id="studentProgressChart" height="200"></canvas>
        </div>
    </div>

    @push('scripts')
    <script>
        const progCtx = document.getElementById('studentProgressChart');
        if (progCtx) {
            new Chart(progCtx, {
                type: 'line',
                data: {
                    labels: {!! json_encode($progressLabels ?? []) !!},
                    datasets: [{
                        label: 'Accuracy %',
                        data: {!! json_encode($progressData ?? []) !!},
                        borderColor: '#0d6efd',
                        backgroundColor: 'rgba(13, 110, 253, 0.1)',
                        fill: true,
                        tension: 0.3
                    }]
                },
                options: {
                    responsive: true,
                    scales: { y: { beginAtZero: true, max: 100 } },
                    plugins: { legend: { display: false } }
                }
            });
        }
    </script>
    @endpush

@else
    {{-- Unlinked student --}}
    <div class="card border-0 shadow-sm">
        <div class="card-body text-center py-5">
            <i class="bi bi-link-45deg display-1 text-muted"></i>
            <h4 class="mt-3">Account Not Linked</h4>
            <p class="text-muted">
                Your student account hasn't been linked to a learner profile yet.<br>
                Please ask your teacher to link your account.
            </p>
        </div>
    </div>
@endif
