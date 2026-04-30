@extends('layouts.app')

@section('title', 'Parent Dashboard')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-house-heart me-2"></i>Parent Dashboard</h4>
        <span class="text-muted">Welcome, {{ Auth::user()->name }}</span>
    </div>

    {{-- Stats Cards --}}
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center">
                    <i class="bi bi-people display-6 text-primary"></i>
                    <h3 class="mt-2 mb-0">{{ $stats['total_children'] ?? 0 }}</h3>
                    <small class="text-muted">My Children</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center">
                    <i class="bi bi-clipboard-check display-6 text-success"></i>
                    <h3 class="mt-2 mb-0">{{ $stats['total_assessments'] ?? 0 }}</h3>
                    <small class="text-muted">Assessments</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center">
                    <i class="bi bi-lightbulb display-6 text-warning"></i>
                    <h3 class="mt-2 mb-0">{{ $stats['pending_interventions'] ?? 0 }}</h3>
                    <small class="text-muted">Pending Activities</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center">
                    <i class="bi bi-envelope display-6 text-info"></i>
                    <h3 class="mt-2 mb-0">{{ $stats['unread_messages'] ?? 0 }}</h3>
                    <small class="text-muted">Unread Messages</small>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        {{-- My Children --}}
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white">
                    <h6 class="mb-0"><i class="bi bi-people me-1"></i> My Children</h6>
                </div>
                <div class="card-body p-0">
                    <div class="list-group list-group-flush">
                        @forelse($learners as $learner)
                            <div class="list-group-item">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <strong>{{ $learner->full_name }}</strong>
                                        <br><small class="text-muted">Grade {{ $learner->grade_level }} &middot; {{ ucfirst($learner->mother_tongue ?? 'N/A') }}</small>
                                    </div>
                                    <div class="text-end">
                                        @if($learner->reading_level === 'independent')
                                            <span class="badge bg-success">Independent</span>
                                        @elseif($learner->reading_level === 'instructional')
                                            <span class="badge bg-warning text-dark">Instructional</span>
                                        @elseif($learner->reading_level === 'frustration')
                                            <span class="badge bg-danger">Frustration</span>
                                        @else
                                            <span class="badge bg-secondary">Not Assessed</span>
                                        @endif
                                    </div>
                                </div>
                                <div class="mt-2 d-flex gap-1">
                                    <a href="{{ route('parent.children.profile', $learner) }}" class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-person-lines-fill me-1"></i>Profile
                                    </a>
                                    <a href="{{ route('parent.children.assessments', $learner) }}" class="btn btn-sm btn-outline-success">
                                        <i class="bi bi-clipboard-data me-1"></i>Results
                                    </a>
                                    <a href="{{ route('parent.children.interventions', $learner) }}" class="btn btn-sm btn-outline-warning">
                                        <i class="bi bi-lightbulb me-1"></i>Activities
                                    </a>
                                </div>
                            </div>
                        @empty
                            <div class="list-group-item text-center text-muted py-4">
                                <i class="bi bi-info-circle me-1"></i>
                                No children linked to your account yet. Please contact the school administrator.
                            </div>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>

        {{-- Right Column --}}
        <div class="col-md-6">
            {{-- Pending Home Activities --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><i class="bi bi-clipboard-heart me-1"></i> Pending Home Activities</h6>
                </div>
                <div class="card-body p-0">
                    <div class="list-group list-group-flush">
                        @forelse($pendingLogs as $log)
                            <div class="list-group-item">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <strong>{{ $log->intervention?->name ?? 'N/A' }}</strong>
                                        <br><small class="text-muted">
                                            For: {{ $log->learner?->full_name ?? 'N/A' }}
                                            &middot; {{ $log->created_at?->diffForHumans() }}
                                        </small>
                                    </div>
                                    <span class="badge {{ $log->status === 'in_progress' ? 'bg-primary' : 'bg-secondary' }}">
                                        {{ ucfirst(str_replace('_', ' ', $log->status)) }}
                                    </span>
                                </div>
                                @if($log->status === 'pending')
                                    <form method="POST" action="{{ route('parent.children.intervention-update', [$log->learner_id, $log]) }}" class="mt-2">
                                        @csrf
                                        <input type="hidden" name="action" value="start">
                                        <button type="submit" class="btn btn-sm btn-primary">
                                            <i class="bi bi-play-fill me-1"></i>Start Activity
                                        </button>
                                    </form>
                                @endif
                            </div>
                        @empty
                            <div class="list-group-item text-center text-muted py-3">
                                No pending activities
                            </div>
                        @endforelse
                    </div>
                </div>
            </div>

            {{-- Recent Assessment Results --}}
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h6 class="mb-0"><i class="bi bi-clipboard-check me-1"></i> Recent Assessments</h6>
                </div>
                <div class="card-body p-0">
                    <div class="list-group list-group-flush">
                        @forelse($recentAssessments as $assessment)
                            <a href="{{ route('parent.children.assessment-detail', [$assessment->learner_id, $assessment]) }}"
                               class="list-group-item list-group-item-action">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <strong>{{ $assessment->learner?->first_name }}</strong>
                                        <span class="text-muted">&middot; {{ $assessment->material?->title ?? 'Untitled' }}</span>
                                        <br><small class="text-muted">{{ $assessment->created_at?->format('M d, Y') }}</small>
                                    </div>
                                    @if($assessment->result)
                                        <div class="text-end">
                                            <span class="badge {{ ($assessment->result->accuracy_rate ?? 0) >= 80 ? 'bg-success' : (($assessment->result->accuracy_rate ?? 0) >= 60 ? 'bg-warning text-dark' : 'bg-danger') }}">
                                                {{ number_format($assessment->result->accuracy_rate ?? 0, 1) }}%
                                            </span>
                                        </div>
                                    @else
                                        <span class="badge bg-secondary">Pending</span>
                                    @endif
                                </div>
                            </a>
                        @empty
                            <div class="list-group-item text-center text-muted py-3">
                                No assessments yet
                            </div>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
