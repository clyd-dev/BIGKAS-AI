@extends('layouts.app')

@section('title', 'Import Teacher Accounts')

@section('content')
    <x-page-header title="Import Teacher Accounts" icon="bi-person-plus"
                   :back="route('admin.users')" back-label="Users" />

    @unless(isset($importRows))
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <p class="mb-3">
                    Create many adviser accounts at once. Each teacher logs in with their own <strong>full name</strong>
                    instead of an email, printed on a credentials sheet you can hand out. The account is created already verified.
                </p>

                <div class="alert alert-warning small">
                    <strong><i class="bi bi-exclamation-triangle me-1"></i>All accounts share one password</strong>
                    (<code>{{ \App\Http\Controllers\ParentImportController::DEFAULT_PASSWORD }}</code>).
                    Anyone who knows another teacher's printed name could log into that account, and a teacher can see
                    their learners' records. Only use this for a short, supervised pilot — ask teachers to change their
                    password afterward (Profile &rarr; Change Password), or deactivate these accounts once testing ends.
                </div>

                <div class="alert alert-info small">
                    <strong>File columns (first row = header):</strong>
                    <code>Teacher Name</code>, <code>Grade Level</code> (e.g. <code>4</code>), <code>Section</code>
                    (e.g. <code>Rizal</code>), <code>Phone</code> (optional). CSV or Excel (.xlsx).
                    The teacher is assigned to that grade &amp; section when it already exists and has no teacher yet
                    (a section has only one teacher). Otherwise the account is still created, and you can assign a section
                    from Manage Users afterward. Two teachers with the exact same name both get accounts — the second
                    login gets "(2)" appended, shown on their slip.
                </div>

                <form method="POST" action="{{ route('admin.teachers.import.preview') }}" enctype="multipart/form-data">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Teacher list file</label>
                        <input type="file" class="form-control" name="teachers_file" accept=".csv,.txt,.xlsx,.xls" required>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-upload me-1"></i> Upload and review
                    </button>
                </form>
            </div>
        </div>
    @else
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <p class="mb-3">Found {{ count($importRows) }} row(s). Untick any you don't want created.</p>

                <form method="POST" action="{{ route('admin.teachers.import.confirm') }}">
                    @csrf
                    <div class="table-responsive mb-3">
                        <table class="table table-sm table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th><input type="checkbox" id="checkAll" checked></th>
                                    <th>Teacher name</th>
                                    <th>Phone</th>
                                    <th>Grade &amp; section</th>
                                    <th>Login (full name)</th>
                                    <th>Password</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($importRows as $i => $row)
                                    <tr>
                                        <td><input type="checkbox" name="rows[]" value="{{ $i }}" checked></td>
                                        <td>{{ $row['name'] }}</td>
                                        <td class="text-muted">{{ $row['phone'] ?? '—' }}</td>
                                        <td>
                                            @if($row['class_label'])
                                                <span class="badge bg-success-subtle text-success-emphasis">{{ $row['class_label'] }}</span>
                                            @else
                                                <span class="badge bg-warning-subtle text-warning-emphasis">No section yet</span>
                                                @if($row['class_note'])
                                                    <div class="small text-muted">{{ $row['class_note'] }}</div>
                                                @endif
                                            @endif
                                        </td>
                                        <td class="small font-monospace">{{ $row['login'] }}</td>
                                        <td class="small font-monospace">{{ $row['password'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-check-lg me-1"></i> Create checked accounts
                    </button>
                    <a href="{{ route('admin.teachers.import') }}" class="btn btn-outline-secondary">Start over</a>
                </form>
            </div>
        </div>

        @push('scripts')
        <script>
            document.getElementById('checkAll')?.addEventListener('change', function (e) {
                document.querySelectorAll('input[name="rows[]"]').forEach(cb => cb.checked = e.target.checked);
            });
        </script>
        @endpush
    @endunless
@endsection
