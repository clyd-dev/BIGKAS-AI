{{-- One roster row: a tick to put this child in the batch, and an optional
     reading of their own. Checking is the only way a child joins, so the same
     child cannot be chosen twice. Errors are keyed by learner id, never by
     position, so a message can only ever land on the child it belongs to.

     The child's own reading sits behind a second tick. Unticked, the row simply
     states which passage this child will read, because that is the ordinary
     case and a dropdown asks a question the teacher has already answered above.
     Ticked, the dropdown appears and she chooses. --}}
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
            {{-- What this child reads when the teacher has not said otherwise. The
                 title is written in by the script as she chooses the batch material,
                 so the row never claims a passage that is no longer selected. --}}
            <div class="small text-muted {{ $override ? 'd-none' : '' }}" data-role="batch-reading">
                <i class="bi bi-book me-1"></i><span data-role="batch-reading-title">the batch material</span>
            </div>

            <div class="form-check roster-override mt-1 {{ $picked ? '' : 'd-none' }}" data-role="override-wrap">
                <input class="form-check-input" type="checkbox" id="override-{{ $learner->id }}"
                       data-role="override" @checked($override)>
                <label class="form-check-label small" for="override-{{ $learner->id }}">
                    A different reading for {{ $learner->first_name }}
                </label>
            </div>

            <select class="form-select form-select-sm mt-1 {{ $override ? '' : 'd-none' }} {{ $materialErrors ? 'is-invalid' : '' }}"
                    name="entries[{{ $learner->id }}][material_id]" data-role="material"
                    aria-label="Reading for {{ $learner->full_name }}" @disabled(! $override)>
                <option value="">Choose a reading...</option>
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
