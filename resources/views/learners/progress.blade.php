@extends('layouts.app')

@section('title', $learner->full_name . ' - Progress')

@section('content')
    <x-page-header :title="$learner->full_name" icon="bi-graph-up" subtitle="Progress"
                   :back="route('learners.show', $learner)" back-label="Learner profile">
        <x-slot:actions>
            <a href="{{ route('reports.learner', $learner) }}" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-file-earmark-bar-graph me-1"></i> Full Report
            </a>
        </x-slot:actions>
    </x-page-header>

    {{-- Progress Charts --}}
    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white"><h6 class="mb-0">Accuracy Over Time</h6></div>
                <div class="card-body">
                    <canvas id="accuracyChart" height="250"></canvas>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white"><h6 class="mb-0">Words Per Minute Over Time</h6></div>
                <div class="card-body">
                    <canvas id="wpmChart" height="250"></canvas>
                </div>
            </div>
        </div>
    </div>

    {{-- Skill Breakdown --}}
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white"><h6 class="mb-0">Skill Breakdown</h6></div>
        <div class="card-body">
            <div class="row g-3">
                {{-- Comprehension comes from the questions, so it is null when none
                     were asked. An empty bar says "not checked"; 0% would read as a
                     score the child earned. --}}
                @foreach(['phonemic' => 'Phonemic Awareness', 'decoding' => 'Decoding Accuracy', 'fluency' => 'Oral Reading Fluency', 'comprehension' => 'Reading Comprehension'] as $key => $label)
                    @php $score = $skillScores[$key] ?? null; @endphp
                    <div class="col-md-3">
                        <div class="text-center">
                            <h6 class="small text-muted">{{ $label }}</h6>
                            <div class="progress" style="height: 10px;">
                                @if($score !== null)
                                    <div class="progress-bar {{ $score >= 80 ? 'bg-success' : ($score >= 60 ? 'bg-warning' : 'bg-danger') }}"
                                         style="width: {{ $score }}%"></div>
                                @endif
                            </div>
                            <small class="{{ $score === null ? 'text-muted' : 'fw-bold' }}">
                                {{ $score === null ? 'Not checked' : number_format($score, 1) . '%' }}
                            </small>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Interventions --}}
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <h6 class="mb-0">Assigned Interventions</h6>
            <a href="{{ route('learners.interventions', $learner) }}" class="btn btn-sm btn-outline-primary">View All</a>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr><th>Intervention</th><th>Status</th><th>Assigned</th><th>Completed</th></tr>
                    </thead>
                    <tbody>
                        @forelse($interventionLogs ?? [] as $log)
                            <tr>
                                <td>{{ $log->intervention?->name ?? 'N/A' }}</td>
                                <td>
                                    <span class="badge {{ $log->status === 'completed' ? 'bg-success' : ($log->status === 'in_progress' ? 'bg-primary' : 'bg-secondary') }}">
                                        {{ ucfirst(str_replace('_', ' ', $log->status)) }}
                                    </span>
                                </td>
                                <td>{{ $log->assigned_at ? \Carbon\Carbon::parse($log->assigned_at)->format('M d, Y') : '-' }}</td>
                                <td>{{ $log->completed_at ? \Carbon\Carbon::parse($log->completed_at)->format('M d, Y') : '-' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-muted py-3">No interventions assigned</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

@push('scripts')
<script>
    const dates = {!! json_encode($progressDates ?? []) !!};
    const accuracies = {!! json_encode($progressAccuracy ?? []) !!};
    const wpms = {!! json_encode($progressWpm ?? []) !!};

    new Chart(document.getElementById('accuracyChart'), {
        type: 'line', data: { labels: dates, datasets: [{ label: 'Accuracy %', data: accuracies, borderColor: '#0d6efd', backgroundColor: 'rgba(13,110,253,0.1)', fill: true, tension: 0.3 }] },
        options: { responsive: true, scales: { y: { beginAtZero: true, max: 100 } } }
    });
    new Chart(document.getElementById('wpmChart'), {
        type: 'line', data: { labels: dates, datasets: [{ label: 'WPM', data: wpms, borderColor: '#198754', backgroundColor: 'rgba(25,135,84,0.1)', fill: true, tension: 0.3 }] },
        options: { responsive: true, scales: { y: { beginAtZero: true } } }
    });
</script>
@endpush
@endsection
