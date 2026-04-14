@extends('layouts.app')

@section('title', $learner->full_name)

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-person me-2"></i>{{ $learner->full_name }}</h4>
        <div>
            <a href="{{ route('assessments.start', $learner) }}" class="btn btn-primary btn-sm">
                <i class="bi bi-mic me-1"></i> New Assessment
            </a>
            <a href="{{ route('learners.edit', $learner) }}" class="btn btn-outline-warning btn-sm">
                <i class="bi bi-pencil me-1"></i> Edit
            </a>
            <a href="{{ route('learners.index') }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i> Back
            </a>
        </div>
    </div>

    {{-- Info Cards --}}
    <div class="row g-3 mb-4">
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
                        <span class="badge bg-secondary fs-6 px-3 py-2">Not Assessed</span>
                    @endif
                    <div class="small text-muted mt-2">Reading Level</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm text-center">
                <div class="card-body">
                    <h4 class="text-primary mb-0">Grade {{ $learner->grade_level }}</h4>
                    <div class="small text-muted mt-1">Grade Level</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm text-center">
                <div class="card-body">
                    <h4 class="mb-0">{{ $assessmentCount ?? 0 }}</h4>
                    <div class="small text-muted mt-1">Assessments</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm text-center">
                <div class="card-body">
                    <h4 class="mb-0">{{ ucfirst($learner->mother_tongue ?? 'N/A') }}</h4>
                    <div class="small text-muted mt-1">Mother Tongue</div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        {{-- Learner Details --}}
        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white"><h6 class="mb-0">Details</h6></div>
                <div class="card-body">
                    <table class="table table-borderless table-sm mb-0">
                        <tr><th class="text-muted" width="40%">LRN</th><td>{{ $learner->lrn ?? 'N/A' }}</td></tr>
                        <tr><th class="text-muted">Gender</th><td>{{ ucfirst($learner->gender ?? 'N/A') }}</td></tr>
                        <tr><th class="text-muted">Birth Date</th><td>{{ $learner->birth_date ? \Carbon\Carbon::parse($learner->birth_date)->format('M d, Y') : 'N/A' }}</td></tr>
                        <tr><th class="text-muted">School</th><td>{{ $learner->school?->name ?? 'N/A' }}</td></tr>
                        <tr><th class="text-muted">Class</th><td>{{ $learner->schoolClass?->name ?? 'N/A' }}</td></tr>
                        <tr><th class="text-muted">Added</th><td>{{ $learner->created_at?->format('M d, Y') }}</td></tr>
                    </table>
                </div>
            </div>
        </div>

        {{-- Assessment History --}}
        <div class="col-md-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h6 class="mb-0">Assessment History</h6>
                    <a href="{{ route('learners.progress', $learner) }}" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-graph-up me-1"></i> Full Progress
                    </a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Date</th>
                                    <th>Material</th>
                                    <th>Accuracy</th>
                                    <th>WPM</th>
                                    <th>Level</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($assessments ?? [] as $assessment)
                                    <tr>
                                        <td>{{ $assessment->created_at?->format('M d, Y') }}</td>
                                        <td>{{ $assessment->material?->title ?? 'N/A' }}</td>
                                        <td>{{ $assessment->results->first()?->accuracy_rate ?? '-' }}%</td>
                                        <td>{{ $assessment->results->first()?->words_per_minute ?? '-' }}</td>
                                        <td>
                                            @php $level = $assessment->results->first()?->reading_level; @endphp
                                            @if($level === 'independent')
                                                <span class="badge bg-success">Ind.</span>
                                            @elseif($level === 'instructional')
                                                <span class="badge bg-warning text-dark">Inst.</span>
                                            @elseif($level === 'frustration')
                                                <span class="badge bg-danger">Frus.</span>
                                            @else
                                                <span class="badge bg-secondary">-</span>
                                            @endif
                                        </td>
                                        <td>
                                            <a href="{{ route('assessments.results', $assessment) }}" class="btn btn-sm btn-outline-primary">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-center text-muted py-3">No assessments yet</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
