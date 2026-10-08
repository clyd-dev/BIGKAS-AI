@extends('layouts.app')

@section('title', 'Manage School')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-0"><i class="bi bi-building me-2"></i>Manage School</h4>
            <small class="text-muted">{{ $school->name ?? 'Old Sagay Elementary School' }}</small>
        </div>
        <a href="{{ route('dashboard') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Dashboard</a>
    </div>

    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    {{-- School information (printed in the header of DepEd forms such as Phil-IRI Form 2) --}}
    @if($school)
        <div class="card border-0 shadow-sm mb-3">
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
    @endif

    <div class="row g-3">
        {{-- Left: Add Grade & Section --}}
        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-plus-circle me-1 text-primary"></i>Add Grade &amp; Section</h6></div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.classes.store') }}">
                        @csrf
                        <div class="mb-3">
                            <label class="form-label">Grade Level <span class="text-danger">*</span></label>
                            <select name="grade_level" class="form-select @error('grade_level') is-invalid @enderror" required>
                                <option value="">Select grade...</option>
                                @foreach(range(1, 6) as $g)
                                    <option value="{{ $g }}" {{ old('grade_level') == $g ? 'selected' : '' }}>Grade {{ $g }}</option>
                                @endforeach
                            </select>
                            @error('grade_level')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Section Name <span class="text-danger">*</span></label>
                            <input type="text" name="section" class="form-control @error('section') is-invalid @enderror"
                                   placeholder="e.g. Sampaguita" value="{{ old('section') }}" required>
                            @error('section')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Adviser / Teacher</label>
                            <select name="teacher_id" class="form-select">
                                <option value="">— Unassigned —</option>
                                @foreach($teachers as $teacher)
                                    <option value="{{ $teacher->id }}" {{ old('teacher_id') == $teacher->id ? 'selected' : '' }}>
                                        {{ $teacher->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">School Year</label>
                            <input type="text" name="school_year" class="form-control"
                                   placeholder="e.g. 2024-2025" value="{{ old('school_year', date('Y') . '-' . (date('Y')+1)) }}">
                        </div>
                        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-plus-circle me-1"></i> Add Section</button>
                    </form>
                </div>
            </div>
        </div>

        {{-- Right: Grades & Sections List --}}
        <div class="col-md-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><i class="bi bi-list-ul me-1"></i>Grades &amp; Sections ({{ $classes->count() }} total)</h6>
                </div>
                <div class="card-body p-0">
                    @php
                        $grouped = $classes->groupBy('grade_level')->sortKeys();
                    @endphp

                    @forelse($grouped as $grade => $sections)
                        <div class="border-bottom">
                            <div class="px-3 py-2 bg-light d-flex justify-content-between align-items-center">
                                <span class="fw-semibold"><i class="bi bi-mortarboard me-2 text-primary"></i>Grade {{ $grade }}</span>
                                <span class="badge bg-primary rounded-pill">{{ $sections->count() }} section{{ $sections->count() > 1 ? 's' : '' }}</span>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0 align-middle">
                                    <thead class="table-light" style="font-size: 0.8rem;">
                                        <tr>
                                            <th class="ps-4">Section</th>
                                            <th>Adviser / Teacher</th>
                                            <th>School Year</th>
                                            <th>Learners</th>
                                            <th class="text-end pe-3">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($sections as $class)
                                            <tr>
                                                <td class="ps-4 fw-semibold">{{ $class->section }}</td>
                                                <td>
                                                    @if($class->teacher)
                                                        <span class="text-dark">{{ $class->teacher->name }}</span>
                                                    @else
                                                        <span class="text-muted fst-italic">Unassigned</span>
                                                    @endif
                                                </td>
                                                <td class="text-muted small">{{ $class->school_year ?? '—' }}</td>
                                                <td>
                                                    <span class="badge bg-secondary rounded-pill">{{ $class->learners_count }}</span>
                                                </td>
                                                <td class="text-end pe-3">
                                                    <button type="button" class="btn btn-sm btn-outline-warning"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#editClass{{ $class->id }}"
                                                            title="Edit">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <form method="POST" action="{{ route('admin.classes.delete', $class) }}"
                                                          class="d-inline"
                                                          onsubmit="return confirm('Delete Grade {{ $grade }} – {{ $class->section }}? This cannot be undone.')">
                                                        @csrf @method('DELETE')
                                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @empty
                        <div class="text-center text-muted py-5">
                            <i class="bi bi-diagram-3 display-5 d-block mb-2"></i>
                            No grades or sections added yet.<br>
                            <small>Use the form on the left to add Grade 3–6 sections.</small>
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    {{-- Edit Modals --}}
    @foreach($classes as $class)
        <div class="modal fade" id="editClass{{ $class->id }}" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="POST" action="{{ route('admin.classes.update', $class) }}">
                        @csrf @method('PUT')
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="bi bi-pencil me-1"></i>Edit Section</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label">Grade Level <span class="text-danger">*</span></label>
                                <select name="grade_level" class="form-select" required>
                                    @foreach(range(1, 6) as $g)
                                        <option value="{{ $g }}" {{ $class->grade_level == $g ? 'selected' : '' }}>Grade {{ $g }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Section Name <span class="text-danger">*</span></label>
                                <input type="text" name="section" class="form-control" value="{{ $class->section }}" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Adviser / Teacher</label>
                                <select name="teacher_id" class="form-select">
                                    <option value="">— Unassigned —</option>
                                    @foreach($teachers as $teacher)
                                        <option value="{{ $teacher->id }}" {{ $class->teacher_id == $teacher->id ? 'selected' : '' }}>
                                            {{ $teacher->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">School Year</label>
                                <input type="text" name="school_year" class="form-control" value="{{ $class->school_year }}">
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endforeach
@endsection
