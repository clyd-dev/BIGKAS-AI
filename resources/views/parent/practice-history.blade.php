@extends('layouts.parent')

@section('title', $learner->full_name . ' - Practice History')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-controller me-2"></i>{{ $learner->full_name }} - Practice History</h4>
        <a href="{{ route('parent.children.profile', $learner) }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm text-center"><div class="card-body">
                <h3 class="text-primary mb-0">{{ $stats['total_sessions'] }}</h3>
                <small class="text-muted">Sessions</small>
            </div></div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm text-center"><div class="card-body">
                <h3 class="text-success mb-0">{{ $stats['avg_score'] !== null ? number_format($stats['avg_score'], 1) . '%' : '-' }}</h3>
                <small class="text-muted">Average Score</small>
            </div></div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm text-center"><div class="card-body">
                <h3 class="text-info mb-0">{{ floor($stats['total_time'] / 60) }} min</h3>
                <small class="text-muted">Total Practice Time</small>
            </div></div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="list-group list-group-flush">
            @forelse($sessions as $session)
                <div class="list-group-item">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <strong>{{ $session->getTypeName() }}</strong>
                            @if($session->material)
                                <span class="text-muted">&middot; {{ $session->material->title }}</span>
                            @endif
                            <br>
                            <small class="text-muted">
                                {{ $session->time_spent ? $session->getTimeSpentFormatted() : '-' }}
                                &middot; {{ $session->created_at?->format('M d, Y g:i A') }}
                            </small>
                        </div>
                        @if($session->score !== null)
                            <span class="badge {{ $session->score >= 80 ? 'bg-success' : ($session->score >= 60 ? 'bg-warning text-dark' : 'bg-danger') }}">
                                {{ round($session->score) }}%
                            </span>
                        @endif
                    </div>
                </div>
            @empty
                <div class="list-group-item text-center text-muted py-4">No practice sessions yet.</div>
            @endforelse
        </div>
    </div>

    @if($sessions->hasPages())
        <div class="mt-3">{{ $sessions->links() }}</div>
    @endif
@endsection
