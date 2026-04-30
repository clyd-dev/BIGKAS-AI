@extends('layouts.app')

@section('title', $learner->full_name . ' - Home Activities')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-clipboard-heart me-2"></i>{{ $learner->full_name }} - Home Activities</h4>
        <div>
            <a href="{{ route('parent.children.profile', $learner) }}" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-person-lines-fill me-1"></i>Profile
            </a>
            <a href="{{ route('parent.dashboard') }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i>Back
            </a>
        </div>
    </div>

    {{-- Intervention Stats --}}
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm text-center">
                <div class="card-body">
                    <h3 class="text-primary mb-0">{{ $interventionStats['totalAssigned'] ?? 0 }}</h3>
                    <small class="text-muted">Total Assigned</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm text-center">
                <div class="card-body">
                    <h3 class="text-warning mb-0">{{ $interventionStats['pending'] ?? 0 }}</h3>
                    <small class="text-muted">Pending</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm text-center">
                <div class="card-body">
                    <h3 class="text-info mb-0">{{ $interventionStats['inProgress'] ?? 0 }}</h3>
                    <small class="text-muted">In Progress</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm text-center">
                <div class="card-body">
                    <h3 class="text-success mb-0">{{ $interventionStats['completed'] ?? 0 }}</h3>
                    <small class="text-muted">Completed</small>
                </div>
            </div>
        </div>
    </div>

    {{-- Activity List --}}
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white">
            <h6 class="mb-0">Assigned Activities</h6>
        </div>
        <div class="card-body p-0">
            <div class="list-group list-group-flush">
                @forelse($logs as $log)
                    <div class="list-group-item">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="flex-grow-1">
                                <h6 class="mb-1">{{ $log->intervention?->name ?? 'N/A' }}</h6>
                                <p class="text-muted small mb-1">{{ Str::limit($log->intervention?->description, 120) }}</p>
                                <div class="d-flex gap-2 flex-wrap">
                                    @php $weaknessLabels = config('bigkas.weakness_categories', []); @endphp
                                    <span class="badge bg-light text-dark border">
                                        <i class="bi bi-bullseye me-1"></i>{{ $weaknessLabels[$log->intervention?->target_weakness]['name'] ?? 'General' }}
                                    </span>
                                    @if($log->intervention?->duration_minutes)
                                        <span class="badge bg-light text-dark border">
                                            <i class="bi bi-clock me-1"></i>{{ $log->intervention->duration_minutes }} min
                                        </span>
                                    @endif
                                    <span class="badge bg-light text-dark border">
                                        <i class="bi bi-person me-1"></i>{{ $log->assigner?->name ?? 'Teacher' }}
                                    </span>
                                    <small class="text-muted">{{ $log->created_at?->format('M d, Y') }}</small>
                                </div>

                                {{-- Instructions (collapsed) --}}
                                @if($log->intervention?->instructions)
                                    <div class="mt-2">
                                        <a class="small text-primary" data-bs-toggle="collapse" href="#instructions-{{ $log->id }}">
                                            <i class="bi bi-info-circle me-1"></i>View Instructions
                                        </a>
                                        <div class="collapse mt-2" id="instructions-{{ $log->id }}">
                                            <div class="card card-body bg-light small">
                                                {!! nl2br(e($log->intervention->instructions)) !!}
                                            </div>
                                        </div>
                                    </div>
                                @endif

                                {{-- Notes --}}
                                @if($log->notes)
                                    <div class="mt-1">
                                        <small class="text-muted"><i class="bi bi-chat-left-text me-1"></i>{{ $log->notes }}</small>
                                    </div>
                                @endif
                            </div>

                            <div class="text-end ms-3" style="min-width: 140px;">
                                {{-- Status badge --}}
                                <span class="badge {{ $log->status === 'completed' ? 'bg-success' : ($log->status === 'in_progress' ? 'bg-primary' : ($log->status === 'skipped' ? 'bg-secondary' : 'bg-warning text-dark')) }} mb-2 d-block">
                                    {{ ucfirst(str_replace('_', ' ', $log->status)) }}
                                </span>

                                {{-- Action buttons --}}
                                @if($log->status === 'pending')
                                    <form method="POST" action="{{ route('parent.children.intervention-update', [$learner, $log]) }}">
                                        @csrf
                                        <input type="hidden" name="action" value="start">
                                        <button type="submit" class="btn btn-sm btn-primary w-100">
                                            <i class="bi bi-play-fill me-1"></i>Start
                                        </button>
                                    </form>
                                @elseif($log->status === 'in_progress')
                                    <button type="button" class="btn btn-sm btn-success w-100" data-bs-toggle="modal" data-bs-target="#completeModal-{{ $log->id }}">
                                        <i class="bi bi-check-lg me-1"></i>Complete
                                    </button>

                                    {{-- Complete Modal --}}
                                    <div class="modal fade" id="completeModal-{{ $log->id }}" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <form method="POST" action="{{ route('parent.children.intervention-update', [$learner, $log]) }}">
                                                    @csrf
                                                    <input type="hidden" name="action" value="complete">
                                                    <div class="modal-header">
                                                        <h6 class="modal-title">Complete Activity</h6>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body text-start">
                                                        <div class="mb-3">
                                                            <label class="form-label small">How effective was this activity? (1-10)</label>
                                                            <input type="range" class="form-range" name="effectiveness_rating" min="1" max="10" value="7" id="rating-{{ $log->id }}"
                                                                   oninput="document.getElementById('ratingVal-{{ $log->id }}').textContent = this.value">
                                                            <div class="text-center"><span id="ratingVal-{{ $log->id }}">7</span>/10</div>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label small">Notes (optional)</label>
                                                            <textarea name="notes" class="form-control form-control-sm" rows="3" placeholder="How did your child do? Any observations..."></textarea>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                        <button type="submit" class="btn btn-sm btn-success">Mark Complete</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                @elseif($log->status === 'completed')
                                    <small class="text-muted d-block">
                                        {{ $log->completed_at?->format('M d') }}
                                    </small>
                                    @if($log->effectiveness_rating)
                                        <small class="text-success">Rating: {{ $log->effectiveness_rating }}/10</small>
                                    @endif
                                @endif
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="list-group-item text-center text-muted py-4">
                        <i class="bi bi-inbox me-1"></i> No activities assigned yet.
                        <br><small>The teacher will assign home reading activities here.</small>
                    </div>
                @endforelse
            </div>
        </div>
    </div>

    @if($logs->hasPages())
        <div class="mt-3">
            {{ $logs->links() }}
        </div>
    @endif
@endsection
