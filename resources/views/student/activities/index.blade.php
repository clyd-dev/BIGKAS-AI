@extends('layouts.student')

@section('title', 'My Activities')

@section('content')

    <h4 class="fw-bold mb-3">🎯 My Activities</h4>

    {{-- Pending / In-Progress Activities --}}
    @if($pendingActivities->count())
        <h6 class="fw-bold text-uppercase mb-2" style="font-size: 0.75rem; color: var(--kid-text-light); letter-spacing: 0.5px;">
            To Do ({{ $pendingActivities->count() }})
        </h6>
        @foreach($pendingActivities as $activity)
            <a href="{{ route('student.activities.show', $activity) }}" class="activity-card">
                <div class="activity-icon" style="background: var(--kid-primary-light); color: var(--kid-primary);">
                    @if($activity->status === 'in_progress')
                        ⏳
                    @else
                        📋
                    @endif
                </div>
                <div class="activity-info">
                    <h6>{{ $activity->intervention->name ?? 'Activity' }}</h6>
                    <p>{{ Str::limit($activity->intervention->description ?? 'Complete this activity', 60) }}</p>
                </div>
                <div class="text-end">
                    @if($activity->status === 'in_progress')
                        <span class="badge rounded-pill" style="background: var(--kid-warning); font-size: 0.65rem;">In Progress</span>
                    @else
                        <span class="badge rounded-pill" style="background: var(--kid-primary); font-size: 0.65rem;">New</span>
                    @endif
                    <br>
                    <small class="text-muted" style="font-size: 0.7rem;">+15 XP</small>
                </div>
            </a>
        @endforeach
    @else
        <div class="kid-card text-center py-4 mb-3">
            <div style="font-size: 3rem;" class="mb-2">✅</div>
            <h5 class="fw-bold">All Caught Up!</h5>
            <p class="text-muted mb-0" style="font-size: 0.85rem;">
                No pending activities. Try flash cards or guided reading!
            </p>
        </div>
    @endif

    {{-- Quick Actions --}}
    <div class="row g-2 mb-4">
        <div class="col-6">
            <a href="{{ route('student.flashcards') }}" class="action-tile">
                <div class="tile-icon" style="background: var(--kid-primary-light); color: var(--kid-primary);">
                    🃏
                </div>
                <h6>Flash Cards</h6>
                <p>Practice words</p>
            </a>
        </div>
        <div class="col-6">
            <a href="{{ route('student.guided-reading') }}" class="action-tile">
                <div class="tile-icon" style="background: #FFF0E6; color: var(--kid-orange);">
                    📚
                </div>
                <h6>Guided Reading</h6>
                <p>Read stories</p>
            </a>
        </div>
    </div>

    {{-- Completed Activities --}}
    @if($completedActivities->count())
        <h6 class="fw-bold text-uppercase mb-2" style="font-size: 0.75rem; color: var(--kid-text-light); letter-spacing: 0.5px;">
            Completed ({{ $completedActivities->count() }})
        </h6>
        @foreach($completedActivities as $activity)
            <div class="activity-card completed">
                <div class="activity-icon" style="background: #E6FFF5; color: var(--kid-success);">
                    ✅
                </div>
                <div class="activity-info">
                    <h6>{{ $activity->intervention->name ?? 'Activity' }}</h6>
                    <p>Completed {{ $activity->completed_at ? $activity->completed_at->diffForHumans() : '' }}</p>
                </div>
                @if($activity->effectiveness_rating)
                    <div class="text-end">
                        <span style="font-size: 0.8rem; color: var(--kid-warning);">
                            @for($i = 0; $i < $activity->effectiveness_rating; $i++)⭐@endfor
                        </span>
                    </div>
                @endif
            </div>
        @endforeach
    @endif

@endsection
