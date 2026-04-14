@extends('layouts.app')

@section('title', 'Manage Interventions')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-lightbulb me-2"></i>Manage Interventions</h4>
        <a href="{{ route('admin.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Admin Panel</a>
    </div>

    <div class="row g-3">
        {{-- Add Intervention Form --}}
        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white"><h6 class="mb-0">Add Intervention</h6></div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.interventions.store') }}">
                        @csrf
                        <div class="mb-3">
                            <label class="form-label">Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control @error('name') is-invalid @enderror" name="name" value="{{ old('name') }}" required>
                            @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description <span class="text-danger">*</span></label>
                            <textarea class="form-control" name="description" rows="3" required>{{ old('description') }}</textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Target Weakness <span class="text-danger">*</span></label>
                            <select class="form-select" name="target_weakness" required>
                                @foreach(config('bigkas.weakness_categories', []) as $id => $label)
                                    <option value="{{ $id }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label">Type</label>
                                <select class="form-select" name="type">
                                    <option value="activity">Activity</option>
                                    <option value="exercise">Exercise</option>
                                    <option value="strategy">Strategy</option>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label">Difficulty</label>
                                <select class="form-select" name="difficulty">
                                    <option value="easy">Easy</option>
                                    <option value="medium" selected>Medium</option>
                                    <option value="hard">Hard</option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Duration (minutes)</label>
                            <input type="number" class="form-control" name="duration_minutes" value="{{ old('duration_minutes', 15) }}" min="1">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Instructions</label>
                            <textarea class="form-control" name="instructions" rows="3">{{ old('instructions') }}</textarea>
                        </div>
                        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-plus-circle me-1"></i> Add Intervention</button>
                    </form>
                </div>
            </div>
        </div>

        {{-- Interventions List --}}
        <div class="col-md-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white"><h6 class="mb-0">Interventions ({{ count($interventions ?? []) }})</h6></div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr><th>Name</th><th>Target</th><th>Type</th><th>Difficulty</th><th>Duration</th><th class="text-end">Actions</th></tr>
                            </thead>
                            <tbody>
                                @php $weaknessLabels = config('bigkas.weakness_categories', []); @endphp
                                @forelse($interventions ?? [] as $intervention)
                                    <tr>
                                        <td><strong>{{ $intervention->name }}</strong></td>
                                        <td>{{ $weaknessLabels[$intervention->target_weakness] ?? 'General' }}</td>
                                        <td>{{ ucfirst($intervention->type ?? '-') }}</td>
                                        <td>{{ ucfirst($intervention->difficulty ?? '-') }}</td>
                                        <td>{{ $intervention->duration_minutes ?? '-' }} min</td>
                                        <td class="text-end">
                                            <form method="POST" action="{{ route('admin.interventions.delete', $intervention) }}" class="d-inline" onsubmit="return confirm('Delete?')">
                                                @csrf @method('DELETE')
                                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="text-center text-muted py-4">No interventions</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
