@extends('layouts.parent')

@section('title', $learner->first_name . ' - Home Activities')

@section('content')
@php
    $name     = $learner->first_name;
    $total    = $interventionStats['totalAssigned'] ?? 0;
    $done     = $interventionStats['completed'] ?? 0;
    $todo     = ($interventionStats['pending'] ?? 0);
    $started  = ($interventionStats['inProgress'] ?? 0);
    $percent  = $total > 0 ? (int) round($done / $total * 100) : 0;
    $faces    = [2 => ['😟', 'Hard'], 4 => ['😕', 'A bit hard'], 6 => ['🙂', 'Okay'], 8 => ['😀', 'Good'], 10 => ['🤩', 'Great!']];
    $weaknessLabels = config('bigkas.weakness_categories', []);
@endphp
<div class="pp">
    <div class="mb-3">
        <a href="{{ route('parent.children.profile', $learner) }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> Back to {{ $name }}
        </a>
    </div>

    <h4 class="mb-1"><i class="bi bi-house-heart me-2"></i>{{ $name }}'s home activities</h4>
    <p class="text-muted">Short activities from the teacher. Do them together, a few minutes at a time.</p>

    {{-- Progress in words --}}
    @if($total > 0)
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <div class="d-flex justify-content-between flex-wrap gap-1 mb-2">
                    <strong>{{ $done }} of {{ $total }} done</strong>
                    <span class="text-muted">
                        @if($todo > 0) {{ $todo }} to do @endif
                        @if($todo > 0 && $started > 0) &middot; @endif
                        @if($started > 0) {{ $started }} started @endif
                    </span>
                </div>
                <div class="pp-bar pp-good" role="img" aria-label="{{ $done }} of {{ $total }} activities done">
                    <span style="width: {{ $percent }}%"></span>
                </div>
                @if($done === $total)
                    <div class="mt-2 pp-tone-label" style="color:#1e7e46">🎉 All activities done. Great job!</div>
                @endif
            </div>
        </div>
    @endif

    @forelse($logs as $log)
        @php
            $isFailed = $errors->any() && (int) old('log_id') === $log->id;
            $selected = $isFailed ? (int) old('effectiveness_rating', 0) : 0;
        @endphp
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                    <h5 class="mb-1">{{ $log->intervention?->name ?? 'Activity' }}</h5>
                    @if($log->status === 'completed')
                        <span class="pp-pill pp-good"><i class="bi bi-check-circle-fill"></i> Done</span>
                    @elseif($log->status === 'in_progress')
                        <span class="pp-pill pp-ok"><i class="bi bi-play-circle-fill"></i> Started</span>
                    @elseif($log->status === 'skipped')
                        <span class="pp-pill pp-none"><i class="bi bi-skip-forward-fill"></i> Skipped</span>
                    @else
                        <span class="pp-pill pp-help"><i class="bi bi-circle"></i> To do</span>
                    @endif
                </div>

                @if($log->intervention?->description)
                    <p class="mb-2">{{ $log->intervention->description }}</p>
                @endif

                <div class="d-flex gap-2 flex-wrap small text-muted mb-2">
                    @if($log->intervention?->duration_minutes)
                        <span><i class="bi bi-clock me-1"></i>About {{ $log->intervention->duration_minutes }} minutes</span>
                    @endif
                    @if($log->intervention?->target_weakness !== null && isset($weaknessLabels[$log->intervention->target_weakness]))
                        <span><i class="bi bi-bullseye me-1"></i>Helps with {{ strtolower($weaknessLabels[$log->intervention->target_weakness]['name']) }}</span>
                    @endif
                    <span><i class="bi bi-person me-1"></i>From {{ $log->assigner?->name ?? 'the teacher' }}</span>
                </div>

                @if($log->intervention?->instructions)
                    <details class="mb-2">
                        <summary><i class="bi bi-list-check me-2"></i> How to do this activity</summary>
                        <div class="pp-todo mt-2">{!! nl2br(e($log->intervention->instructions)) !!}</div>
                    </details>
                @endif

                @if($log->notes)
                    <div class="small text-muted mb-2"><i class="bi bi-chat-left-text me-1"></i>{{ $log->notes }}</div>
                @endif

                @if($log->status === 'pending')
                    <form method="POST" action="{{ route('parent.children.intervention-update', [$learner, $log]) }}">
                        @csrf
                        <input type="hidden" name="action" value="start">
                        <button type="submit" class="btn btn-primary btn-block-sm"><i class="bi bi-play-fill"></i> We are starting this</button>
                    </form>
                @elseif($log->status === 'in_progress')
                    <button type="button" class="btn btn-success btn-block-sm" data-bs-toggle="modal" data-bs-target="#completeModal-{{ $log->id }}">
                        <i class="bi bi-check-lg"></i> We finished this
                    </button>

                    <div class="modal fade" id="completeModal-{{ $log->id }}" tabindex="-1" aria-labelledby="completeTitle-{{ $log->id }}" aria-hidden="true">
                        <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content pp" style="border-radius:16px">
                                <form method="POST" action="{{ route('parent.children.intervention-update', [$learner, $log]) }}">
                                    @csrf
                                    <input type="hidden" name="action" value="complete">
                                    <input type="hidden" name="log_id" value="{{ $log->id }}">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="completeTitle-{{ $log->id }}">Great job! How did it go?</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        @if($isFailed)
                                            <div class="alert alert-danger small py-2">
                                                <ul class="mb-0 ps-3">
                                                    @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                                                </ul>
                                            </div>
                                        @endif
                                        <div class="mb-3">
                                            <div class="fw-bold mb-2">How did {{ $name }} feel about it?</div>
                                            <div class="pp-faces" role="radiogroup">
                                                @foreach($faces as $value => [$emoji, $word])
                                                    <input type="radio" name="effectiveness_rating" value="{{ $value }}" id="face-{{ $log->id }}-{{ $value }}" {{ $selected === $value ? 'checked' : '' }}>
                                                    <label for="face-{{ $log->id }}-{{ $value }}"><span class="face">{{ $emoji }}</span><span class="small">{{ $word }}</span></label>
                                                @endforeach
                                            </div>
                                        </div>
                                        <div class="mb-1">
                                            <label class="form-label fw-bold" for="notes-{{ $log->id }}">Anything the teacher should know? (optional)</label>
                                            <textarea id="notes-{{ $log->id }}" name="notes" class="form-control" rows="3" maxlength="2000" placeholder="For example: she liked it, or he found the long words hard.">{{ $isFailed ? old('notes') : '' }}</textarea>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Not yet</button>
                                        <button type="submit" class="btn btn-success"><i class="bi bi-check-lg"></i> Save</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                @elseif($log->status === 'completed')
                    <div class="small text-muted">
                        Finished {{ $log->completed_at?->format('F j') }}
                        @if($log->effectiveness_rating)
                            @php $fk = collect($faces)->keys()->sortBy(fn ($k) => abs($k - $log->effectiveness_rating))->first(); @endphp
                            &middot; {{ $faces[$fk][0] }} {{ $faces[$fk][1] }}
                        @endif
                    </div>
                @endif
            </div>
        </div>
    @empty
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-5">
                <div style="font-size:2.5rem" aria-hidden="true">🏡</div>
                <h5 class="mt-2">No home activities yet</h5>
                <p class="text-muted mb-0">When the teacher assigns activities, they will appear here.</p>
            </div>
        </div>
    @endforelse

    @if($logs->hasPages())
        <div class="mt-3">{{ $logs->links() }}</div>
    @endif
</div>

{{-- Reopen the complete modal when its submission failed validation --}}
@if($errors->any() && old('log_id'))
    @push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var el = document.getElementById('completeModal-{{ (int) old('log_id') }}');
            if (el) { new bootstrap.Modal(el).show(); }
        });
    </script>
    @endpush
@endif
@endsection
