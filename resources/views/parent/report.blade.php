@extends('layouts.parent')

@section('title', $learner->first_name . ' - Report')

@php use App\Support\ParentFriendly as PF; @endphp

@section('content')
@php
    $name  = $learner->first_name;
    $lv    = PF::level($latestResult?->reading_level ?? $learner->reading_level);
    $speed = $latestResult ? PF::speed($latestResult->words_per_minute, $learner->grade_level) : null;
    $trend = PF::trend($comparison);
    $labels = PF::skillLabels();
    $canSend = $reportRecipients->isNotEmpty();
@endphp
<div class="pp">
    <div class="mb-3">
        <a href="{{ route('reports.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> All reports</a>
    </div>

    <h4 class="mb-1"><i class="bi bi-file-earmark-text me-2"></i>{{ $learner->full_name }}'s reading report</h4>
    <p class="text-muted">Grade {{ $learner->grade_level }} &middot; Made {{ now()->format('F j, Y') }}</p>

    {{-- Actions: full-width buttons on phones --}}
    <div class="d-grid gap-2 d-sm-flex mb-4">
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#sendReportModal" {{ $canSend ? '' : 'disabled' }}>
            <i class="bi bi-send"></i> Send to teacher
        </button>
        <a href="{{ route('reports.pdf', $learner) }}" class="btn btn-outline-danger"><i class="bi bi-file-earmark-pdf"></i> Save as PDF</a>
        <a href="{{ route('reports.print', $learner) }}" target="_blank" rel="noopener" class="btn btn-outline-secondary"><i class="bi bi-printer"></i> Print</a>
    </div>
    @unless($canSend)
        <p class="small text-muted mt-n2 mb-4">There is no teacher linked to {{ $name }}'s class yet, so sending is not available.</p>
    @endunless

    {{-- Summary --}}
    <div class="card border-0 shadow-sm mb-4">
        <div class="pp-hero pp-{{ $lv['tone'] }} pp-tone d-flex gap-3 align-items-start">
            <div class="pp-emoji" aria-hidden="true">{{ $lv['emoji'] }}</div>
            <div>
                <h3 class="pp-tone-label">{{ $name }}: {{ strtolower($lv['title']) }}</h3>
                <p class="mb-1">{{ $lv['meaning'] }}</p>
                @if($latestResult)
                    <div>{{ PF::accuracySentence($latestResult->accuracy_rate) }} {{ $speed['text'] }}.</div>
                @endif
            </div>
        </div>
    </div>

    @if($latestResult)
        <div class="pp-tone pp-{{ $trend['tone'] }} rounded-3 p-3 mb-4 d-flex gap-3 align-items-center">
            <i class="bi {{ $trend['icon'] }} fs-2 pp-tone-label"></i>
            <div><strong>Compared with last time</strong><div>{{ $trend['text'] }}</div></div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white"><i class="bi bi-stars me-1"></i> Skills</div>
            <div class="card-body py-2">
                @foreach($labels as $key => $meta)
                    @php $s = PF::skill($skillScores[$key] ?? null); @endphp
                    <div class="pp-skill pp-{{ $s['tone'] }}">
                        <div class="pp-skill-icon"><i class="bi {{ $meta['icon'] }}"></i></div>
                        <div class="flex-grow-1 fw-bold">{{ $meta['label'] }}</div>
                        <div class="text-end">
                            <div class="pp-stars" aria-hidden="true">@for($i = 1; $i <= 3; $i++)<span class="{{ $i <= $s['stars'] ? '' : 'off' }}">★</span>@endfor</div>
                            <div class="small" style="color:var(--tone)">{{ $s['word'] }}</div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Reading checks as cards (no wide table on phones) --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white"><i class="bi bi-clipboard-check me-1"></i> Reading checks</div>
        <div class="card-body">
            @forelse($assessments as $assessment)
                @php $r = $assessment->result; $cl = PF::level($r?->reading_level); @endphp
                <div class="pp-checkup pp-{{ $cl['tone'] }}">
                    <div class="d-flex justify-content-between flex-wrap gap-1">
                        <strong>{{ $assessment->created_at?->format('F j, Y') }}</strong>
                        <span class="pp-pill pp-{{ $cl['tone'] }}">{{ $cl['emoji'] }} {{ $cl['short'] }}</span>
                    </div>
                    <div class="small text-muted">{{ $assessment->material?->title ?? 'Reading passage' }}</div>
                    @if($r)
                        <div class="small mt-1">{{ PF::accuracySentence($r->accuracy_rate) }}</div>
                        <a class="small" href="{{ route('parent.children.assessment-detail', [$learner, $assessment]) }}">See the full story</a>
                    @endif
                </div>
            @empty
                <p class="text-muted mb-0">No reading checks yet.</p>
            @endforelse
        </div>
    </div>
    @if($assessments->hasPages())
        <div class="mb-3">{{ $assessments->links() }}</div>
    @endif

    @if($canSend)
        <div class="modal fade" id="sendReportModal" tabindex="-1" aria-labelledby="sendReportTitle" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form method="POST" action="{{ route('parent.children.send-report', $learner) }}">
                        @csrf
                        <div class="modal-header">
                            <h5 class="modal-title" id="sendReportTitle">Send {{ $name }}'s report</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            @if($errors->any() && old('receiver_id') !== null)
                                <div class="alert alert-danger small py-2"><ul class="mb-0 ps-3">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
                            @endif
                            <p class="text-muted small">The teacher gets this report as a message with a link. You can follow the reply in Messages.</p>
                            <div class="mb-3">
                                <label class="form-label fw-bold" for="reportReceiver">Send to</label>
                                <select name="receiver_id" id="reportReceiver" class="form-select" required>
                                    <option value="">Choose...</option>
                                    @foreach($reportRecipients as $role => $group)
                                        <optgroup label="{{ $role === 'admin' ? 'School administrator' : 'Class teacher' }}">
                                            @foreach($group as $person)
                                                <option value="{{ $person->id }}" {{ old('receiver_id') == $person->id ? 'selected' : '' }}>{{ $person->name }}</option>
                                            @endforeach
                                        </optgroup>
                                    @endforeach
                                </select>
                            </div>
                            <div class="mb-1">
                                <label class="form-label fw-bold" for="reportNote">Add a note (optional)</label>
                                <textarea name="note" id="reportNote" class="form-control" rows="3" maxlength="2000"
                                          placeholder="For example: I have a question about the results.">{{ old('note') }}</textarea>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary"><i class="bi bi-send"></i> Send report</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        @if($errors->any() && old('receiver_id') !== null)
            @push('scripts')
            <script>document.addEventListener('DOMContentLoaded', function () { new bootstrap.Modal(document.getElementById('sendReportModal')).show(); });</script>
            @endpush
        @endif
    @endif
</div>
@endsection
