@extends('layouts.student')

@section('title', 'Leaderboard')

@section('content')

    <h4 class="fw-bold mb-3">🏆 Class Leaderboard</h4>

    {{-- My Rank Card --}}
    <div class="kid-card kid-card-colored kid-card-purple mb-3">
        <div class="d-flex align-items-center gap-3">
            <div style="font-size: 2.5rem;">
                @if($myRank === 1)
                    🥇
                @elseif($myRank === 2)
                    🥈
                @elseif($myRank === 3)
                    🥉
                @else
                    🎯
                @endif
            </div>
            <div class="flex-grow-1">
                <h5 class="fw-bold mb-1">You're #{{ $myRank }}!</h5>
                <p class="mb-0" style="opacity: 0.9; font-size: 0.85rem;">
                    {{ number_format($learner->total_xp) }} XP &middot;
                    🔥 {{ $learner->current_streak }}-day streak &middot;
                    🏅 {{ $learner->getBadgeCount() }} badges
                </p>
            </div>
        </div>
    </div>

    {{-- Leaderboard List --}}
    @if($classmates->count() === 0)
        <div class="kid-card text-center py-4">
            <div style="font-size: 3rem;" class="mb-2">🤷</div>
            <h5 class="fw-bold">No Classmates Yet</h5>
            <p class="text-muted mb-0">The leaderboard will appear once your classmates join!</p>
        </div>
    @else
        <div class="mb-3">
            @foreach($classmates as $index => $mate)
                @php $rank = $index + 1; @endphp
                <div class="leaderboard-item {{ $mate['is_me'] ? 'is-me' : '' }} {{ $rank <= 3 ? 'rank-' . $rank : '' }}">
                    <div class="leaderboard-rank">
                        @if($rank === 1)
                            🥇
                        @elseif($rank === 2)
                            🥈
                        @elseif($rank === 3)
                            🥉
                        @else
                            {{ $rank }}
                        @endif
                    </div>

                    {{-- Avatar --}}
                    <div class="student-avatar" style="width: 38px; height: 38px; font-size: 0.9rem;
                        background: {{ $mate['is_me'] ? 'var(--kid-primary)' : '#E8E6FF' }};
                        color: {{ $mate['is_me'] ? '#fff' : 'var(--kid-primary)' }};">
                        {{ strtoupper(substr($mate['name'], 0, 1)) }}
                    </div>

                    <div class="leaderboard-name">
                        {{ $mate['name'] }}
                        @if($mate['is_me'])
                            <span style="font-size: 0.7rem; color: var(--kid-primary); font-weight: 700;">(You)</span>
                        @endif
                        <div style="font-size: 0.7rem; color: var(--kid-text-light);">
                            🔥 {{ $mate['streak'] }} &middot; 🏅 {{ $mate['badges'] }}
                        </div>
                    </div>

                    <div class="leaderboard-xp">
                        {{ number_format($mate['xp']) }}
                        <span style="font-size: 0.7rem; font-weight: 600;">XP</span>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Badges Link --}}
    <div class="text-center mt-2 mb-3">
        <a href="{{ route('student.badges') }}" class="btn btn-kid btn-kid-primary">
            🏅 View My Badges
        </a>
    </div>

@endsection
