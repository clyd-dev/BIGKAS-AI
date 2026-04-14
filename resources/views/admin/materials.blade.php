@extends('layouts.app')

@section('title', 'Admin - Materials')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-journal-text me-2"></i>All Reading Materials</h4>
        <div>
            <a href="{{ route('materials.create') }}" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle me-1"></i> Add Material</a>
            <a href="{{ route('admin.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Admin Panel</a>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr><th>Title</th><th>Language</th><th>Grade</th><th>Difficulty</th><th>Words</th><th>Status</th><th>Created</th></tr>
                    </thead>
                    <tbody>
                        @forelse($materials ?? [] as $material)
                            <tr>
                                <td><a href="{{ route('materials.show', $material) }}">{{ $material->title }}</a></td>
                                <td>{{ ucfirst($material->language) }}</td>
                                <td>Grade {{ $material->grade_level }}</td>
                                <td>{{ ucfirst($material->difficulty ?? '-') }}</td>
                                <td>{{ $material->word_count }}</td>
                                <td>
                                    @if($material->is_active)
                                        <span class="badge bg-success">Active</span>
                                    @else
                                        <span class="badge bg-secondary">Inactive</span>
                                    @endif
                                </td>
                                <td>{{ $material->created_at?->format('M d, Y') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-center text-muted py-4">No materials</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
