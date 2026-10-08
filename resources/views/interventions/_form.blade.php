{{-- Intervention Form Partial (used by create and edit) --}}
<div class="row g-3">
    <div class="col-12">
        <label for="name" class="form-label">Name <span class="text-danger">*</span></label>
        <input type="text" class="form-control @error('name') is-invalid @enderror"
               id="name" name="name" value="{{ old('name', $intervention->name ?? '') }}" required>
        @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>
    <div class="col-12">
        <label for="description" class="form-label">Description <span class="text-danger">*</span></label>
        <textarea class="form-control @error('description') is-invalid @enderror"
                  id="description" name="description" rows="3" required>{{ old('description', $intervention->description ?? '') }}</textarea>
        @error('description') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>
    <div class="col-md-6">
        <label for="target_weakness" class="form-label">Target Weakness <span class="text-danger">*</span></label>
        <select class="form-select @error('target_weakness') is-invalid @enderror" id="target_weakness" name="target_weakness" required>
            <option value="">Select</option>
            @foreach($weaknessCategories as $id => $cat)
                <option value="{{ $id }}" {{ old('target_weakness', $intervention->target_weakness ?? '') == $id ? 'selected' : '' }}>
                    {{ $cat['name'] }}
                </option>
            @endforeach
        </select>
        @error('target_weakness') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>
    <div class="col-md-6">
        <label for="activity_type" class="form-label">Activity Type <span class="text-danger">*</span></label>
        <select class="form-select @error('activity_type') is-invalid @enderror" id="activity_type" name="activity_type" required>
            @foreach(['game' => 'Interactive Game', 'drill' => 'Practice Drill', 'reading' => 'Reading Activity', 'writing' => 'Writing Activity', 'audio' => 'Audio-Based', 'visual' => 'Visual Activity'] as $value => $label)
                <option value="{{ $value }}" {{ old('activity_type', $intervention->activity_type ?? 'drill') === $value ? 'selected' : '' }}>{{ $label }}</option>
            @endforeach
        </select>
        @error('activity_type') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>
    <div class="col-md-4">
        <label for="grade_level_min" class="form-label">Grade Level (Min) <span class="text-danger">*</span></label>
        <select class="form-select @error('grade_level_min') is-invalid @enderror" id="grade_level_min" name="grade_level_min" required>
            @for($i = 3; $i <= 6; $i++)
                <option value="{{ $i }}" {{ old('grade_level_min', $intervention->grade_level_min ?? 3) == $i ? 'selected' : '' }}>Grade {{ $i }}</option>
            @endfor
        </select>
        @error('grade_level_min') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>
    <div class="col-md-4">
        <label for="grade_level_max" class="form-label">Grade Level (Max) <span class="text-danger">*</span></label>
        <select class="form-select @error('grade_level_max') is-invalid @enderror" id="grade_level_max" name="grade_level_max" required>
            @for($i = 3; $i <= 6; $i++)
                <option value="{{ $i }}" {{ old('grade_level_max', $intervention->grade_level_max ?? 6) == $i ? 'selected' : '' }}>Grade {{ $i }}</option>
            @endfor
        </select>
        @error('grade_level_max') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>
    <div class="col-md-4">
        <label for="estimated_duration" class="form-label">Duration (minutes) <span class="text-danger">*</span></label>
        <input type="number" class="form-control @error('estimated_duration') is-invalid @enderror"
               id="estimated_duration" name="estimated_duration" min="1" max="240"
               value="{{ old('estimated_duration', $intervention->estimated_duration ?? 15) }}" required>
        @error('estimated_duration') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>
    <div class="col-12">
        <label for="materials_needed" class="form-label">Materials Needed</label>
        <textarea class="form-control" id="materials_needed" name="materials_needed" rows="2">{{ old('materials_needed', $intervention->materials_needed ?? '') }}</textarea>
    </div>
    <div class="col-12">
        <label for="instructions" class="form-label">Instructions <span class="text-danger">*</span></label>
        <textarea class="form-control @error('instructions') is-invalid @enderror"
                  id="instructions" name="instructions" rows="4" required>{{ old('instructions', $intervention->instructions ?? '') }}</textarea>
        @error('instructions') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>
    <div class="col-md-6">
        <div class="form-check">
            <input class="form-check-input" type="checkbox" id="for_teacher" name="for_teacher" value="1"
                   {{ old('for_teacher', $intervention->for_teacher ?? true) ? 'checked' : '' }}>
            <label class="form-check-label" for="for_teacher">Usable by teachers (classroom activity)</label>
        </div>
    </div>
    <div class="col-md-6">
        <div class="form-check">
            <input class="form-check-input" type="checkbox" id="for_parent" name="for_parent" value="1"
                   {{ old('for_parent', $intervention->for_parent ?? true) ? 'checked' : '' }}>
            <label class="form-check-label" for="for_parent">Usable by parents (home activity)</label>
        </div>
    </div>
</div>
