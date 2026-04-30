@extends('layouts.student')

@section('title', 'My Badges')

@section('content')

    <h4 class="fw-bold mb-1">🏅 My Badges</h4>
    <p class="text-muted mb-3" style="font-size: 0.85rem;">
        You've earned <strong style="color: var(--kid-primary);">{{ count($earnedIds) }}</strong> out of
        <strong>{{ $badgesByCategory->flatten()->count() }}</strong> badges. Keep going!
    </p>

    {{-- Overall Progress --}}
    @php
        $totalBadges = $badgesByCategory->flatten()->count();
        $earnedCount = count($earnedIds);
        $pct = $totalBadges > 0 ? round(($earnedCount / $totalBadges) * 100) : 0;
    @endphp
    <div class="kid-card mb-4">
        <div class="d-flex align-items-center gap-3">
            <div class="progress-ring">
                <svg width="80" height="80" viewBox="0 0 80 80">
                    <circle cx="40" cy="40" r="34" fill="none" stroke="#E8E6FF" stroke-width="8"/>
                    <circle cx="40" cy="40" r="34" fill="none" stroke="var(--kid-primary)" stroke-width="8"
                            stroke-dasharray="{{ 2 * 3.14159 * 34 }}"
                            stroke-dashoffset="{{ 2 * 3.14159 * 34 * (1 - $pct / 100) }}"
                            stroke-linecap="round"/>
                </svg>
                <span class="progress-value">{{ $pct }}%</span>
            </div>
            <div>
                <h6 class="fw-bold mb-1">Badge Collection</h6>
                <p class="text-muted mb-0" style="font-size: 0.8rem;">
                    {{ $earnedCount }} earned &middot; {{ $totalBadges - $earnedCount }} remaining
                </p>
            </div>
        </div>
    </div>

    {{-- Badges by Category --}}
    @foreach($badgesByCategory as $category => $badges)
        <h6 class="fw-bold text-uppercase mb-2" style="font-size: 0.75rem; color: var(--kid-text-light); letter-spacing: 0.5px;">
            {{ ucfirst($category) }}
        </h6>
        <div class="badge-grid mb-4">
            @foreach($badges as $badge)
                @php $isEarned = in_array($badge->id, $earnedIds); @endphp
                <div class="badge-item {{ $isEarned ? 'earned' : 'locked' }}">
                    <span class="badge-icon">{{ $badge->icon }}</span>
                    <div class="badge-name">{{ $badge->name }}</div>
                    @if($isEarned)
                        <div style="font-size: 0.6rem; color: var(--kid-success); font-weight: 700; margin-top: 2px;">
                            Earned!
                        </div>
                    @else
                        <div style="font-size: 0.6rem; color: var(--kid-text-light); margin-top: 2px;">
                            {{ $badge->description }}
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endforeach

    {{-- Leaderboard Link --}}
    <div class="text-center mt-2 mb-3">
        <a href="{{ route('student.leaderboard') }}" class="btn btn-kid btn-kid-warning">
            🏆 View Leaderboard
        </a>
    </div>

@endsection
