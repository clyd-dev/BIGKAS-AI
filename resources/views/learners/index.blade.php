@extends('layouts.app')

@section('title', 'Learners')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-people me-2"></i>Learners</h4>
        <a href="{{ route('learners.create') }}" class="btn btn-primary">
            <i class="bi bi-plus-circle me-1"></i> Add Learner
        </a>
    </div>

    {{-- Filters --}}
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" action="{{ route('learners.index') }}" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small">Search</label>
                    <input type="text" name="search" class="form-control form-control-sm" placeholder="Name or LRN..." value="{{ request('search') }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Grade Level</label>
                    <select name="grade_level" class="form-select form-select-sm">
                        <option value="">All Grades</option>
                        @for($i = 1; $i <= 6; $i++)
                            <option value="{{ $i }}" {{ request('grade_level') == $i ? 'selected' : '' }}>Grade {{ $i }}</option>
                        @endfor
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Reading Level</label>
                    <select name="reading_level" class="form-select form-select-sm">
                        <option value="">All Levels</option>
                        <option value="independent" {{ request('reading_level') === 'independent' ? 'selected' : '' }}>Independent</option>
                        <option value="instructional" {{ request('reading_level') === 'instructional' ? 'selected' : '' }}>Instructional</option>
                        <option value="frustration" {{ request('reading_level') === 'frustration' ? 'selected' : '' }}>Frustration</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-sm btn-outline-primary w-100">
                        <i class="bi bi-search me-1"></i> Filter
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- Learners Table --}}
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Name</th>
                            <th>LRN</th>
                            <th>Grade</th>
                            <th>Gender</th>
                            <th>Reading Level</th>
                            <th>Mother Tongue</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($learners ?? [] as $learner)
                            <tr>
                                <td>
                                    <a href="{{ route('learners.show', $learner) }}" class="text-decoration-none fw-semibold">
                                        {{ $learner->full_name }}
                                    </a>
                                </td>
                                <td><code>{{ $learner->lrn ?? '-' }}</code></td>
                                <td>Grade {{ $learner->grade_level }}</td>
                                <td>{{ ucfirst($learner->gender ?? '-') }}</td>
                                <td>
                                    @if($learner->reading_level === 'independent')
                                        <span class="badge bg-success">Independent</span>
                                    @elseif($learner->reading_level === 'instructional')
                                        <span class="badge bg-warning text-dark">Instructional</span>
                                    @elseif($learner->reading_level === 'frustration')
                                        <span class="badge bg-danger">Frustration</span>
                                    @else
                                        <span class="badge bg-secondary">Not Assessed</span>
                                    @endif
                                </td>
                                <td>{{ ucfirst($learner->mother_tongue ?? '-') }}</td>
                                <td class="text-end">
                                    <a href="{{ route('assessments.start', $learner) }}" class="btn btn-sm btn-primary" title="Start Assessment">
                                        <i class="bi bi-mic"></i>
                                    </a>
                                    <a href="{{ route('learners.show', $learner) }}" class="btn btn-sm btn-outline-secondary" title="View">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <a href="{{ route('learners.edit', $learner) }}" class="btn btn-sm btn-outline-warning" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <form method="POST" action="{{ route('learners.destroy', $learner) }}" class="d-inline" onsubmit="return confirm('Are you sure?')">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">
                                    No learners found. <a href="{{ route('learners.create') }}">Add your first learner</a>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Pagination --}}
    @if(method_exists($learners ?? collect(), 'links'))
        <div class="mt-3">{{ $learners->links() }}</div>
    @endif
@endsection
