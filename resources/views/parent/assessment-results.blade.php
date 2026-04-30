@extends('layouts.app')

@section('title', $learner->full_name . ' - Assessment Results')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-clipboard-data me-2"></i>{{ $learner->full_name }} - Assessment Results</h4>
        <div>
            <a href="{{ route('parent.children.profile', $learner) }}" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-person-lines-fill me-1"></i>Profile
            </a>
            <a href="{{ route('parent.dashboard') }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i>Back
            </a>
        </div>
    </div>

    {{-- Summary Stats --}}
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm text-center">
                <div class="card-body">
                    <h3 class="text-primary mb-0">{{ $stats['total_assessments'] ?? 0 }}</h3>
                    <small class="text-muted">Total Assessments</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm text-center">
                <div class="card-body">
                    <h3 class="text-success mb-0">{{ number_format($stats['avg_accuracy'] ?? 0, 1) }}%</h3>
                    <small class="text-muted">Avg Accuracy</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm text-center">
                <div class="card-body">
                    <h3 class="text-info mb-0">{{ round($stats['avg_wpm'] ?? 0) }}</h3>
                    <small class="text-muted">Avg WPM</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm text-center">
                <div class="card-body">
                    @if($learner->reading_level === 'independent')
                        <span class="badge bg-success fs-6 px-3 py-2">Independent</span>
                    @elseif($learner->reading_level === 'instructional')
                        <span class="badge bg-warning text-dark fs-6 px-3 py-2">Instructional</span>
                    @elseif($learner->reading_level === 'frustration')
                        <span class="badge bg-danger fs-6 px-3 py-2">Frustration</span>
                    @else
                        <span class="badge bg-secondary fs-6 px-3 py-2">N/A</span>
                    @endif
                    <div class="small text-muted mt-1">Current Level</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Assessment History --}}
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white"><h6 class="mb-0">Assessment History</h6></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Material</th>
                            <th>Language</th>
                            <th>Accuracy</th>
                            <th>WPM</th>
                            <th>Reading Level</th>
                            <th>Assessed By</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($assessments as $assessment)
                            @php $r = $assessment->result; @endphp
                            <tr>
                                <td>{{ $assessment->created_at?->format('M d, Y') }}</td>
                                <td>{{ $assessment->material?->title ?? 'N/A' }}</td>
                                <td>{{ ucfirst($assessment->material?->language ?? '-') }}</td>
                                <td>
                                    @if($r)
                                        <span class="badge {{ ($r->accuracy_rate ?? 0) >= 80 ? 'bg-success' : (($r->accuracy_rate ?? 0) >= 60 ? 'bg-warning text-dark' : 'bg-danger') }}">
                                            {{ number_format($r->accuracy_rate ?? 0, 1) }}%
                                        </span>
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                                <td>{{ $r?->words_per_minute ?? '-' }}</td>
                                <td>
                                    @if($r?->reading_level === 'independent')
                                        <span class="badge bg-success">Ind.</span>
                                    @elseif($r?->reading_level === 'instructional')
                                        <span class="badge bg-warning text-dark">Inst.</span>
                                    @elseif($r?->reading_level === 'frustration')
                                        <span class="badge bg-danger">Frus.</span>
                                    @else
                                        <span class="badge bg-secondary">-</span>
                                    @endif
                                </td>
                                <td>{{ $assessment->assessor?->name ?? '-' }}</td>
                                <td>
                                    @if($r)
                                        <a href="{{ route('parent.children.assessment-detail', [$learner, $assessment]) }}" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="text-center text-muted py-4">No assessments recorded yet</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    @if($assessments->hasPages())
        <div class="mt-3">
            {{ $assessments->links() }}
        </div>
    @endif
@endsection
