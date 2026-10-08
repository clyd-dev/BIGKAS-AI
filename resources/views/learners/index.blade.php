@extends('layouts.app')

@section('title', 'Learners')

@section('content')
    @php $canManage = auth()->user()->isTeacher(); @endphp

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-people me-2"></i>Learners</h4>
        @if($canManage)
            <a href="{{ route('learners.create') }}" class="btn btn-primary">
                <i class="bi bi-plus-circle me-1"></i> Add Learner
            </a>
        @endif
    </div>

    {{-- Section summary (click a tile to filter) --}}
    @if($allClasses->isNotEmpty())
        <div class="row g-2 mb-4">
            @foreach($allClasses as $class)
                @php
                    $rows   = $sectionStats->get($class->id, collect());
                    $total  = $rows->sum('total');
                    $by     = $rows->pluck('total', 'reading_level');
                    $active = request('class_id') == $class->id;
                @endphp
                <div class="col-6 col-md-4 col-xl-2">
                    <a href="{{ route('learners.index', $active ? [] : ['class_id' => $class->id]) }}"
                       class="card border-0 shadow-sm h-100 text-decoration-none"
                       @if($active) style="outline: 2px solid var(--bigkas-primary);" @endif>
                        <div class="card-body py-2 px-3">
                            <div class="small text-muted">Grade {{ $class->grade_level }}</div>
                            <div class="fw-semibold text-dark">{{ $class->section }}</div>
                            <div class="small text-muted mb-1">{{ $total }} learner{{ $total === 1 ? '' : 's' }}</div>
                            @if($total > 0)
                                <div class="progress" style="height: 6px;"
                                     title="Independent {{ $by['independent'] ?? 0 }} · Instructional {{ $by['instructional'] ?? 0 }} · Frustration {{ $by['frustration'] ?? 0 }}">
                                    <div class="progress-bar bg-success" style="width: {{ ($by['independent'] ?? 0) / $total * 100 }}%"></div>
                                    <div class="progress-bar bg-warning" style="width: {{ ($by['instructional'] ?? 0) / $total * 100 }}%"></div>
                                    <div class="progress-bar bg-danger" style="width: {{ ($by['frustration'] ?? 0) / $total * 100 }}%"></div>
                                </div>
                            @endif
                        </div>
                    </a>
                </div>
            @endforeach
        </div>
    @endif

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
                            <th>Learner</th>
                            <th>LRN</th>
                            <th>Grade &amp; Section</th>
                            <th>Reading Level</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($learners as $learner)
                            <tr role="link" style="cursor: pointer;"
                                onclick="window.location='{{ route('learners.show', $learner) }}'">
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="rounded-circle bg-primary bg-opacity-10 text-primary fw-semibold d-inline-flex align-items-center justify-content-center"
                                              style="width: 34px; height: 34px; font-size: .8rem;">
                                            {{ strtoupper(mb_substr($learner->first_name, 0, 1) . mb_substr($learner->last_name, 0, 1)) }}
                                        </span>
                                        <a href="{{ route('learners.show', $learner) }}"
                                           class="text-decoration-none fw-semibold">
                                            {{ $learner->getFullName() }}
                                        </a>
                                    </div>
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
                                <td class="text-end text-muted"><i class="bi bi-chevron-right"></i></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">
                                    No learners found.
                                    @if($canManage)
                                        <a href="{{ route('learners.create') }}">Add your first learner</a>
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Pagination --}}
    <div class="d-flex flex-wrap justify-content-between align-items-center mt-3 gap-2">
        <div class="small text-muted">
            @if($learners->total() > 0)
                Showing {{ $learners->firstItem() }}–{{ $learners->lastItem() }} of {{ $learners->total() }}
            @endif
        </div>
        {{ $learners->onEachSide(1)->links('partials.pagination') }}
    </div>

    {{-- Student portal activity and badges (teachers) --}}
    @if($portalLearners)
        @include('learners._student_portal')
    @endif
@endsection
