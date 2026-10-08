@extends('layouts.parent')

@section('title', 'Home')

@php use App\Support\ParentFriendly as PF; @endphp

@section('content')
<div class="pp">
    @php
        $hour = now()->hour;
        $greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
    @endphp
    <h4 class="mb-1">{{ $greeting }}, {{ Str::before(Auth::user()->name, ' ') }}! 👋</h4>
    <p class="text-muted mb-4">Here is how your {{ $learners->count() > 1 ? 'children are' : 'child is' }} doing with reading.</p>

    {{-- Things that need attention --}}
    @if(($stats['unread_messages'] ?? 0) > 0)
        <a href="{{ route('parent.messages.index') }}" class="text-decoration-none">
            <div class="pp-tone pp-none rounded-3 p-3 mb-3 d-flex align-items-center gap-3">
                <i class="bi bi-envelope-fill fs-3"></i>
                <div>
                    <strong>You have {{ $stats['unread_messages'] }} new {{ Str::plural('message', $stats['unread_messages']) }} from school.</strong>
                    <div class="small">Tap to read</div>
                </div>
            </div>
        </a>
    @endif

    {{-- One card per child --}}
    @forelse($learners as $learner)
        @php
            $latest   = $latestResults[$learner->id] ?? null;
            $lv       = PF::level($latest?->reading_level ?? $learner->reading_level);
            $open     = $openByLearner[$learner->id] ?? 0;
            $when     = $latest?->assessment?->created_at;
            $speed    = $latest ? PF::speed($latest->words_per_minute, $learner->grade_level) : null;
        @endphp
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-0">
                <div class="pp-hero pp-{{ $lv['tone'] }} pp-tone d-flex gap-3 align-items-start" style="border-bottom-left-radius:0;border-bottom-right-radius:0;">
                    <div class="pp-emoji" aria-hidden="true">{{ $lv['emoji'] }}</div>
                    <div class="flex-grow-1">
                        <div class="small text-muted">{{ $learner->full_name }} &middot; Grade {{ $learner->grade_level }}</div>
                        <h3 class="pp-tone-label">{{ $lv['title'] }}</h3>
                        @if($latest)
                            <div>{{ PF::accuracySentence($latest->accuracy_rate) }}</div>
                            @if($speed && $speed['tone'] !== 'none')
                                <div>{{ $speed['text'] }}.</div>
                            @endif
                            @if($when)
                                <div class="small text-muted mt-1">Last reading check: {{ $when->format('F j, Y') }}</div>
                            @endif
                        @else
                            <div>{{ $lv['meaning'] }}</div>
                        @endif
                    </div>
                </div>

                <div class="p-3 p-md-4">
                    @if($open > 0)
                        <div class="pp-todo mb-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <div>
                                <strong><i class="bi bi-house-heart me-1"></i>{{ $open }} {{ Str::plural('activity', $open) }} to do at home</strong>
                                <div class="small text-muted">Short activities from the teacher to help {{ $learner->first_name }}.</div>
                            </div>
                            <a href="{{ route('parent.children.interventions', $learner) }}" class="btn btn-warning fw-bold">
                                <i class="bi bi-play-circle-fill"></i> See activities
                            </a>
                        </div>
                    @endif

                    <div class="d-flex flex-wrap gap-2">
                        <a href="{{ route('parent.children.profile', $learner) }}" class="btn btn-primary">
                            <i class="bi bi-emoji-smile"></i> How is {{ $learner->first_name }} doing?
                        </a>
                        <a href="{{ route('parent.children.assessments', $learner) }}" class="btn btn-outline-primary">
                            <i class="bi bi-clipboard-check"></i> Reading checks
                        </a>
                        @if($open === 0)
                            <a href="{{ route('parent.children.interventions', $learner) }}" class="btn btn-outline-secondary">
                                <i class="bi bi-house-heart"></i> Home activities
                            </a>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @empty
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-5">
                <div style="font-size:3rem" aria-hidden="true">👨‍👩‍👧</div>
                <h5 class="mt-2">No child is linked to your account yet</h5>
                <p class="text-muted mb-0">Please ask your child's teacher or the school office to link your account.</p>
            </div>
        </div>
    @endforelse

    {{-- Recent reading checks, in words --}}
    @if($recentAssessments->isNotEmpty())
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white"><i class="bi bi-clock-history me-1"></i> Latest reading checks</div>
            <div class="list-group list-group-flush">
                @foreach($recentAssessments as $assessment)
                    @php
                        $r  = $assessment->result;
                        $rl = PF::level($r?->reading_level);
                    @endphp
                    <a href="{{ route('parent.children.assessment-detail', [$assessment->learner_id, $assessment]) }}"
                       class="list-group-item list-group-item-action py-3">
                        <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap">
                            <div>
                                <strong>{{ $assessment->learner?->first_name }}</strong>
                                read <em>{{ $assessment->material?->title ?? 'a story' }}</em>
                                <div class="small text-muted">{{ $assessment->created_at?->format('F j, Y') }}</div>
                            </div>
                            @if($r)
                                <span class="pp-pill pp-{{ $rl['tone'] }}">{{ $rl['emoji'] }} {{ $rl['short'] }}</span>
                            @else
                                <span class="pp-pill pp-none"><i class="bi bi-hourglass-split"></i> Waiting for results</span>
                            @endif
                        </div>
                    </a>
                @endforeach
            </div>
        </div>
    @endif
</div>
@endsection
