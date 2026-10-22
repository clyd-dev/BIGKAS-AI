@extends('layouts.app')

@section('title', 'Assessments')

@section('content')
    <x-page-header title="Assessments" icon="bi-clipboard-check">
        <x-slot:actions>
            @if(config('assessment.batch_enabled'))
                {{-- The way to a group board without typing a URL. Only while the
                     feature is on: every batch route 404s with the flag off. --}}
                <a href="{{ route('assessments.batch.index') }}" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-people me-1"></i> Group assessments
                    @if(($openBatches ?? collect())->isNotEmpty())
                        <span class="badge bg-primary ms-1">{{ $openBatches->count() }}</span>
                    @endif
                </a>
            @endif
            @if(auth()->user()->hasAssignedClass())
                <a href="{{ route('assessments.create') }}" class="btn btn-primary btn-sm">
                    <i class="bi bi-plus-circle me-1"></i> New Assessment
                </a>
            @endif
        </x-slot:actions>
    </x-page-header>

    @include('partials.unassigned-teacher')

    {{--
        An unfinished group, named, with the way back to its board.

        This is the whole point of the entry point: a teacher who closed a board
        mid-batch is looking for that board, and a generic link to a list makes
        her hunt for it. So the open ones are named here, newest first.
    --}}
    @foreach(($openBatches ?? collect()) as $openBatch)
        <div class="alert alert-primary d-flex flex-wrap align-items-center justify-content-between gap-2" role="status">
            <div class="d-flex align-items-start gap-2">
                <i class="bi bi-people-fill mt-1"></i>
                <div>
                    <div class="fw-semibold">{{ $openBatch->title ?: 'Group Assessment' }} — still open</div>
                    <div class="small">
                        Started {{ $openBatch->created_at?->format('M d, Y g:ia') }}. Analysis carries on whether
                        or not this page is open.
                    </div>
                </div>
            </div>
            <a href="{{ route('assessments.batch.board', $openBatch) }}" class="btn btn-sm btn-primary">
                <i class="bi bi-grid-3x3-gap me-1"></i> Back to the board
            </a>
        </div>
    @endforeach

    {{-- Filters --}}
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" action="{{ route('assessments.index') }}" class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small">Search</label>
                    <input type="text" name="search" class="form-control form-control-sm"
                           placeholder="Learner name..." value="{{ request('search') }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Reading Level</label>
                    <select name="reading_level" class="form-select form-select-sm">
                        <option value="">All Levels</option>
                        <option value="independent"  {{ request('reading_level') === 'independent'  ? 'selected' : '' }}>Independent</option>
                        <option value="instructional" {{ request('reading_level') === 'instructional' ? 'selected' : '' }}>Instructional</option>
                        <option value="frustration"  {{ request('reading_level') === 'frustration'  ? 'selected' : '' }}>Frustration</option>
                    </select>
                </div>
                <div class="col-md-3 d-flex gap-1">
                    <button type="submit" class="btn btn-sm btn-outline-primary w-100"><i class="bi bi-search me-1"></i> Filter</button>
                    <a href="{{ route('assessments.index') }}" class="btn btn-sm btn-outline-secondary">Clear</a>
                </div>
            </form>
        </div>
    </div>

    {{-- Teacher: one row per learner who has taken at least one assessment --}}
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Learner</th>
                            <th>Summary of Activity</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($learners as $learner)
                            @php $latest = $learner->getLatestAssessment(); @endphp
                            <tr>
                                <td class="fw-semibold">{{ $learner->getFullName() }}</td>
                                <td>
                                    <div class="d-flex align-items-center gap-2 flex-wrap">
                                        <span class="badge bg-secondary">{{ $learner->assessments_count }} assessment{{ $learner->assessments_count === 1 ? '' : 's' }}</span>
                                        @if($latest)
                                            @php $level = $latest->result?->reading_level; @endphp
                                            @if($level === 'independent')
                                                <span class="badge bg-success">Independent</span>
                                            @elseif($level === 'instructional')
                                                <span class="badge bg-warning text-dark">Instructional</span>
                                            @elseif($level === 'frustration')
                                                <span class="badge bg-danger">Frustration</span>
                                            @endif
                                            <span class="small text-muted">Last assessed {{ $latest->created_at?->format('M d, Y') }}</span>
                                        @endif
                                    </div>
                                </td>
                                <td class="text-end">
                                    <a href="{{ route('assessments.learner-history', $learner) }}" class="btn btn-sm btn-primary px-3">
                                        <i class="bi bi-clock-history me-1"></i> View History
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="text-center text-muted py-4">
                                    No assessments found. <a href="{{ route('assessments.create') }}">Start an assessment</a>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Pagination --}}
    @php $paginated = $learners; @endphp
    <div class="d-flex flex-wrap justify-content-between align-items-center mt-3 gap-2">
        <div class="small text-muted">
            @if($paginated->total() > 0)
                Showing {{ $paginated->firstItem() }}–{{ $paginated->lastItem() }} of {{ $paginated->total() }}
            @endif
        </div>
        {{ $paginated->onEachSide(1)->links('partials.pagination') }}
    </div>
@endsection
