{{-- One roster row: a tick to put this child in the batch, and an optional
     reading of their own. Checking is the only way a child joins, so the same
     child cannot be chosen twice. Errors are keyed by learner id, never by
     position, so a message can only ever land on the child it belongs to. --}}
@php
    $picked = filled(old("entries.{$learner->id}.learner_id"));
    $overrideId = old("entries.{$learner->id}.material_id");
    $override = filled($overrideId) ? $materials->firstWhere('id', (int) $overrideId) : null;
    $learnerErrors = $errors->get("entries.{$learner->id}.learner_id");
    $materialErrors = $errors->get("entries.{$learner->id}.material_id");
@endphp
<div class="list-group-item py-3 {{ ($learnerErrors || $materialErrors) ? 'bg-danger-subtle' : '' }}"
     data-role="row" data-learner="{{ $learner->id }}" data-grade="{{ $learner->grade_level }}">
    <div class="d-flex flex-column flex-md-row gap-2 gap-md-3 align-items-md-center">
        <div class="form-check roster-check mb-0 flex-md-grow-1">
            <input class="form-check-input roster-pick" type="checkbox" value="{{ $learner->id }}"
                   name="entries[{{ $learner->id }}][learner_id]" id="child-{{ $learner->id }}"
                   data-role="pick" @checked($picked)>
            <label class="form-check-label" for="child-{{ $learner->id }}">
                <span class="fw-semibold">{{ $learner->full_name }}</span>
                <span class="text-muted small ms-md-2 d-block d-md-inline">Grade {{ $learner->grade_level }}</span>
            </label>
        </div>

        <div class="roster-material">
            <select class="form-select form-select-sm {{ $materialErrors ? 'is-invalid' : '' }}"
                    name="entries[{{ $learner->id }}][material_id]" data-role="material"
                    aria-label="Reading for {{ $learner->full_name }}" @disabled(! $picked)>
                <option value="">Reads the batch material</option>
                @if($override)
                    <option value="{{ $override->id }}" selected>{{ $override->title }} (Grade {{ $override->grade_level }})</option>
                @endif
            </select>
        </div>
    </div>

    @foreach(array_merge($learnerErrors, $materialErrors) as $message)
        <div class="invalid-feedback d-block mt-1">{{ $message }}</div>
    @endforeach
</div>
