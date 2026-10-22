@extends('layouts.app')

@section('title', 'Import Parent Accounts')

@section('content')
    <x-page-header title="Import Parent Accounts" icon="bi-person-plus"
                   :back="route('admin.users')" back-label="Users" />

    @unless(isset($importRows))
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <p class="mb-3">
                    Use this when several parents have no email address — each one gets an account that logs in
                    with their own <strong>full name</strong> instead of an email, printed on a credentials sheet
                    you can hand out. No real inbox is needed; the account is created already verified.
                </p>

                <div class="alert alert-warning small">
                    <strong><i class="bi bi-exclamation-triangle me-1"></i>All accounts share one password</strong>
                    (<code>{{ \App\Http\Controllers\ParentImportController::DEFAULT_PASSWORD }}</code>).
                    Anyone who knows another parent's printed name could log into that parent's account.
                    Only use this for a short, supervised pilot — ask parents to change their password afterward
                    (Profile &rarr; Change Password), or deactivate these accounts once testing ends.
                </div>

                <div class="alert alert-info small">
                    <strong>File columns (first row = header):</strong>
                    <code>Parent Name</code>, <code>Learner LRN</code> (or <code>Learner Name</code> if no LRN yet),
                    <code>Grade Level</code> (e.g. <code>4</code>, or <code>3, 4</code> for a parent with children in two grades),
                    <code>Phone</code> (optional). CSV or Excel (.xlsx).
                    Each parent is assigned to those grade(s): only the teachers of that grade (and the admin) can see
                    or link them. A matched child's own grade is added automatically. Two parents with the exact same name both
                    get accounts — the second one's login gets "(2)" appended, shown on their slip.
                </div>

                <form method="POST" action="{{ route('admin.users.import.preview') }}" enctype="multipart/form-data">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Parent list file</label>
                        <input type="file" class="form-control" name="parents_file" accept=".csv,.txt,.xlsx,.xls" required>
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
                <p class="mb-3">
                    Found {{ count($importRows) }} row(s). Rows are checked by default when they have a matched child
                    or a grade. A row with neither is unchecked: without a grade, no teacher could see that parent.
                    A parent with a grade but no matched child can still be created — the grade's teacher links the
                    child later from the learner's edit page.
                </p>

                <form method="POST" action="{{ route('admin.users.import.confirm') }}">
                    @csrf
                    <div class="table-responsive mb-3">
                        <table class="table table-sm table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th><input type="checkbox" id="checkAll" checked></th>
                                    <th>Parent name</th>
                                    <th>Phone</th>
                                    <th>Child</th>
                                    <th>Grade(s)</th>
                                    <th>Login (full name)</th>
                                    <th>Password</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($importRows as $i => $row)
                                    <tr>
                                        <td>
                                            <input type="checkbox" name="rows[]" value="{{ $i }}"
                                                   {{ ($row['learner_id'] || $row['grades']) ? 'checked' : '' }}>
                                        </td>
                                        <td>{{ $row['name'] }}</td>
                                        <td class="text-muted">{{ $row['phone'] ?? '—' }}</td>
                                        <td>
                                            @if($row['learner_id'])
                                                <span class="badge bg-success-subtle text-success-emphasis">{{ $row['learner_label'] }}</span>
                                                @if($row['already_linked'])
                                                    <span class="badge bg-warning-subtle text-warning-emphasis">already has a parent</span>
                                                @endif
                                            @else
                                                <span class="badge bg-danger-subtle text-danger-emphasis">
                                                    Not found ({{ $row['lrn_or_name'] ?: '—' }})
                                                </span>
                                            @endif
                                        </td>
                                        <td>
                                            @if($row['grades'])
                                                <span class="badge bg-primary-subtle text-primary-emphasis">Grade {{ implode(' & ', $row['grades']) }}</span>
                                                @if($row['grade_note'])
                                                    <div class="small text-warning-emphasis">{{ $row['grade_note'] }}</div>
                                                @endif
                                            @else
                                                <span class="badge bg-danger-subtle text-danger-emphasis">No grade</span>
                                                <div class="small text-muted">No teacher will see this parent until a grade is set.</div>
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
                    <a href="{{ route('admin.users.import') }}" class="btn btn-outline-secondary">Start over</a>
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
