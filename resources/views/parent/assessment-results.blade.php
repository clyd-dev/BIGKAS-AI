@extends('layouts.parent')

@section('title', $learner->first_name . ' - Reading Checks')

@php use App\Support\ParentFriendly as PF; @endphp

@section('content')
@php $lv = PF::level($learner->reading_level); @endphp
<div class="pp">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <a href="{{ route('parent.children.profile', $learner) }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Back to {{ $learner->first_name }}</a>
    </div>

    <h4 class="mb-1"><i class="bi bi-clipboard-check me-2"></i>{{ $learner->first_name }}'s reading checks</h4>
    <p class="text-muted">A reading check is when the teacher listens to {{ $learner->first_name }} read a short passage.</p>

    <div class="pp-tone pp-{{ $lv['tone'] }} rounded-3 p-3 mb-4 d-flex align-items-center gap-3">
        <span style="font-size:2rem" aria-hidden="true">{{ $lv['emoji'] }}</span>
        <div>
            <div class="small text-muted">Right now</div>
            <strong class="pp-tone-label">{{ $lv['title'] }}</strong>
            <div class="small">{{ $stats['total_assessments'] ?? 0 }} {{ Str::plural('check', $stats['total_assessments'] ?? 0) }} so far</div>
        </div>
    </div>

    @forelse($assessments as $assessment)
        @php
            $r  = $assessment->result;
            $rl = PF::level($r?->reading_level);
        @endphp
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                    <div>
                        <div class="fw-bold">{{ $assessment->created_at?->format('F j, Y') }}</div>
                        <div class="text-muted">{{ $assessment->material?->title ?? 'Reading passage' }}
                            @if($assessment->material?->language)
                                &middot; {{ ucfirst($assessment->material->language) }}
                            @endif
                        </div>
                    </div>
                    @if($r)
                        <span class="pp-pill pp-{{ $rl['tone'] }}">{{ $rl['emoji'] }} {{ $rl['title'] }}</span>
                    @else
                        <span class="pp-pill pp-none"><i class="bi bi-hourglass-split"></i> Waiting for results</span>
                    @endif
                </div>

                @if($r)
                    <div class="pp-bar mt-3 pp-{{ $rl['tone'] }}" role="img" aria-label="{{ (int) round($r->accuracy_rate) }} out of 100 words correct">
                        <span style="width: {{ min(100, max(0, $r->accuracy_rate)) }}%"></span>
                    </div>
                    <div class="mt-1">{{ PF::accuracySentence($r->accuracy_rate) }}</div>
                    <a href="{{ route('parent.children.assessment-detail', [$learner, $assessment]) }}" class="btn btn-outline-primary btn-sm mt-3">
                        See the full story <i class="bi bi-arrow-right"></i>
                    </a>
                @endif
            </div>
        </div>
    @empty
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-5">
                <div style="font-size:2.5rem" aria-hidden="true">📖</div>
                <h5 class="mt-2">No reading checks yet</h5>
                <p class="text-muted mb-0">They will appear here after the teacher records one.</p>
            </div>
        </div>
    @endforelse

    @if($assessments->hasPages())
        <div class="mt-3">{{ $assessments->links() }}</div>
    @endif
</div>
@endsection
