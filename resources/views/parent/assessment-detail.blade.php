@extends('layouts.parent')

@section('title', $learner->first_name . ' - Reading Check')

@php use App\Support\ParentFriendly as PF; @endphp

@section('content')
@php
    $name = $learner->first_name;
@endphp
<div class="pp">
    <div class="mb-3">
        <a href="{{ route('parent.children.assessments', $learner) }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> All reading checks
        </a>
    </div>

    @if($result)
        @php
            $lv      = PF::level($result->reading_level);
            $speed   = PF::speed($result->words_per_minute, $learner->grade_level);
            $trend   = PF::trend($comparison);
            $tips    = PF::homeTips($result->primary_weakness);
            $mistakes = PF::mistakeTypes();
            $weaknessLabels = config('bigkas.weakness_categories', []);
            $weak = $result->primary_weakness !== null ? ($weaknessLabels[$result->primary_weakness] ?? null) : null;
        @endphp

        {{-- Headline --}}
        <div class="card border-0 shadow-sm mb-4">
            <div class="pp-hero pp-{{ $lv['tone'] }} pp-tone d-flex gap-3 align-items-start">
                <div class="pp-emoji" aria-hidden="true">{{ $lv['emoji'] }}</div>
                <div>
                    <div class="small text-muted">{{ $assessment->created_at?->format('F j, Y') }}</div>
                    <h3 class="pp-tone-label">{{ $name }}: {{ strtolower($lv['title']) }}</h3>
                    <p class="mb-2">
                        {{ $name }} read <em>"{{ $assessment->material?->title ?? 'a short passage' }}"</em>.
                        {{ PF::accuracySentence($result->accuracy_rate) }}
                    </p>
                    <p class="mb-0">{{ $lv['meaning'] }}</p>
                </div>
            </div>
        </div>

        {{-- Simple facts --}}
        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <div class="card border-0 shadow-sm h-100"><div class="card-body">
                    <h6 class="text-muted"><i class="bi bi-bullseye me-1"></i> Words read correctly</h6>
                    <div class="pp-bar mt-2 pp-{{ $result->accuracy_rate >= 90 ? 'good' : ($result->accuracy_rate >= 80 ? 'ok' : 'help') }}" role="img"
                         aria-label="{{ (int) round($result->accuracy_rate) }} out of 100 words correct">
                        <span style="width: {{ min(100, max(0, $result->accuracy_rate)) }}%"></span>
                    </div>
                    <div class="mt-2 fw-bold">{{ (int) round($result->accuracy_rate) }} out of 100</div>
                </div></div>
            </div>
            <div class="col-md-6">
                <div class="card border-0 shadow-sm h-100"><div class="card-body">
                    <h6 class="text-muted"><i class="bi bi-speedometer2 me-1"></i> Reading speed</h6>
                    <div class="fw-bold fs-5">{{ $speed['text'] }}.</div>
                    <div class="small text-muted">About {{ (int) round($result->words_per_minute) }} words in a minute.</div>
                </div></div>
            </div>
        </div>

        {{-- Compared with last time --}}
        <div class="pp-tone pp-{{ $trend['tone'] }} rounded-3 p-3 mb-4 d-flex gap-3 align-items-center">
            <i class="bi {{ $trend['icon'] }} fs-2 pp-tone-label"></i>
            <div>
                <strong>Compared with the check before</strong>
                <div>{{ $trend['text'] }}</div>
            </div>
        </div>

        {{-- Mistakes in plain words --}}
        @if($errorBreakdown && ($errorBreakdown['total'] ?? 0) > 0)
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white"><i class="bi bi-chat-square-text me-1"></i> What happened while {{ $name }} read</div>
                <div class="card-body py-2">
                    <p class="text-muted small mt-2 mb-1">Every child makes some of these. They show the teacher where to help.</p>
                    @foreach($mistakes as $key => $meta)
                        @php $count = (int) ($errorBreakdown[$key] ?? 0); @endphp
                        @if($count > 0)
                            <div class="pp-skill pp-{{ $key === 'self_corrections' ? 'good' : 'none' }}">
                                <div class="pp-skill-icon"><i class="bi {{ $meta['icon'] }}"></i></div>
                                <div class="flex-grow-1">
                                    <div class="fw-bold">{{ $meta['label'] }}</div>
                                    <div class="small text-muted">{{ $meta['help'] }}</div>
                                </div>
                                <div class="fs-4 fw-bold">{{ $count }}<span class="small text-muted fw-normal"> {{ Str::plural('time', $count) }}</span></div>
                            </div>
                        @endif
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Help at home --}}
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white"><i class="bi bi-house-heart me-1"></i> How you can help at home</div>
            <div class="card-body">
                @if($weak && ($weak['code'] ?? '') !== 'INDEPENDENT')
                    <p class="mb-2">The teacher sees that {{ $name }} can grow most in <strong>{{ strtolower($weak['name']) }}</strong>. Simple things to try:</p>
                @else
                    <p class="mb-2">Keep the reading habit going with these simple ideas:</p>
                @endif
                @foreach($tips as $tip)
                    <div class="pp-tip"><i class="bi bi-check-circle-fill"></i><div>{{ $tip }}</div></div>
                @endforeach
                <a href="{{ route('parent.children.interventions', $learner) }}" class="btn btn-warning fw-bold mt-2">
                    <i class="bi bi-play-circle-fill"></i> See activities from the teacher
                </a>
            </div>
        </div>

        {{-- Extra details --}}
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <details>
                    <summary><i class="bi bi-info-circle me-2"></i> More details about this check (optional)</summary>
                    <table class="table table-borderless table-sm mt-3 mb-0">
                        <tr><th class="text-muted" style="width:170px">Passage</th><td>{{ $assessment->material?->title ?? 'N/A' }}</td></tr>
                        <tr><th class="text-muted">Language</th><td>{{ ucfirst($assessment->material?->language ?? '-') }}</td></tr>
                        <tr><th class="text-muted">Grade of passage</th><td>Grade {{ $assessment->material?->grade_level ?? '-' }}</td></tr>
                        <tr><th class="text-muted">Checked by</th><td>{{ $assessment->assessor?->name ?? '-' }}</td></tr>
                        <tr><th class="text-muted">Date and time</th><td>{{ $assessment->created_at?->format('F j, Y g:i A') }}</td></tr>
                        <tr><th class="text-muted">Words correct</th><td>{{ number_format($result->accuracy_rate ?? 0, 1) }}%</td></tr>
                        <tr><th class="text-muted">Words per minute</th><td>{{ (int) round($result->words_per_minute ?? 0) }}</td></tr>
                        <tr><th class="text-muted">Mistakes in total</th><td>{{ $errorBreakdown['total'] ?? 0 }}</td></tr>
                    </table>
                </details>
            </div>
        </div>
    @else
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-5">
                <div style="font-size:2.5rem" aria-hidden="true">⏳</div>
                <h5 class="mt-2">Results are not ready yet</h5>
                <p class="text-muted mb-0">The teacher is still reviewing this reading check. It will show up here soon.</p>
            </div>
        </div>
    @endif
</div>
@endsection
