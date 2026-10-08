@extends('layouts.app')

@section('title', 'Add Learner')

@section('content')
    <x-page-header title="Add New Learner" icon="bi-person-plus"
                   :back="route('learners.index')" back-label="Learners" />

    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    {{-- SF1 Bulk Import --}}
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white d-flex align-items-center">
            <i class="bi bi-file-earmark-spreadsheet me-2"></i>
            <h6 class="mb-0">Import from SF1</h6>
        </div>
        <div class="card-body">
            @isset($importError)
                <div class="alert alert-danger">{{ $importError }}</div>
            @endisset

            @isset($importRows)
                {{-- Review step: show parsed rows for the teacher to confirm --}}
                <form method="POST" action="{{ route('learners.import.confirm') }}">
                    @csrf
                    <p class="text-muted small mb-3">
                        Found {{ count($importRows) }} learner row(s). Rows that already match an existing learner
                        are unchecked by default — review and confirm which ones to import.
                    </p>

                    @if(isset($lockedClass) && $lockedClass)
                        <p class="small mb-3">
                            Importing into <strong>Grade {{ $lockedClass->grade_level }} – {{ $lockedClass->section }}</strong>.
                        </p>
                    @else
                        <div class="mb-3 col-md-4">
                            <label class="form-label small">Target Class / Section</label>
                            <select class="form-select form-select-sm" name="class_id">
                                <option value="">Select class</option>
                                @foreach(($allClasses ?? collect())->groupBy('grade_level') as $grade => $classes)
                                    <optgroup label="Grade {{ $grade }}">
                                        @foreach($classes as $class)
                                            <option value="{{ $class->id }}">{{ $class->section }}</option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                        </div>
                    @endif

                    <div class="table-responsive mb-3">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th style="width:40px"></th>
                                    <th>LRN</th>
                                    <th>Last Name</th>
                                    <th>First Name</th>
                                    <th>Middle Name</th>
                                    <th>Birth Date</th>
                                    <th>Gender</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($importRows as $i => $row)
                                    <tr>
                                        <td>
                                            <input type="checkbox" class="form-check-input" name="rows[]" value="{{ $i }}"
                                                   {{ $row['is_duplicate'] ? '' : 'checked' }}>
                                        </td>
                                        <td>{{ $row['lrn'] ?: '-' }}</td>
                                        <td>{{ $row['last_name'] }}</td>
                                        <td>{{ $row['first_name'] }}</td>
                                        <td>{{ $row['middle_name'] ?: '-' }}</td>
                                        <td>{{ $row['birth_date'] ?: '-' }}</td>
                                        <td>{{ $row['gender'] ? ucfirst($row['gender']) : '-' }}</td>
                                        <td>
                                            @if($row['is_duplicate'])
                                                <span class="badge bg-warning text-dark">Possible duplicate</span>
                                            @else
                                                <span class="badge bg-success">New</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle me-1"></i> Confirm Import
                    </button>
                    <a href="{{ route('learners.create') }}" class="btn btn-outline-secondary ms-2">Cancel</a>
                </form>
            @else
                <form method="POST" action="{{ route('learners.import.preview') }}" enctype="multipart/form-data" class="row g-2 align-items-end">
                    @csrf
                    <div class="col-md-6">
                        <label class="form-label small">SF1 Excel File (.xlsx)</label>
                        <input type="file" class="form-control form-control-sm" name="sf1_file" accept=".xlsx,.xls" required>
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-upload me-1"></i> Upload &amp; Preview
                        </button>
                    </div>
                </form>
                <div class="form-text mt-2">
                    Upload a School Form 1 (SF1) export to add multiple learners at once instead of entering them one by one.
                </div>
            @endisset
        </div>
    </div>

    {{-- Manual Entry --}}
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white">
            <h6 class="mb-0">Or Add Individually</h6>
        </div>
        <div class="card-body">
            <form method="POST" action="{{ route('learners.store') }}">
                @csrf
                @include('learners._form')

                <div class="mt-4">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle me-1"></i> Save Learner
                    </button>
                    <a href="{{ route('learners.index') }}" class="btn btn-outline-secondary ms-2">Cancel</a>
                </div>
            </form>
        </div>
    </div>
@endsection
