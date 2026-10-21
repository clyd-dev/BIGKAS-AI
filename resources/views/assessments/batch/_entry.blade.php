{{-- One roster row. Errors are read by position: "entries.N.*" belongs to row N and no other. --}}
@php
    $learnerErrors = is_int($i) ? $errors->get("entries.$i.learner_id") : [];
    $materialErrors = is_int($i) ? $errors->get("entries.$i.material_id") : [];
@endphp
<div class="border rounded p-3 mb-3" data-role="entry" data-entry="{{ $i }}">
    <div class="row g-3 align-items-start">
        <div class="col-md-4">
            <label class="form-label">Learner <span class="text-danger">*</span></label>
            <select class="form-select {{ $learnerErrors ? 'is-invalid' : '' }}" name="entries[{{ $i }}][learner_id]" data-role="learner" required>
                <option value="">Choose a learner...</option>
                @foreach($learners as $learner)
                    <option value="{{ $learner->id }}" data-grade="{{ $learner->grade_level }}"
                        {{ (string) ($row['learner_id'] ?? '') === (string) $learner->id ? 'selected' : '' }}>
                        {{ $learner->full_name }} (Grade {{ $learner->grade_level }})
                    </option>
                @endforeach
            </select>
            @foreach($learnerErrors as $message)
                <div class="invalid-feedback d-block">{{ $message }}</div>
            @endforeach
        </div>

        <div class="col-md-5">
            <label class="form-label">Material <span class="text-muted">(optional override)</span></label>
            <select class="form-select {{ $materialErrors ? 'is-invalid' : '' }}" name="entries[{{ $i }}][material_id]"
                    data-role="material" data-old="{{ $row['material_id'] ?? '' }}">
                <option value="">Use the default material</option>
                @if(filled($row['material_id'] ?? null))
                    <option value="{{ $row['material_id'] }}" selected>Selected material</option>
                @endif
            </select>
            @foreach($materialErrors as $message)
                <div class="invalid-feedback d-block">{{ $message }}</div>
            @endforeach
            <div class="form-check mt-2">
                <input class="form-check-input" type="checkbox" name="entries[{{ $i }}][allow_fallback]" value="1"
                       id="fallback-{{ $i }}" {{ filter_var($row['allow_fallback'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 'checked' : '' }}>
                <label class="form-check-label" for="fallback-{{ $i }}">Allow a material from another grade</label>
            </div>
        </div>

        <div class="col-md-3 text-md-end">
            <button type="button" class="btn btn-outline-danger btn-sm" data-role="remove">
                <i class="bi bi-x-lg me-1"></i>Remove
            </button>
        </div>
    </div>
</div>
