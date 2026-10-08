@extends('layouts.parent')

@section('title', $learner->first_name . ' - Reading Progress')

@php use App\Support\ParentFriendly as PF; @endphp

@section('content')
@php
    $name   = $learner->first_name;
    $lv     = PF::level($latestResult?->reading_level ?? $learner->reading_level);
    $speed  = $latestResult ? PF::speed($latestResult->words_per_minute, $learner->grade_level) : null;
    $trend  = PF::trend($comparison);
    $labels = PF::skillLabels();
    $tips   = PF::homeTips($weaknessId);
    $hasAnyResult = $latestResult !== null;
@endphp
<div class="pp">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <a href="{{ route('parent.dashboard') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Home</a>
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('parent.children.assessments', $learner) }}" class="btn btn-outline-primary btn-sm"><i class="bi bi-clipboard-check"></i> Reading checks</a>
            <a href="{{ route('parent.children.interventions', $learner) }}" class="btn btn-outline-primary btn-sm"><i class="bi bi-house-heart"></i> Home activities</a>
            <a href="{{ route('reports.pdf', $learner) }}" class="btn btn-outline-danger btn-sm"><i class="bi bi-file-earmark-pdf"></i> Download report</a>
        </div>
    </div>

    {{-- 1. The headline: how is my child doing? --}}
    <div class="card border-0 shadow-sm mb-4">
        <div class="pp-hero pp-{{ $lv['tone'] }} pp-tone d-flex gap-3 align-items-start">
            <div class="pp-emoji" aria-hidden="true">{{ $lv['emoji'] }}</div>
            <div>
                <div class="small text-muted">{{ $learner->full_name }} &middot; Grade {{ $learner->grade_level }}</div>
                <h3 class="pp-tone-label">{{ $name }}: {{ strtolower($lv['title']) }}</h3>
                <p class="mb-0">{{ $lv['meaning'] }}</p>
                @if($lastAssessedAt)
                    <div class="small text-muted mt-2"><i class="bi bi-calendar-check me-1"></i>Last reading check: {{ $lastAssessedAt->format('F j, Y') }}</div>
                @endif
            </div>
        </div>
    </div>

    @if($hasAnyResult)
        {{-- 2. Since last time --}}
        <div class="pp-tone pp-{{ $trend['tone'] }} rounded-3 p-3 mb-4 d-flex gap-3 align-items-center">
            <i class="bi {{ $trend['icon'] }} fs-2 pp-tone-label"></i>
            <div>
                <strong>Compared with last time</strong>
                <div>{{ $trend['text'] }}</div>
            </div>
        </div>

        {{-- 3. Reading in simple words --}}
        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <h6 class="text-muted mb-2"><i class="bi bi-bullseye me-1"></i> Reading words correctly</h6>
                        <div class="fs-5 fw-bold">{{ PF::accuracySentence($latestResult->accuracy_rate) }}</div>
                        <div class="pp-bar mt-2 pp-{{ $latestResult->accuracy_rate >= 90 ? 'good' : ($latestResult->accuracy_rate >= 80 ? 'ok' : 'help') }}" role="img"
                             aria-label="{{ (int) round($latestResult->accuracy_rate) }} out of 100 words correct">
                            <span style="width: {{ min(100, max(0, $latestResult->accuracy_rate)) }}%"></span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <h6 class="text-muted mb-2"><i class="bi bi-speedometer2 me-1"></i> Reading speed</h6>
                        <div class="fs-5 fw-bold">{{ $speed['text'] }}.</div>
                        <div class="small text-muted mt-1">
                            About {{ (int) round($latestResult->words_per_minute) }} words in one minute.
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- 4. Skills as stars --}}
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white"><i class="bi bi-stars me-1"></i> What {{ $name }} is good at, and what needs practice</div>
            <div class="card-body py-2">
                @foreach($labels as $key => $meta)
                    @php $s = PF::skill($skillScores[$key] ?? null); @endphp
                    <div class="pp-skill pp-{{ $s['tone'] }}">
                        <div class="pp-skill-icon"><i class="bi {{ $meta['icon'] }}"></i></div>
                        <div class="flex-grow-1">
                            <div class="fw-bold">{{ $meta['label'] }}</div>
                            <div class="small text-muted">{{ $meta['hint'] }}</div>
                        </div>
                        <div class="text-end">
                            <div class="pp-stars" aria-hidden="true">
                                @for($i = 1; $i <= 3; $i++)<span class="{{ $i <= $s['stars'] ? '' : 'off' }}">★</span>@endfor
                            </div>
                            <div class="small pp-tone-label" style="color:var(--tone)">{{ $s['word'] }}</div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- 5. What I can do at home --}}
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white"><i class="bi bi-house-heart me-1"></i> How you can help at home</div>
            <div class="card-body">
                @if($weaknessInfo && ($weaknessInfo['code'] ?? '') !== 'INDEPENDENT')
                    <p class="mb-2">The teacher sees that {{ $name }} can grow most in
                        <strong>{{ strtolower($weaknessInfo['name']) }}</strong>. Simple things to try:</p>
                @else
                    <p class="mb-2">Keep the reading habit going with these simple ideas:</p>
                @endif
                @foreach($tips as $tip)
                    <div class="pp-tip"><i class="bi bi-check-circle-fill"></i><div>{{ $tip }}</div></div>
                @endforeach

                @if($suggestedActivities->isNotEmpty())
                    <hr>
                    <div class="fw-bold mb-2">From {{ $name }}'s teacher:</div>
                    @foreach($suggestedActivities as $log)
                        <div class="pp-todo mb-2">{{ $log->intervention?->name ?? 'Activity' }}
                            @if($log->intervention?->duration_minutes)
                                <span class="text-muted">&middot; about {{ $log->intervention->duration_minutes }} minutes</span>
                            @endif
                        </div>
                    @endforeach
                    <a href="{{ route('parent.children.interventions', $learner) }}" class="btn btn-warning fw-bold mt-2">
                        <i class="bi bi-play-circle-fill"></i> Open home activities
                    </a>
                @endif
            </div>
        </div>

        {{-- 6. Recent reading checks as a simple list --}}
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span><i class="bi bi-clock-history me-1"></i> Recent reading checks</span>
                <a href="{{ route('parent.children.assessments', $learner) }}" class="btn btn-outline-primary btn-sm">See all</a>
            </div>
            <div class="card-body">
                @foreach($checkups as $r)
                    @php $cl = PF::level($r->reading_level); @endphp
                    <div class="pp-checkup pp-{{ $cl['tone'] }}">
                        <div class="d-flex justify-content-between flex-wrap gap-1">
                            <strong>{{ $r->assessment?->created_at?->format('F j, Y') }}</strong>
                            <span class="pp-pill pp-{{ $cl['tone'] }}">{{ $cl['emoji'] }} {{ $cl['short'] }}</span>
                        </div>
                        <div class="small text-muted">{{ $r->assessment?->material?->title ?? 'Reading passage' }}</div>
                        <div class="pp-bar mt-2 pp-{{ $cl['tone'] }}" role="img" aria-label="{{ (int) round($r->accuracy_rate) }} out of 100 words correct">
                            <span style="width: {{ min(100, max(0, $r->accuracy_rate)) }}%"></span>
                        </div>
                        <div class="small mt-1">{{ PF::accuracySentence($r->accuracy_rate) }}</div>
                    </div>
                @endforeach
            </div>
        </div>
    @else
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body text-center py-4">
                <div style="font-size:2.5rem" aria-hidden="true">📖</div>
                <h5 class="mt-2">No reading check yet</h5>
                <p class="text-muted mb-0">When the teacher records {{ $name }}'s reading, you will see a simple summary here.</p>
            </div>
        </div>
    @endif

    {{-- 7. Practice + badges --}}
    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-controller me-1"></i> Practice at school and home</span>
                    <a href="{{ route('parent.children.practice', $learner) }}" class="btn btn-outline-primary btn-sm">See all</a>
                </div>
                <div class="list-group list-group-flush">
                    @forelse($recentPractice as $session)
                        <div class="list-group-item d-flex justify-content-between align-items-center">
                            <div>
                                <strong>{{ $session->getTypeName() }}</strong>
                                <div class="small text-muted">{{ $session->created_at?->diffForHumans() }}</div>
                            </div>
                            @if($session->score !== null)
                                @php $ps = PF::skill($session->score); @endphp
                                <span class="pp-pill pp-{{ $ps['tone'] }}">{{ $ps['word'] }}</span>
                            @endif
                        </div>
                    @empty
                        <div class="list-group-item text-center text-muted py-4">No practice yet. It will show up here.</div>
                    @endforelse
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white"><i class="bi bi-award me-1"></i> Rewards {{ $name }} has earned</div>
                <div class="card-body">
                    <div class="mb-2 text-muted">
                        ⭐ {{ number_format($learner->total_xp ?? 0) }} points
                        &middot; 🔥 {{ $learner->current_streak ?? 0 }} {{ Str::plural('day', $learner->current_streak ?? 0) }} in a row
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        @forelse($badges as $badge)
                            <span class="pp-pill pp-ok" title="{{ $badge->description }}">{{ $badge->icon ?: '🏅' }} {{ $badge->name }}</span>
                        @empty
                            <span class="text-muted">No badges yet. They are earned through practice.</span>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- 8. Numbers and charts, for parents who want them --}}
    @if($hasAnyResult)
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <details id="techDetails">
                    <summary><i class="bi bi-graph-up me-2"></i> Show the detailed numbers and charts (optional)</summary>
                    <div class="pt-3">
                        <div class="row g-3 mb-3 text-center">
                            <div class="col-6 col-md-3"><div class="fs-4 fw-bold">{{ number_format($stats['avg_accuracy'] ?? 0, 1) }}%</div><div class="small text-muted">Average words correct</div></div>
                            <div class="col-6 col-md-3"><div class="fs-4 fw-bold">{{ round($stats['avg_wpm'] ?? 0) }}</div><div class="small text-muted">Average words per minute</div></div>
                            <div class="col-6 col-md-3"><div class="fs-4 fw-bold">{{ $stats['total_assessments'] ?? 0 }}</div><div class="small text-muted">Reading checks so far</div></div>
                            <div class="col-6 col-md-3"><div class="fs-4 fw-bold">{{ ucfirst($learner->mother_tongue ?? 'N/A') }}</div><div class="small text-muted">Home language</div></div>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="small fw-bold mb-1">Words read correctly over time (%)</div>
                                <canvas id="accuracyTrend" height="200"></canvas>
                            </div>
                            <div class="col-md-6">
                                <div class="small fw-bold mb-1">Words read per minute over time</div>
                                <canvas id="wpmTrend" height="200"></canvas>
                            </div>
                        </div>
                        <p class="small text-muted mt-3 mb-0">A line going up means your child is improving.</p>
                    </div>
                </details>
            </div>
        </div>
    @endif
</div>

@if($hasAnyResult)
@push('scripts')
<script>
    // Draw charts only when the optional section is opened (hidden canvases measure 0px).
    (function () {
        var drawn = false;
        var d = document.getElementById('techDetails');
        if (!d) return;
        // Chart.js is ~200KB, so only fetch it when the parent opens this section.
        function loadChartJs(cb) {
            if (window.Chart) return cb();
            var s = document.createElement('script');
            s.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js';
            s.onload = cb;
            document.head.appendChild(s);
        }
        d.addEventListener('toggle', function () {
            if (!d.open || drawn) return;
            drawn = true;
            loadChartJs(drawCharts);
        });
        function drawCharts() {
            var dates = {!! json_encode($progressDates ?? []) !!};
            new Chart(document.getElementById('accuracyTrend'), {
                type: 'line',
                data: { labels: dates, datasets: [{ label: 'Words correct %', data: {!! json_encode($progressAccuracy ?? []) !!}, borderColor: '#0d6efd', backgroundColor: 'rgba(13,110,253,0.1)', fill: true, tension: 0.3 }] },
                options: { responsive: true, scales: { y: { beginAtZero: true, max: 100 } } }
            });
            new Chart(document.getElementById('wpmTrend'), {
                type: 'line',
                data: { labels: dates, datasets: [{ label: 'Words per minute', data: {!! json_encode($progressWpm ?? []) !!}, borderColor: '#198754', backgroundColor: 'rgba(25,135,84,0.1)', fill: true, tension: 0.3 }] },
                options: { responsive: true, scales: { y: { beginAtZero: true } } }
            });
        }
    })();
</script>
@endpush
@endif
@endsection
