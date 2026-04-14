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
        <label for="grade_level" class="form-label">Grade Level <span class="text-danger">*</span></label>
        <select class="form-select @error('grade_level') is-invalid @enderror" id="grade_level" name="grade_level" required>
            <option value="">Select</option>
            @for($i = 1; $i <= 6; $i++)
                <option value="{{ $i }}" {{ old('grade_level', $learner->grade_level ?? '') == $i ? 'selected' : '' }}>
                    Grade {{ $i }}
                </option>
            @endfor
        </select>
        @error('grade_level') <div class="invalid-feedback">{{ $message }}</div> @enderror
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

    <div class="col-md-4">
        <label for="mother_tongue" class="form-label">Mother Tongue</label>
        <select class="form-select" id="mother_tongue" name="mother_tongue">
            <option value="">Select</option>
            <option value="hiligaynon" {{ old('mother_tongue', $learner->mother_tongue ?? '') === 'hiligaynon' ? 'selected' : '' }}>Hiligaynon</option>
            <option value="filipino" {{ old('mother_tongue', $learner->mother_tongue ?? '') === 'filipino' ? 'selected' : '' }}>Filipino</option>
            <option value="english" {{ old('mother_tongue', $learner->mother_tongue ?? '') === 'english' ? 'selected' : '' }}>English</option>
            <option value="cebuano" {{ old('mother_tongue', $learner->mother_tongue ?? '') === 'cebuano' ? 'selected' : '' }}>Cebuano</option>
        </select>
    </div>
    <div class="col-md-4">
        <label for="school_id" class="form-label">School</label>
        <select class="form-select" id="school_id" name="school_id">
            <option value="">Select school</option>
            @foreach($schools ?? [] as $school)
                <option value="{{ $school->id }}" {{ old('school_id', $learner->school_id ?? Auth::user()->school_id) == $school->id ? 'selected' : '' }}>
                    {{ $school->name }}
                </option>
            @endforeach
        </select>
    </div>
    <div class="col-md-4">
        <label for="class_id" class="form-label">Class / Section</label>
        <select class="form-select" id="class_id" name="class_id">
            <option value="">Select class</option>
            @foreach($classes ?? [] as $class)
                <option value="{{ $class->id }}" {{ old('class_id', $learner->class_id ?? '') == $class->id ? 'selected' : '' }}>
                    {{ $class->name }} (Grade {{ $class->grade_level }})
                </option>
            @endforeach
        </select>
    </div>
</div>
