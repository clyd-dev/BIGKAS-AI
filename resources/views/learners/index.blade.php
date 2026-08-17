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
                    <input type="text" name="search" class="form-control form-control-sm"
                           placeholder="Name or LRN..." value="{{ request('search') }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Grade &amp; Section</label>
                    <select name="class_id" class="form-select form-select-sm">
                        <option value="">All Sections</option>
                        @foreach($allClasses->groupBy('grade_level') as $grade => $classes)
                            <optgroup label="Grade {{ $grade }}">
                                @foreach($classes as $class)
                                    <option value="{{ $class->id }}"
                                        {{ request('class_id') == $class->id ? 'selected' : '' }}>
                                        {{ $class->section }}
                                    </option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Reading Level</label>
                    <select name="reading_level" class="form-select form-select-sm">
                        <option value="">All Levels</option>
                        <option value="independent"  {{ request('reading_level') === 'independent'  ? 'selected' : '' }}>Independent</option>
                        <option value="instructional" {{ request('reading_level') === 'instructional' ? 'selected' : '' }}>Instructional</option>
                        <option value="frustration"  {{ request('reading_level') === 'frustration'  ? 'selected' : '' }}>Frustration</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex gap-1">
                    <button type="submit" class="btn btn-sm btn-outline-primary w-100">
                        <i class="bi bi-search me-1"></i> Filter
                    </button>
                    <a href="{{ route('learners.index') }}" class="btn btn-sm btn-outline-secondary">Clear</a>
                </div>
            </form>
        </div>
    </div>

    {{-- Learners Table --}}
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Name</th>
                            <th>LRN</th>
                            <th>Grade &amp; Section</th>
                            <th>Gender</th>
                            <th>Reading Level</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($learners as $learner)
                            <tr>
                                <td>
                                    <a href="{{ route('learners.show', $learner) }}"
                                       class="text-decoration-none fw-semibold">
                                        {{ $learner->getFullName() }}
                                    </a>
                                </td>
                                <td><code>{{ $learner->lrn ?? '-' }}</code></td>
                                <td>
                                    @if($learner->schoolClass)
                                        <span class="badge bg-primary bg-opacity-10 text-primary border border-primary-subtle">
                                            Gr.{{ $learner->grade_level }} – {{ $learner->schoolClass->section }}
                                        </span>
                                    @else
                                        <span class="text-muted small">Grade {{ $learner->grade_level }} — no section</span>
                                    @endif
                                </td>
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
                                <td class="text-end">
                                    <a href="{{ route('assessments.start', $learner) }}"
                                       class="btn btn-sm btn-primary" title="Start Assessment">
                                        <i class="bi bi-mic"></i>
                                    </a>
                                    <a href="{{ route('learners.show', $learner) }}"
                                       class="btn btn-sm btn-outline-secondary" title="View">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <a href="{{ route('learners.edit', $learner) }}"
                                       class="btn btn-sm btn-outline-warning" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <form method="POST" action="{{ route('learners.destroy', $learner) }}"
                                          class="d-inline" onsubmit="return confirm('Remove this learner?')">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">
                                    No learners found.
                                    <a href="{{ route('learners.create') }}">Add your first learner</a>
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
