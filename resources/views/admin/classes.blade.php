@extends('layouts.app')

@section('title', 'Classrooms')

@section('content')
@php
    $grouped = $classes->groupBy('grade_level')->sortKeys();
    $gradeColors = [1 => 'info', 2 => 'secondary', 3 => 'primary', 4 => 'success', 5 => 'warning', 6 => 'danger'];
    $reopen = old('_modal');
@endphp

<x-page-header title="Classrooms" icon="bi-diagram-3" :subtitle="$school->name ?? null">
    <x-slot:actions>
        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#classAdd">
            <i class="bi bi-plus-circle me-1"></i> Add Grade &amp; Section
        </button>
        <a href="{{ route('admin.schools') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-building me-1"></i> School
        </a>
    </x-slot:actions>
</x-page-header>

@if($errors->any())
    <div class="alert alert-danger py-2 small">
        @foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach
    </div>
@endif

@if($classes->isEmpty())
    <div class="card border-0 shadow-sm">
        <div class="text-center text-muted py-5">
            <i class="bi bi-diagram-3 display-4 d-block mb-3"></i>
            <h5>No classrooms set up yet</h5>
            <p class="mb-3">Add a grade and section to get started.</p>
            <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#classAdd">
                <i class="bi bi-plus-circle me-1"></i>Add Grade &amp; Section
            </button>
        </div>
    </div>
@else
    <div class="row g-4">
        @foreach($grouped as $grade => $sections)
            @php $color = $gradeColors[$grade] ?? 'secondary'; @endphp
            <div class="col-md-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-{{ $color }} text-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-mortarboard me-2"></i>Grade {{ $grade }}</h5>
                        <span class="badge bg-white text-{{ $color }} rounded-pill">
                            {{ $sections->count() }} section{{ $sections->count() > 1 ? 's' : '' }}
                            &middot; {{ $sections->sum('learners_count') }} learners
                        </span>
                    </div>

                    <div class="card-body p-0">
                        @foreach($sections as $class)
                            <div class="border-bottom px-3 py-3">
                                <div class="d-flex justify-content-between align-items-start gap-2">
                                    <div>
                                        <div class="fw-semibold">
                                            <i class="bi bi-people me-1 text-{{ $color }}"></i>
                                            Section {{ $class->section }}
                                            <span class="badge bg-{{ $color }} bg-opacity-10 text-{{ $color }} ms-1">
                                                {{ $class->learners_count }} learner{{ $class->learners_count != 1 ? 's' : '' }}
                                            </span>
                                        </div>
                                        <div class="small mt-1">
                                            <i class="bi bi-person-video3 me-1 text-muted"></i>
                                            <span class="text-muted">Adviser:</span>
                                            @if($class->teacher)
                                                <strong>{{ $class->teacher->name }}</strong>
                                            @else
                                                <span class="badge bg-warning text-dark">No adviser yet</span>
                                            @endif
                                        </div>
                                        <div class="small text-muted"><i class="bi bi-calendar3 me-1"></i>S.Y. {{ $class->school_year ?? '—' }}</div>
                                    </div>

                                    {{-- Reading level mini-bar --}}
                                    <div class="text-end" style="min-width: 110px;">
                                        @php
                                            $total = $class->learners_count ?: 1;
                                            $indep = $class->learners->where('reading_level','independent')->count();
                                            $instr = $class->learners->where('reading_level','instructional')->count();
                                            $frust = $class->learners->where('reading_level','frustration')->count();
                                        @endphp
                                        <div class="progress" style="height: 8px;" title="Independent / Instructional / Frustration">
                                            <div class="progress-bar bg-success" style="width:{{ round($indep/$total*100) }}%"></div>
                                            <div class="progress-bar bg-warning" style="width:{{ round($instr/$total*100) }}%"></div>
                                            <div class="progress-bar bg-danger"  style="width:{{ round($frust/$total*100) }}%"></div>
                                        </div>
                                        <div class="small text-muted mt-1" style="font-size:0.72rem;">
                                            <span class="text-success">{{ $indep }}</span> /
                                            <span class="text-warning">{{ $instr }}</span> /
                                            <span class="text-danger">{{ $frust }}</span>
                                        </div>
                                    </div>
                                </div>

                                {{-- Actions --}}
                                <div class="d-flex flex-wrap gap-2 mt-2">
                                    <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2"
                                            data-bs-toggle="modal" data-bs-target="#classEdit{{ $class->id }}">
                                        <i class="bi bi-pencil me-1"></i>Edit
                                    </button>
                                    <button class="btn btn-sm btn-outline-secondary py-0 px-2"
                                            data-bs-toggle="collapse" data-bs-target="#learners{{ $class->id }}">
                                        <i class="bi bi-list-ul me-1"></i>Learners
                                    </button>
                                    <button class="btn btn-sm btn-outline-danger py-0 px-2 ms-auto"
                                            data-bs-toggle="collapse" data-bs-target="#danger{{ $class->id }}">
                                        <i class="bi bi-exclamation-triangle me-1"></i>Danger zone
                                    </button>
                                </div>

                                <div class="collapse mt-2" id="learners{{ $class->id }}">
                                    @if($class->learners->isEmpty())
                                        <p class="text-muted small fst-italic mb-0">No learners enrolled in this section.</p>
                                    @else
                                        <ul class="list-group list-group-flush rounded border">
                                            @foreach($class->learners->sortBy(fn($l) => $l->last_name) as $learner)
                                                <li class="list-group-item d-flex justify-content-between align-items-center py-2 px-3 small">
                                                    <span class="text-truncate" style="max-width: 160px;">{{ $learner->getFullName() }}</span>
                                                    @php $lvl = $learner->reading_level; @endphp
                                                    @if($lvl === 'independent')
                                                        <span class="badge bg-success rounded-pill">Independent</span>
                                                    @elseif($lvl === 'instructional')
                                                        <span class="badge bg-warning text-dark rounded-pill">Instructional</span>
                                                    @elseif($lvl === 'frustration')
                                                        <span class="badge bg-danger rounded-pill">Frustration</span>
                                                    @else
                                                        <span class="badge bg-secondary rounded-pill">Not Assessed</span>
                                                    @endif
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </div>

                                {{-- Danger zone: delete this section --}}
                                <div class="collapse mt-2" id="danger{{ $class->id }}">
                                    <div class="border border-danger rounded p-3 bg-danger bg-opacity-10">
                                        <div class="fw-semibold text-danger mb-1"><i class="bi bi-exclamation-triangle me-1"></i>Danger zone</div>
                                        <p class="small mb-2">
                                            Deleting <strong>Grade {{ $class->grade_level }} – {{ $class->section }}</strong> cannot be undone.
                                            @if($class->learners_count)
                                                Its <strong>{{ $class->learners_count }}</strong> learner{{ $class->learners_count == 1 ? '' : 's' }} will be left without a section.
                                            @endif
                                            @if($class->reports_count)
                                                Its <strong>{{ $class->reports_count }}</strong> teacher report{{ $class->reports_count == 1 ? '' : 's' }} will be removed.
                                            @endif
                                            @if($class->teacher)
                                                {{ $class->teacher->name }} will have no section until you assign one.
                                            @endif
                                        </p>
                                        <form method="POST" action="{{ route('admin.classes.delete', $class) }}" class="row g-2 align-items-end">
                                            @csrf @method('DELETE')
                                            <input type="hidden" name="_modal" value="danger-{{ $class->id }}">
                                            <div class="col-sm-7">
                                                <label class="form-label small mb-1">Type <strong>{{ $class->section }}</strong> to confirm</label>
                                                <input type="text" name="confirm" class="form-control form-control-sm" autocomplete="off" required>
                                            </div>
                                            <div class="col-sm-5">
                                                <button type="submit" class="btn btn-sm btn-danger w-100"><i class="bi bi-trash me-1"></i>Delete section</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        @endforeach
    </div>
@endif

{{-- ───── Add Grade & Section ───── --}}
<div class="modal fade" id="classAdd" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="{{ route('admin.classes.store') }}" class="modal-content">
            @csrf
            <input type="hidden" name="_modal" value="add">
            @php $addMine = $reopen === 'add'; @endphp
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-plus-circle me-1 text-primary"></i>Add Grade &amp; Section</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Grade Level <span class="text-danger">*</span></label>
                    <select name="grade_level" class="form-select" required>
                        <option value="">Select grade...</option>
                        @foreach(range(1, 6) as $g)
                            <option value="{{ $g }}" {{ $addMine && old('grade_level') == $g ? 'selected' : '' }}>Grade {{ $g }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Section Name <span class="text-danger">*</span></label>
                    <input type="text" name="section" class="form-control" placeholder="e.g. Sampaguita" maxlength="100"
                           value="{{ $addMine ? old('section') : '' }}" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Adviser / Teacher</label>
                    <select name="teacher_id" class="form-select">
                        <option value="">— Assign later —</option>
                        @foreach($teachers as $t)
                            @php $has = $advising->get($t->id); @endphp
                            <option value="{{ $t->id }}" {{ $has ? 'disabled' : '' }} {{ $addMine && old('teacher_id') == $t->id ? 'selected' : '' }}>
                                {{ $t->name }}@if($has) — advises Gr.{{ $has->grade_level }} {{ $has->section }}@endif
                            </option>
                        @endforeach
                    </select>
                    <div class="form-text">A section has one teacher, and a teacher has one section.</div>
                </div>
                <div>
                    <label class="form-label">School Year</label>
                    <input type="text" name="school_year" class="form-control" value="{{ $addMine ? old('school_year') : $defaultYear }}" placeholder="e.g. 2026-2027" maxlength="20">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i>Add Section</button>
            </div>
        </form>
    </div>
</div>

{{-- ───── Edit modals (one per section) ───── --}}
@foreach($classes as $class)
    @php $mine = $reopen === 'edit-' . $class->id; @endphp
    <div class="modal fade" id="classEdit{{ $class->id }}" tabindex="-1">
        <div class="modal-dialog">
            <form method="POST" action="{{ route('admin.classes.update', $class) }}" class="modal-content">
                @csrf @method('PUT')
                <input type="hidden" name="_modal" value="edit-{{ $class->id }}">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil me-1"></i>Edit Grade {{ $class->grade_level }} – {{ $class->section }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Grade Level <span class="text-danger">*</span></label>
                        <select name="grade_level" class="form-select" required>
                            @foreach(range(1, 6) as $g)
                                <option value="{{ $g }}" {{ ($mine ? old('grade_level') : $class->grade_level) == $g ? 'selected' : '' }}>Grade {{ $g }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Section Name <span class="text-danger">*</span></label>
                        <input type="text" name="section" class="form-control" maxlength="100"
                               value="{{ $mine ? old('section') : $class->section }}" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Adviser / Teacher</label>
                        <select name="teacher_id" class="form-select">
                            <option value="">— No adviser —</option>
                            @foreach($teachers as $t)
                                @php
                                    $has = $advising->get($t->id);
                                    $busy = $has && $has->id !== $class->id;
                                    $selected = ($mine ? old('teacher_id') : $class->teacher_id) == $t->id;
                                @endphp
                                <option value="{{ $t->id }}" {{ $busy ? 'disabled' : '' }} {{ $selected ? 'selected' : '' }}>
                                    {{ $t->name }}@if($busy) — advises Gr.{{ $has->grade_level }} {{ $has->section }}@endif
                                </option>
                            @endforeach
                        </select>
                        <div class="form-text">A section has one teacher, and a teacher has one section.</div>
                    </div>
                    <div>
                        <label class="form-label">School Year</label>
                        <input type="text" name="school_year" class="form-control" maxlength="20"
                               value="{{ $mine ? old('school_year') : $class->school_year }}">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
@endforeach
@endsection

@push('scripts')
<script>
    // After a refused save, reopen whatever the user was working in.
    (function () {
        var where = @json($reopen);
        if (!where) return;
        var el;
        if (where === 'add') el = document.getElementById('classAdd');
        else if (where.indexOf('edit-') === 0) el = document.getElementById('classEdit' + where.slice(5));
        else if (where.indexOf('danger-') === 0) {
            var box = document.getElementById('danger' + where.slice(7));
            if (box) new bootstrap.Collapse(box, { toggle: false }).show();
            return;
        }
        if (el) new bootstrap.Modal(el).show();
    })();
</script>
@endpush
