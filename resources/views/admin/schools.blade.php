@extends('layouts.app')

@section('title', 'Manage School')

@section('content')
    <x-page-header title="Manage School" icon="bi-building" :subtitle="$school->name ?? null">
        <x-slot:actions>
            <a href="{{ route('admin.classes') }}" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-diagram-3 me-1"></i> Classrooms
            </a>
        </x-slot:actions>
    </x-page-header>

    {{-- At a glance --}}
    <div class="row g-3 mb-4">
        @foreach([['Sections', $stats['sections'], 'bi-diagram-3'], ['Teachers', $stats['teachers'], 'bi-person-badge'], ['Learners', $stats['learners'], 'bi-people']] as [$label, $value, $icon])
            <div class="col-4">
                <div class="card border-0 shadow-sm text-center"><div class="card-body py-3">
                    <i class="bi {{ $icon }} text-primary"></i>
                    <div class="h4 mb-0">{{ number_format($value) }}</div>
                    <small class="text-muted">{{ $label }}</small>
                </div></div>
            </div>
        @endforeach
    </div>

    {{-- School information (printed in the header of DepEd forms such as Phil-IRI Form 2) --}}
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-building-gear me-1 text-primary"></i>School Information</h6></div>
        <form method="POST" action="{{ route('admin.schools.update', $school) }}">
            @csrf @method('PUT')
            <div class="card-body">
                @if($errors->hasAny(['name', 'school_id_number', 'email']))
                    <div class="alert alert-danger py-2 small">
                        @foreach($errors->only(['name', 'school_id_number', 'email']) as $msgs) @foreach((array) $msgs as $m) <div>{{ $m }}</div> @endforeach @endforeach
                    </div>
                @endif
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">School name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" value="{{ old('name', $school->name) }}" required maxlength="255">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">School ID</label>
                        <input type="text" name="school_id_number" class="form-control" value="{{ old('school_id_number', $school->school_id_number) }}" maxlength="50">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">School Principal / Head</label>
                        <input type="text" name="principal_name" class="form-control" value="{{ old('principal_name', $school->principal_name) }}" maxlength="255">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Division <span class="text-muted small">(Sangay)</span></label>
                        <input type="text" name="division" class="form-control" value="{{ old('division', $school->division) }}" maxlength="255">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">District <span class="text-muted small">(Distrito)</span></label>
                        <input type="text" name="district" class="form-control" value="{{ old('district', $school->district) }}" maxlength="255">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Region <span class="text-muted small">(Rehiyon)</span></label>
                        <input type="text" name="region" class="form-control" value="{{ old('region', $school->region) }}" maxlength="255">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Address</label>
                        <input type="text" name="address" class="form-control" value="{{ old('address', $school->address) }}">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Contact number</label>
                        <input type="text" name="contact_number" class="form-control" value="{{ old('contact_number', $school->contact_number) }}" maxlength="50">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" value="{{ old('email', $school->email) }}" maxlength="255">
                    </div>
                </div>
            </div>
            <div class="card-footer bg-white text-end">
                <button type="submit" class="btn btn-primary px-4"><i class="bi bi-save me-1"></i> Save School Information</button>
            </div>
        </form>
    </div>

    <p class="small text-muted mt-3 mb-0">
        Grades, sections and advisers are managed under <a href="{{ route('admin.classes') }}">Classrooms</a>.
    </p>
@endsection
