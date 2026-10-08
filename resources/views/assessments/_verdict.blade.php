{{--
    Teacher decision on an AI result.

    The model advises, the teacher decides. Until a teacher records a decision
    the result is explicitly "pending review" rather than silently treated as
    fact. Admins see the decision but can't make one.
--}}
@php
    $verdict = $assessment->verdict;
    $canDecide = auth()->user()->isTeacher();
    $weaknessLabels = config('bigkas.weakness_categories', []);
@endphp

<div class="card border-0 shadow-sm mb-4">
    @if(!$verdict)
        <div class="card-header bg-warning-subtle text-warning-emphasis d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h6 class="mb-0"><i class="bi bi-hourglass-split me-1"></i> Pending your review</h6>
            <span class="small">This result does not count as final until you decide.</span>
        </div>
    @elseif($verdict->isInvalidated())
        <div class="card-header bg-danger-subtle text-danger-emphasis">
            <h6 class="mb-0"><i class="bi bi-x-octagon me-1"></i> Marked invalid — excluded from this learner's level</h6>
        </div>
    @elseif($verdict->isOverridden())
        <div class="card-header bg-primary-subtle text-primary-emphasis">
            <h6 class="mb-0"><i class="bi bi-pencil-square me-1"></i> Overridden by teacher</h6>
        </div>
    @else
        <div class="card-header bg-success-subtle text-success-emphasis">
            <h6 class="mb-0"><i class="bi bi-check-circle me-1"></i> AI result accepted</h6>
        </div>
    @endif

    <div class="card-body">
        @if($verdict)
            <div class="row g-3 align-items-start">
                <div class="col-md-7">
                    @if($verdict->isOverridden())
                        <div class="mb-2">
                            <span class="text-muted small d-block">Final reading level</span>
                            <strong>{{ ucfirst($verdict->final_reading_level) }}</strong>
                            @if($result->reading_level !== $verdict->final_reading_level)
                                <span class="text-muted small">(AI said {{ ucfirst($result->reading_level) }})</span>
                            @endif
                        </div>
                        @if($verdict->final_primary_weakness !== null)
                            <div class="mb-2">
                                <span class="text-muted small d-block">Final primary weakness</span>
                                <strong>{{ $weaknessLabels[$verdict->final_primary_weakness]['name'] ?? 'Unknown' }}</strong>
                                @if($result->primary_weakness !== $verdict->final_primary_weakness)
                                    <span class="text-muted small">
                                        (AI said {{ $weaknessLabels[$result->primary_weakness]['name'] ?? 'none' }})
                                    </span>
                                @endif
                            </div>
                        @endif
                        @if($verdict->manual_scoring)
                            <div class="mb-2">
                                <span class="text-muted small d-block">Manually re-scored</span>
                                <strong>{{ number_format((float) $verdict->final_accuracy_rate, 1) }}%</strong> accuracy,
                                <strong>{{ number_format((float) $verdict->final_words_per_minute, 0) }}</strong> WPM
                                <span class="text-muted small d-block">
                                    {{ $verdict->words_read }} words read &middot; {{ $verdict->miscueCount() }} miscues
                                    ({{ $verdict->manual_substitutions }} substitutions,
                                    {{ $verdict->manual_omissions }} omissions,
                                    {{ $verdict->manual_insertions }} insertions,
                                    {{ $verdict->manual_self_corrections }} self-corrections)
                                </span>
                            </div>
                        @endif
                    @endif

                    @if($verdict->reason)
                        <div class="mb-2">
                            <span class="text-muted small d-block">Reason given</span>
                            <div class="p-2 bg-light rounded">{{ $verdict->reason }}</div>
                        </div>
                    @endif
                </div>
                <div class="col-md-5">
                    <div class="small text-muted">
                        {{ $verdict->decisionLabel() }} by
                        <strong>{{ $verdict->decidedBy?->name ?? 'Unknown' }}</strong><br>
                        {{ $verdict->decided_at?->format('M d, Y g:i A') }}
                    </div>

                    @if($canDecide)
                        <button class="btn btn-sm btn-outline-secondary mt-2" type="button"
                                data-bs-toggle="collapse" data-bs-target="#verdictForm">
                            <i class="bi bi-arrow-repeat me-1"></i> Change decision
                        </button>
                    @endif

                    @if($verdict->isInvalidated() && $canDecide)
                        <a href="{{ route('assessments.start', $assessment->learner) }}"
                           class="btn btn-sm btn-primary mt-2">
                            <i class="bi bi-mic me-1"></i> Re-assess this learner
                        </a>
                    @endif
                </div>
            </div>
        @elseif($canDecide)
            <p class="text-muted small mb-3">
                Review the evidence below, then record what you conclude. The AI's own numbers are kept either way.
            </p>
            <div class="d-flex flex-wrap gap-2">
                <form method="POST" action="{{ route('assessments.verdict', $assessment) }}" class="d-inline">
                    @csrf
                    <input type="hidden" name="decision" value="accepted">
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-check-circle me-1"></i> Accept AI Result
                    </button>
                </form>
                <button class="btn btn-outline-primary" type="button"
                        data-bs-toggle="collapse" data-bs-target="#verdictForm"
                        data-preset="overridden">
                    <i class="bi bi-pencil-square me-1"></i> Override&hellip;
                </button>
                <button class="btn btn-outline-danger" type="button"
                        data-bs-toggle="collapse" data-bs-target="#verdictForm"
                        data-preset="invalidated">
                    <i class="bi bi-x-octagon me-1"></i> Mark Invalid &amp; Re-assess
                </button>
            </div>
        @else
            <p class="text-muted small mb-0">
                <i class="bi bi-info-circle me-1"></i> No teacher decision has been recorded for this result yet.
            </p>
        @endif

        {{-- Override / invalidate form --}}
        @if($canDecide)
            <div class="collapse mt-3" id="verdictForm">
                <form method="POST" action="{{ route('assessments.verdict', $assessment) }}" class="border-top pt-3">
                    @csrf

                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label small">Decision <span class="text-danger">*</span></label>
                            <select class="form-select" name="decision" id="verdictDecision" required>
                                <option value="overridden" {{ old('decision', $verdict?->decision) === 'overridden' ? 'selected' : '' }}>
                                    Override the AI result
                                </option>
                                <option value="invalidated" {{ old('decision', $verdict?->decision) === 'invalidated' ? 'selected' : '' }}>
                                    Mark invalid — needs re-assessment
                                </option>
                                <option value="accepted" {{ old('decision', $verdict?->decision) === 'accepted' ? 'selected' : '' }}>
                                    Accept the AI result as-is
                                </option>
                            </select>
                        </div>

                        <div class="col-md-4 verdict-override-field">
                            <label class="form-label small">Final reading level <span class="text-danger">*</span></label>
                            <select class="form-select" name="final_reading_level">
                                @foreach(['frustration' => 'Frustration', 'instructional' => 'Instructional', 'independent' => 'Independent'] as $value => $label)
                                    <option value="{{ $value }}"
                                        {{ old('final_reading_level', $verdict?->final_reading_level ?? $result->reading_level) === $value ? 'selected' : '' }}>
                                        {{ $label }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-md-4 verdict-override-field">
                            <label class="form-label small">Final primary weakness</label>
                            <select class="form-select" name="final_primary_weakness">
                                <option value="">— No weakness —</option>
                                @foreach($weaknessLabels as $id => $cat)
                                    <option value="{{ $id }}"
                                        {{ (string) old('final_primary_weakness', $verdict?->final_primary_weakness ?? $result->primary_weakness) === (string) $id ? 'selected' : '' }}>
                                        {{ $cat['name'] }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        {{-- Optional manual re-scoring --}}
                        <div class="col-12 verdict-override-field">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" value="1"
                                       id="manualScoring" name="manual_scoring"
                                       {{ old('manual_scoring', $verdict?->manual_scoring) ? 'checked' : '' }}>
                                <label class="form-check-label" for="manualScoring">
                                    Re-score the reading myself (recomputes accuracy and words per minute from my own miscue counts)
                                </label>
                            </div>
                        </div>

                        <div class="col-12 {{ old('manual_scoring', $verdict?->manual_scoring) ? '' : 'd-none' }}" id="manualScoringFields">
                            <div class="p-3 bg-light rounded">
                                <div class="row g-2">
                                    @php
                                        $ml = $result->ml_analysis_json ?? [];
                                        $defaults = [
                                            'words_read' => $verdict?->words_read ?? ($ml['total_words'] ?? 0),
                                            'manual_substitutions' => $verdict?->manual_substitutions ?? $result->substitutions,
                                            'manual_omissions' => $verdict?->manual_omissions ?? $result->omissions,
                                            'manual_insertions' => $verdict?->manual_insertions ?? $result->insertions,
                                            'manual_self_corrections' => $verdict?->manual_self_corrections ?? $result->self_corrections,
                                        ];
                                    @endphp
                                    <div class="col-6 col-md-2">
                                        <label class="form-label small mb-1">Words read</label>
                                        <input type="number" min="1" class="form-control form-control-sm js-score"
                                               name="words_read" id="wordsRead"
                                               value="{{ old('words_read', $defaults['words_read']) }}">
                                    </div>
                                    <div class="col-6 col-md-2">
                                        <label class="form-label small mb-1">Substitutions</label>
                                        <input type="number" min="0" class="form-control form-control-sm js-score"
                                               name="manual_substitutions"
                                               value="{{ old('manual_substitutions', $defaults['manual_substitutions']) }}">
                                    </div>
                                    <div class="col-6 col-md-2">
                                        <label class="form-label small mb-1">Omissions</label>
                                        <input type="number" min="0" class="form-control form-control-sm js-score"
                                               name="manual_omissions"
                                               value="{{ old('manual_omissions', $defaults['manual_omissions']) }}">
                                    </div>
                                    <div class="col-6 col-md-2">
                                        <label class="form-label small mb-1">Insertions</label>
                                        <input type="number" min="0" class="form-control form-control-sm js-score"
                                               name="manual_insertions"
                                               value="{{ old('manual_insertions', $defaults['manual_insertions']) }}">
                                    </div>
                                    <div class="col-6 col-md-2">
                                        <label class="form-label small mb-1">Self-corrections</label>
                                        <input type="number" min="0" class="form-control form-control-sm"
                                               name="manual_self_corrections"
                                               value="{{ old('manual_self_corrections', $defaults['manual_self_corrections']) }}">
                                    </div>
                                    <div class="col-6 col-md-2">
                                        <label class="form-label small mb-1 d-block">Recomputed</label>
                                        <div class="small">
                                            <strong id="previewAccuracy">—</strong> accuracy<br>
                                            <strong id="previewWpm">—</strong> WPM
                                        </div>
                                    </div>
                                </div>
                                <div class="form-text mt-2">
                                    Self-corrections are recorded but don't count as miscues, following Phil-IRI.
                                    WPM uses the recorded audio length ({{ gmdate('i:s', (int) ($ml['duration_seconds'] ?? 0)) }}).
                                </div>
                            </div>
                        </div>

                        <div class="col-12">
                            <label class="form-label small">
                                Reason <span class="text-danger verdict-reason-required">*</span>
                            </label>
                            <textarea class="form-control @error('reason') is-invalid @enderror" name="reason" rows="2"
                                      placeholder="e.g. Recording was too noisy to judge; child read better than the transcript suggests.">{{ old('reason', $verdict?->reason) }}</textarea>
                            @error('reason') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            <div class="form-text">Kept with the result as the record of why it was changed.</div>
                        </div>
                    </div>

                    <div class="mt-3">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save me-1"></i> Save Decision
                        </button>
                        <button class="btn btn-outline-secondary ms-1" type="button"
                                data-bs-toggle="collapse" data-bs-target="#verdictForm">Cancel</button>
                    </div>
                </form>
            </div>
        @endif
    </div>
</div>

@if($canDecide)
@push('scripts')
<script>
(function () {
    const form = document.getElementById('verdictForm');
    if (!form) return;

    const decision = document.getElementById('verdictDecision');
    const manualToggle = document.getElementById('manualScoring');
    const manualFields = document.getElementById('manualScoringFields');
    const durationSeconds = {{ (float) ($result->ml_analysis_json['duration_seconds'] ?? 0) }};

    // The buttons above preselect which kind of decision this is.
    document.querySelectorAll('[data-preset]').forEach(btn => {
        btn.addEventListener('click', () => {
            decision.value = btn.dataset.preset;
            syncDecision();
        });
    });

    function syncDecision() {
        const isOverride = decision.value === 'overridden';
        const needsReason = decision.value !== 'accepted';

        form.querySelectorAll('.verdict-override-field').forEach(el => {
            el.classList.toggle('d-none', !isOverride);
        });
        form.querySelectorAll('.verdict-reason-required').forEach(el => {
            el.classList.toggle('d-none', !needsReason);
        });
        if (!isOverride) manualFields.classList.add('d-none');
    }

    function syncManual() {
        manualFields.classList.toggle('d-none', !manualToggle.checked);
        recompute();
    }

    // Mirrors the server-side recompute so the teacher sees the effect as they type.
    function recompute() {
        const num = name => parseInt(form.querySelector(`[name="${name}"]`)?.value || '0', 10) || 0;
        const wordsRead = num('words_read');
        const miscues = num('manual_substitutions') + num('manual_omissions') + num('manual_insertions');

        const accuracyEl = document.getElementById('previewAccuracy');
        const wpmEl = document.getElementById('previewWpm');

        if (wordsRead > 0) {
            accuracyEl.textContent = (Math.max(0, wordsRead - miscues) / wordsRead * 100).toFixed(1) + '%';
            wpmEl.textContent = durationSeconds > 0
                ? (wordsRead / (durationSeconds / 60)).toFixed(0)
                : '—';
        } else {
            accuracyEl.textContent = '—';
            wpmEl.textContent = '—';
        }
    }

    decision.addEventListener('change', syncDecision);
    manualToggle.addEventListener('change', syncManual);
    form.querySelectorAll('.js-score').forEach(el => el.addEventListener('input', recompute));

    syncDecision();
    recompute();
})();
</script>
@endpush
@endif
