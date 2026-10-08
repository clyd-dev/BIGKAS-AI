{{-- Learner Form Partial (used by create and edit) --}}
<div class="row g-3">
    <div class="col-md-4">
        <label for="first_name" class="form-label">First Name <span class="text-danger">*</span></label>
        <input type="text" class="form-control @error('first_name') is-invalid @enderror"
               id="first_name" name="first_name" value="{{ old('first_name', $learner->first_name ?? '') }}" required>
        @error('first_name') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>
    <div class="col-md-4">
        <label for="middle_name" class="form-label">Middle Name</label>
        <input type="text" class="form-control" id="middle_name" name="middle_name"
               value="{{ old('middle_name', $learner->middle_name ?? '') }}">
    </div>
    <div class="col-md-4">
        <label for="last_name" class="form-label">Last Name <span class="text-danger">*</span></label>
        <input type="text" class="form-control @error('last_name') is-invalid @enderror"
               id="last_name" name="last_name" value="{{ old('last_name', $learner->last_name ?? '') }}" required>
        @error('last_name') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>

    <div class="col-md-3">
        <label for="grade_level" class="form-label">Grade Level</label>
        @if(isset($lockedClass) && $lockedClass)
            <input type="text" class="form-control bg-light" value="Grade {{ $lockedClass->grade_level }}" readonly>
            <input type="hidden" name="grade_level" value="{{ $lockedClass->grade_level }}">
        @else
            <select class="form-select @error('grade_level') is-invalid @enderror" id="grade_level" name="grade_level" required>
                <option value="">Select</option>
                @for($i = 1; $i <= 6; $i++)
                    <option value="{{ $i }}" {{ old('grade_level', $learner->grade_level ?? '') == $i ? 'selected' : '' }}>
                        Grade {{ $i }}
                    </option>
                @endfor
            </select>
            @error('grade_level') <div class="invalid-feedback">{{ $message }}</div> @enderror
        @endif
    </div>
    <div class="col-md-3">
        <label for="gender" class="form-label">Gender</label>
        <select class="form-select" id="gender" name="gender">
            <option value="">Select</option>
            <option value="male" {{ old('gender', $learner->gender ?? '') === 'male' ? 'selected' : '' }}>Male</option>
            <option value="female" {{ old('gender', $learner->gender ?? '') === 'female' ? 'selected' : '' }}>Female</option>
        </select>
    </div>
    <div class="col-md-3">
        <label for="birth_date" class="form-label">Birth Date</label>
        <input type="date" class="form-control" id="birth_date" name="birth_date"
               value="{{ old('birth_date', $learner->birth_date ?? '') }}">
    </div>
    <div class="col-md-3">
        <label for="lrn" class="form-label">LRN (Learner Ref. No.)</label>
        <input type="text" class="form-control" id="lrn" name="lrn"
               value="{{ old('lrn', $learner->lrn ?? '') }}" placeholder="12-digit LRN">
    </div>

    <div class="col-md-6">
        <label class="form-label">School</label>
        <input type="text" class="form-control bg-light" value="{{ $schoolName ?? 'Old Sagay Elementary School' }}" readonly>
    </div>
    <div class="col-md-6">
        <label for="class_id" class="form-label">Class / Section</label>
        @if(isset($lockedClass) && $lockedClass)
            <input type="text" class="form-control bg-light" value="{{ $lockedClass->section }}" readonly>
            <input type="hidden" name="class_id" value="{{ $lockedClass->id }}">
            <div class="form-text">You're assigned to this class/section, so it can't be changed here.</div>
        @else
            <select class="form-select" id="class_id" name="class_id">
                <option value="">Select class</option>
                @foreach(($allClasses ?? collect())->groupBy('grade_level') as $grade => $classes)
                    <optgroup label="Grade {{ $grade }}">
                        @foreach($classes as $class)
                            <option value="{{ $class->id }}" {{ old('class_id', $learner->class_id ?? '') == $class->id ? 'selected' : '' }}>
                                {{ $class->section }}
                            </option>
                        @endforeach
                    </optgroup>
                @endforeach
            </select>
            <div class="form-text">Selecting a class will automatically set the grade level.</div>
        @endif
    </div>
    
    <div class="col-md-12">
        <label for="parent_ids" class="form-label">Linked Parents</label>
        @php
            $selectedParents = isset($learner) ? $learner->users->where('role', 'parent')->pluck('id')->toArray() : [];
        @endphp
        <select class="form-select" id="parent_ids" name="parent_ids[]" multiple>
            @foreach($parents as $parent)
                <option value="{{ $parent->id }}" {{ in_array($parent->id, old('parent_ids', $selectedParents)) ? 'selected' : '' }}>
                    {{ $parent->name }} ({{ $parent->email }})
                </option>
            @endforeach
        </select>
        <div class="form-text">Hold Ctrl (Windows) or Cmd (Mac) to select multiple parents. You must create parent accounts in the Users panel first.</div>
    </div>
</div>
