@extends('layouts.app')

@section('title', 'Group Screening Test')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-ui-checks-grid me-2"></i>Group Screening Test</h4>
        <div class="small text-muted">Grade {{ $class->grade_level }} – {{ $class->section }} · {{ $class->school_year }}</div>
    </div>

    @if(!empty($info))
        <div class="alert alert-info">{{ $info }}</div>
    @endif

    @unless($eligible)
        <div class="alert alert-warning">
            The Phil-IRI Group Screening Test is administered in Grades 3 to 6. Your section is Grade {{ $class->grade_level }}.
        </div>
    @else
        {{-- Period / language --}}
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <form method="GET" action="{{ route('screening.index') }}" class="row g-2 align-items-end">
                    <div class="col-md-5">
                        <label class="form-label small">Period</label>
                        <select name="period" class="form-select form-select-sm" onchange="this.form.submit()">
                            @foreach($periods as $key => $label)
                                <option value="{{ $key }}" {{ $period === $key ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Test language</label>
                        <select name="language" class="form-select form-select-sm" onchange="this.form.submit()">
                            @foreach($languages as $key => $label)
                                <option value="{{ $key }}" {{ $language === $key ? 'selected' : '' }}>
                                    {{ $label }} (Form {{ $key === 'fil' ? '1A' : '1B' }})
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3 d-flex gap-2 justify-content-md-end">
                        <a href="{{ route('screening.record.print', [$class, 'period' => $period, 'language' => $language]) }}" target="_blank" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-printer me-1"></i> Print Form {{ $language === 'fil' ? '1A' : '1B' }}
                        </a>
                        <a href="{{ route('screening.record.pdf', [$class, 'period' => $period, 'language' => $language]) }}" class="btn btn-sm btn-outline-primary">PDF</a>
                    </div>
                </form>
            </div>
        </div>

        {{-- Excel import --}}
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <form method="POST" action="{{ route('screening.import') }}" enctype="multipart/form-data" class="row g-2 align-items-end">
                    @csrf
                    <input type="hidden" name="period" value="{{ $period }}">
                    <input type="hidden" name="language" value="{{ $language }}">
                    <div class="col-md-8">
                        <label class="form-label small">Import from a filled Phil-IRI Form 1A/1B workbook (.xlsx)</label>
                        <input type="file" name="file" accept=".xlsx,.xlsm" class="form-control form-control-sm @error('file') is-invalid @enderror" required>
                        @error('file') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-sm btn-outline-primary w-100">
                            <i class="bi bi-file-earmark-excel me-1"></i> Read File
                        </button>
                    </div>
                    <div class="col-12 small text-muted">
                        The scores are loaded into the table below for you to check. Nothing is saved until you press Save.
                    </div>
                </form>
                @if(!empty($unmatched))
                    <div class="alert alert-warning small mt-3 mb-0">
                        These names in the file did not match a learner in your section and were skipped:
                        <strong>{{ implode(', ', $unmatched) }}</strong>
                    </div>
                @endif
            </div>
        </div>

        {{-- Score entry --}}
        <form method="POST" action="{{ route('screening.store') }}">
            @csrf
            <input type="hidden" name="period" value="{{ $period }}">
            <input type="hidden" name="language" value="{{ $language }}">

            @if($errors->any())
                <div class="alert alert-danger">
                    <ul class="mb-0 small">
                        @foreach($errors->unique() as $error) <li>{{ $error }}</li> @endforeach
                    </ul>
                </div>
            @endif

            <div class="card border-0 shadow-sm">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle" id="gstTable">
                            <thead class="table-light">
                                <tr>
                                    <th>Learner</th>
                                    <th class="text-center">Took test?</th>
                                    <th style="width: 110px;">Literal <span class="text-muted small">/{{ $items['literal'] }}</span></th>
                                    <th style="width: 110px;">Inferential <span class="text-muted small">/{{ $items['inferential'] }}</span></th>
                                    <th style="width: 110px;">Critical <span class="text-muted small">/{{ $items['critical'] }}</span></th>
                                    <th class="text-center">Total /{{ array_sum($items) }}</th>
                                    <th>Result</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($learners as $learner)
                                    @php $e = $entries[$learner->id]; @endphp
                                    <tr data-row>
                                        <td class="fw-semibold">{{ $learner->getFullName() }}</td>
                                        <td class="text-center">
                                            <select name="rows[{{ $learner->id }}][taken]" class="form-select form-select-sm d-inline-block w-auto" data-taken>
                                                <option value="1" {{ $e['taken'] ? 'selected' : '' }}>Yes</option>
                                                <option value="0" {{ ! $e['taken'] ? 'selected' : '' }}>No</option>
                                            </select>
                                        </td>
                                        @foreach(['literal', 'inferential', 'critical'] as $type)
                                            <td>
                                                <input type="number" min="0" max="{{ $items[$type] }}" inputmode="numeric"
                                                       name="rows[{{ $learner->id }}][{{ $type }}]"
                                                       value="{{ old("rows.{$learner->id}.{$type}", $e[$type]) }}"
                                                       class="form-control form-control-sm" data-score data-max="{{ $items[$type] }}">
                                            </td>
                                        @endforeach
                                        <td class="text-center fw-semibold" data-total>—</td>
                                        <td data-result></td>
                                    </tr>
                                @empty
                                    <tr><td colspan="7" class="text-center text-muted py-4">No learners in your section yet.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
                @if($learners->isNotEmpty())
                    <div class="card-footer bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <span class="small text-muted">Score of 14 or more = at grade level. Below 14 = needs the individual oral assessment.</span>
                        <button type="submit" class="btn btn-primary px-4"><i class="bi bi-save me-1"></i> Save Scores</button>
                    </div>
                @endif
            </div>
        </form>

        @push('scripts')
        <script>
            // Live total / result preview; the server recomputes everything on save.
            document.querySelectorAll('[data-row]').forEach(function (row) {
                const taken = row.querySelector('[data-taken]');
                const inputs = row.querySelectorAll('[data-score]');
                const total = row.querySelector('[data-total]');
                const result = row.querySelector('[data-result]');
                const level = {{ (int) $class->grade_level }};

                function update() {
                    const absent = taken.value === '0';
                    inputs.forEach(function (i) { i.disabled = absent; });
                    if (absent) { total.textContent = '—'; result.innerHTML = '<span class="badge bg-secondary">Absent</span>'; return; }

                    const vals = Array.from(inputs).map(function (i) { return i.value === '' ? null : parseInt(i.value, 10); });
                    if (vals.some(function (v) { return v === null || isNaN(v); })) { total.textContent = '—'; result.innerHTML = ''; return; }

                    const sum = vals.reduce(function (a, b) { return a + b; }, 0);
                    total.textContent = sum;
                    if (sum >= {{ \App\Services\GstScoring::PASSING_SCORE }}) {
                        result.innerHTML = '<span class="badge bg-success">At grade level</span>';
                    } else {
                        const start = Math.max(0, sum >= {{ \App\Services\GstScoring::LOW_BAND_FROM }} ? level - 2 : level - 3);
                        result.innerHTML = '<span class="badge bg-warning text-dark">Oral assessment</span> '
                            + '<span class="small text-muted">start: ' + (start === 0 ? 'Kindergarten' : 'Grade ' + start) + '</span>';
                    }
                }
                taken.addEventListener('change', update);
                inputs.forEach(function (i) { i.addEventListener('input', update); });
                update();
            });
        </script>
        @endpush
    @endunless
@endsection
