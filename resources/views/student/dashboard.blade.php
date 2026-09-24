@extends('layouts.student')

@section('title', 'Dashboard')

@section('content')

    {{-- Active Assessment Alert --}}
    @if($activeSession)
        <div class="kid-card kid-card-colored kid-card-pink mb-3" style="cursor: pointer;"
             onclick="window.location='{{ route('student.assessment.show', $activeSession) }}'">
            <div class="d-flex align-items-center gap-3">
                <div style="font-size: 2.5rem;">📝</div>
                <div class="flex-grow-1">
                    <h5 class="fw-bold mb-1">Reading Assessment Ready!</h5>
                    <p class="mb-0" style="opacity: 0.9; font-size: 0.9rem;">
                        Your teacher is waiting. Tap here to start reading!
                    </p>
                </div>
                <i class="bi bi-chevron-right" style="font-size: 1.5rem;"></i>
            </div>
        </div>
    @endif

    {{-- Streak Banner --}}
    <div class="streak-banner">
        <div class="streak-icon">🔥</div>
        <div class="streak-info">
            @if($learner->current_streak > 0)
                <h3>{{ $learner->current_streak }}-Day Streak!</h3>
                <p>Keep it up! Your longest streak is {{ $learner->longest_streak }} days.</p>
            @else
                <h3>Start Your Streak!</h3>
                <p>Complete an activity today to begin your streak!</p>
            @endif
        </div>
    </div>

    {{-- Welcome --}}
    <h4 class="fw-bold mb-3">
        Hi, {{ $learner->first_name }}! 👋
    </h4>

    {{-- Quick Stats Row --}}
    <div class="row g-2 mb-3">
        <div class="col-4">
            <div class="kid-card text-center py-3">
                <div style="font-size: 1.5rem; font-weight: 800; color: var(--kid-primary);">
                    {{ $stats['total_assessments'] }}
                </div>
                <div style="font-size: 0.7rem; color: var(--kid-text-light); font-weight: 600;">Assessments</div>
            </div>
        </div>
        <div class="col-4">
            <div class="kid-card text-center py-3">
                <div style="font-size: 1.5rem; font-weight: 800; color: var(--kid-success);">
                    {{ $stats['average_accuracy'] }}%
                </div>
                <div style="font-size: 0.7rem; color: var(--kid-text-light); font-weight: 600;">Accuracy</div>
            </div>
        </div>
        <div class="col-4">
            <div class="kid-card text-center py-3">
                <div style="font-size: 1.5rem; font-weight: 800; color: var(--kid-warning);">
                    {{ $totalBadges }}/{{ $allBadgesCount }}
                </div>
                <div style="font-size: 0.7rem; color: var(--kid-text-light); font-weight: 600;">Badges</div>
            </div>
        </div>
    </div>

    {{-- Action Tiles --}}
    <h5 class="fw-bold mb-2">What do you want to do?</h5>
    <div class="row g-3 mb-4">
        <div class="col-6">
            <a href="{{ route('student.flashcards') }}" class="action-tile">
                <div class="tile-icon" style="background: var(--kid-primary-light); color: var(--kid-primary);">
                    🃏
                </div>
                <h6>Flash Cards</h6>
                <p>Practice reading words</p>
            </a>
        </div>
        <div class="col-6">
            <a href="{{ route('student.guided-reading') }}" class="action-tile">
                <div class="tile-icon" style="background: #FFF0E6; color: var(--kid-orange);">
                    📚
                </div>
                <h6>Guided Reading</h6>
                <p>Read stories at your level</p>
            </a>
        </div>
        <div class="col-6">
            <a href="{{ route('student.activities') }}" class="action-tile">
                <div class="tile-icon" style="background: #E6FFF5; color: var(--kid-success);">
                    🎯
                </div>
                <h6>My Activities</h6>
                <p>Teacher-assigned tasks</p>
            </a>
        </div>
        <div class="col-6">
            <a href="{{ route('student.leaderboard') }}" class="action-tile">
                <div class="tile-icon" style="background: #FFF5E6; color: var(--kid-warning);">
                    🏆
                </div>
                <h6>Leaderboard</h6>
                <p>See class rankings</p>
            </a>
        </div>
    </div>

    {{-- Pending Activities --}}
    @if($pendingActivities->count())
        <h5 class="fw-bold mb-2">Activities For You</h5>
        @foreach($pendingActivities as $activity)
            <a href="{{ route('student.activities.show', $activity) }}" class="activity-card">
                <div class="activity-icon" style="background: var(--kid-primary-light); color: var(--kid-primary);">
                    📋
                </div>
                <div class="activity-info">
                    <h6>{{ $activity->intervention->name ?? 'Activity' }}</h6>
                    <p>{{ Str::limit($activity->intervention->description ?? '', 60) }}</p>
                </div>
                <i class="bi bi-chevron-right text-muted"></i>
            </a>
        @endforeach
    @endif

    {{-- Recent Badges --}}
    @if($recentBadges->count())
        <div class="d-flex align-items-center justify-content-between mb-2 mt-3">
            <h5 class="fw-bold mb-0">Recent Badges</h5>
            <a href="{{ route('student.badges') }}" class="text-decoration-none fw-bold" style="font-size: 0.85rem; color: var(--kid-primary);">
                See All
            </a>
        </div>
        <div class="d-flex gap-2 overflow-auto pb-2" style="scrollbar-width: none;">
            @foreach($recentBadges as $badge)
                <div class="badge-item earned" style="min-width: 90px;">
                    <span class="badge-icon">{{ $badge->icon }}</span>
                    <div class="badge-name">{{ $badge->name }}</div>
                </div>
            @endforeach
        </div>
    @endif

@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    let checkInterval = setInterval(checkPendingAssessment, 3000);

    function checkPendingAssessment() {
        fetch('{{ route("student.assessment.pending") }}', {
            headers: { 'Accept': 'application/json' }
        })
        .then(res => res.json())
        .then(data => {
            if (data.has_pending) {
                clearInterval(checkInterval); // Stop polling once found
                if (confirm('Your teacher has an assessment ready for you: ' + data.material_title + '\n\nClick OK to start!')) {
                    window.location.href = '/student/assessment/' + data.assessment_id + '/read';
                }
            }
        });
    }
});
</script>
@endpush
