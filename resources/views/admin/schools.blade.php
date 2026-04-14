@extends('layouts.app')

@section('title', 'Manage Schools')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-building me-2"></i>Manage Schools</h4>
        <a href="{{ route('admin.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Admin Panel</a>
    </div>

    <div class="row g-3">
        {{-- Add School Form --}}
        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white"><h6 class="mb-0">Add School</h6></div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.schools.store') }}">
                        @csrf
                        <div class="mb-3">
                            <label for="name" class="form-label">School Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control @error('name') is-invalid @enderror" id="name" name="name" value="{{ old('name') }}" required>
                            @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="mb-3">
                            <label for="address" class="form-label">Address</label>
                            <input type="text" class="form-control" id="address" name="address" value="{{ old('address') }}">
                        </div>
                        <div class="mb-3">
                            <label for="district" class="form-label">District</label>
                            <input type="text" class="form-control" id="district" name="district" value="{{ old('district') }}">
                        </div>
                        <div class="mb-3">
                            <label for="school_id_number" class="form-label">School ID Number</label>
                            <input type="text" class="form-control" id="school_id_number" name="school_id_number" value="{{ old('school_id_number') }}">
                        </div>
                        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-plus-circle me-1"></i> Add School</button>
                    </form>
                </div>
            </div>
        </div>

        {{-- Schools List --}}
        <div class="col-md-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white"><h6 class="mb-0">Schools ({{ count($schools ?? []) }})</h6></div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr><th>Name</th><th>District</th><th>ID Number</th><th>Teachers</th><th>Learners</th><th class="text-end">Actions</th></tr>
                            </thead>
                            <tbody>
                                @forelse($schools ?? [] as $school)
                                    <tr>
                                        <td><strong>{{ $school->name }}</strong><br><small class="text-muted">{{ $school->address ?? '' }}</small></td>
                                        <td>{{ $school->district ?? '-' }}</td>
                                        <td><code>{{ $school->school_id_number ?? '-' }}</code></td>
                                        <td>{{ $school->users_count ?? 0 }}</td>
                                        <td>{{ $school->learners_count ?? 0 }}</td>
                                        <td class="text-end">
                                            <button type="button" class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#editSchool{{ $school->id }}">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <form method="POST" action="{{ route('admin.schools.delete', $school) }}" class="d-inline" onsubmit="return confirm('Delete this school?')">
                                                @csrf @method('DELETE')
                                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="text-center text-muted py-4">No schools registered</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Edit Modals --}}
    @foreach($schools ?? [] as $school)
        <div class="modal fade" id="editSchool{{ $school->id }}" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="POST" action="{{ route('admin.schools.update', $school) }}">
                        @csrf @method('PUT')
                        <div class="modal-header">
                            <h5 class="modal-title">Edit School</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label">School Name</label>
                                <input type="text" class="form-control" name="name" value="{{ $school->name }}" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Address</label>
                                <input type="text" class="form-control" name="address" value="{{ $school->address }}">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">District</label>
                                <input type="text" class="form-control" name="district" value="{{ $school->district }}">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">School ID Number</label>
                                <input type="text" class="form-control" name="school_id_number" value="{{ $school->school_id_number }}">
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">Update</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endforeach
@endsection
