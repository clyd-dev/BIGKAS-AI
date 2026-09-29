@extends('layouts.app')

@section('title', 'Phil-IRI Reading Profile')

@section('content')
    {{-- Header --}}
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="bi bi-journal-bookmark-fill me-2"></i>Phil-IRI Reading Profile</h4>
            <p class="text-muted mb-0 small">
                <i class="bi bi-building me-1"></i>{{ $schoolName }}
                @if($schoolYear)
                    <span class="ms-2"><i class="bi bi-calendar3 me-1"></i>S.Y. {{ $schoolYear }}</span>
                @endif
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.index') }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i>Admin Panel
            </a>
        </div>
    </div>

    {{-- Filters --}}
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body py-3">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small text-muted mb-1">School Year</label>
                    <select name="school_year" class="form-select form-select-sm">
                        @foreach($schoolYears as $sy)
                            <option value="{{ $sy }}" {{ $schoolYear == $sy ? 'selected' : '' }}>S.Y. {{ $sy }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small text-muted mb-1">Grade Level</label>
                    <select name="grade_level" class="form-select form-select-sm">
                        <option value="">All Grades</option>
                        @for($g = 3; $g <= 6; $g++)
                            <option value="{{ $g }}" {{ $gradeFilter == $g ? 'selected' : '' }}>Grade {{ $g }}</option>
                        @endfor
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="bi bi-funnel me-1"></i>Filter
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- Summary Stats --}}
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center py-3">
                    <div class="display-6 fw-bold text-danger">{{ $totals['frustration'] }}</div>
                    <small class="text-muted">Frustration</small>
                    @if($totals['total'] > 0)
                        <div class="small text-danger">{{ round($totals['frustration'] / $totals['total'] * 100, 1) }}%</div>
                    @endif
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center py-3">
                    <div class="display-6 fw-bold text-warning">{{ $totals['instructional'] }}</div>
                    <small class="text-muted">Instructional</small>
                    @if($totals['total'] > 0)
                        <div class="small text-warning">{{ round($totals['instructional'] / $totals['total'] * 100, 1) }}%</div>
                    @endif
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center py-3">
                    <div class="display-6 fw-bold text-success">{{ $totals['independent'] }}</div>
                    <small class="text-muted">Independent</small>
                    @if($totals['total'] > 0)
                        <div class="small text-success">{{ round($totals['independent'] / $totals['total'] * 100, 1) }}%</div>
                    @endif
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center py-3">
                    <div class="display-6 fw-bold text-secondary">{{ $totals['not_assessed'] }}</div>
                    <small class="text-muted">Not Assessed</small>
                    @if($totals['total'] > 0)
                        <div class="small text-secondary">{{ round($totals['not_assessed'] / $totals['total'] * 100, 1) }}%</div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- ═══════════════════════════════════════════════════ --}}
    {{-- FORM 4: School Reading Profile Matrix              --}}
    {{-- ═══════════════════════════════════════════════════ --}}
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <h6 class="mb-0"><i class="bi bi-table me-2"></i>Form 4 &mdash; School Reading Profile</h6>
            <span class="badge bg-white text-primary">{{ $totals['total'] }} Learners</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered table-hover table-sm mb-0 align-middle text-center">
                    <thead class="table-light">
                        <tr>
                            <th class="text-start" style="min-width: 80px;">Grade</th>
                            <th class="text-start" style="min-width: 100px;">Section</th>
                            <th class="text-start" style="min-width: 130px;">Teacher</th>
                            <th style="min-width: 60px;">Total</th>
                            <th style="min-width: 90px;">
                                <span class="text-danger"><i class="bi bi-exclamation-triangle me-1"></i>Frust.</span>
                            </th>
                            <th style="min-width: 90px;">
                                <span class="text-warning"><i class="bi bi-dash-circle me-1"></i>Instr.</span>
                            </th>
                            <th style="min-width: 90px;">
                                <span class="text-success"><i class="bi bi-check-circle me-1"></i>Indep.</span>
                            </th>
                            <th style="min-width: 90px;">
                                <span class="text-secondary"><i class="bi bi-question-circle me-1"></i>N/A</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        @php $prevGrade = null; @endphp
                        @forelse($form4 as $row)
                            {{-- Grade subtotal row before new grade --}}
                            @if($prevGrade !== null && $prevGrade !== $row['grade_level'] && isset($gradeTotals[$prevGrade]))
                                @php $gt = $gradeTotals[$prevGrade]; @endphp
                                <tr class="table-secondary fw-bold">
                                    <td colspan="3" class="text-end">Grade {{ $prevGrade }} Subtotal</td>
                                    <td>{{ $gt['total'] }}</td>
                                    <td class="text-danger">
                                        {{ $gt['frustration'] }}
                                        @if($gt['total'] > 0)
                                            <small>({{ round($gt['frustration'] / $gt['total'] * 100) }}%)</small>
                                        @endif
                                    </td>
                                    <td class="text-warning">
                                        {{ $gt['instructional'] }}
                                        @if($gt['total'] > 0)
                                            <small>({{ round($gt['instructional'] / $gt['total'] * 100) }}%)</small>
                                        @endif
                                    </td>
                                    <td class="text-success">
                                        {{ $gt['independent'] }}
                                        @if($gt['total'] > 0)
                                            <small>({{ round($gt['independent'] / $gt['total'] * 100) }}%)</small>
                                        @endif
                                    </td>
                                    <td class="text-secondary">
                                        {{ $gt['not_assessed'] }}
                                    </td>
                                </tr>
                            @endif

                            <tr>
                                <td class="text-start fw-semibold">
                                    @if($prevGrade !== $row['grade_level'])
                                        Grade {{ $row['grade_level'] }}
                                    @endif
                                </td>
                                <td class="text-start">
                                    <a href="#section-{{ $row['class_id'] }}" class="text-decoration-none">
                                        {{ $row['section'] }}
                                    </a>
                                </td>
                                <td class="text-start small">{{ $row['teacher'] }}</td>
                                <td><strong>{{ $row['total'] }}</strong></td>
                                <td>
                                    @if($row['frustration'] > 0)
                                        <span class="badge bg-danger">{{ $row['frustration'] }}</span>
                                    @else
                                        <span class="text-muted">0</span>
                                    @endif
                                </td>
                                <td>
                                    @if($row['instructional'] > 0)
                                        <span class="badge bg-warning text-dark">{{ $row['instructional'] }}</span>
                                    @else
                                        <span class="text-muted">0</span>
                                    @endif
                                </td>
                                <td>
                                    @if($row['independent'] > 0)
                                        <span class="badge bg-success">{{ $row['independent'] }}</span>
                                    @else
                                        <span class="text-muted">0</span>
                                    @endif
                                </td>
                                <td>
                                    @if($row['not_assessed'] > 0)
                                        <span class="badge bg-secondary">{{ $row['not_assessed'] }}</span>
                                    @else
                                        <span class="text-muted">0</span>
                                    @endif
                                </td>
                            </tr>
                            @php $prevGrade = $row['grade_level']; @endphp
                        @empty
                            <tr>
                                <td colspan="8" class="text-center text-muted py-4">
                                    <i class="bi bi-inbox display-6 d-block mb-2"></i>
                                    No classes found for the selected filters.
                                </td>
                            </tr>
                        @endforelse

                        {{-- Last grade subtotal --}}
                        @if($prevGrade !== null && isset($gradeTotals[$prevGrade]))
                            @php $gt = $gradeTotals[$prevGrade]; @endphp
                            <tr class="table-secondary fw-bold">
                                <td colspan="3" class="text-end">Grade {{ $prevGrade }} Subtotal</td>
                                <td>{{ $gt['total'] }}</td>
                                <td class="text-danger">
                                    {{ $gt['frustration'] }}
                                    @if($gt['total'] > 0)
                                        <small>({{ round($gt['frustration'] / $gt['total'] * 100) }}%)</small>
                                    @endif
                                </td>
                                <td class="text-warning">
                                    {{ $gt['instructional'] }}
                                    @if($gt['total'] > 0)
                                        <small>({{ round($gt['instructional'] / $gt['total'] * 100) }}%)</small>
                                    @endif
                                </td>
                                <td class="text-success">
                                    {{ $gt['independent'] }}
                                    @if($gt['total'] > 0)
                                        <small>({{ round($gt['independent'] / $gt['total'] * 100) }}%)</small>
                                    @endif
                                </td>
                                <td class="text-secondary">
                                    {{ $gt['not_assessed'] }}
                                </td>
                            </tr>
                        @endif

                        {{-- Grand total --}}
                        @if($totals['total'] > 0)
                            <tr class="table-dark fw-bold">
                                <td colspan="3" class="text-end">SCHOOL TOTAL</td>
                                <td>{{ $totals['total'] }}</td>
                                <td class="text-danger">
                                    {{ $totals['frustration'] }}
                                    <small>({{ round($totals['frustration'] / $totals['total'] * 100) }}%)</small>
                                </td>
                                <td class="text-warning">
                                    {{ $totals['instructional'] }}
                                    <small>({{ round($totals['instructional'] / $totals['total'] * 100) }}%)</small>
                                </td>
                                <td class="text-success">
                                    {{ $totals['independent'] }}
                                    <small>({{ round($totals['independent'] / $totals['total'] * 100) }}%)</small>
                                </td>
                                <td class="text-secondary">
                                    {{ $totals['not_assessed'] }}
                                </td>
                            </tr>
                        @endif
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Reading Level Distribution Chart --}}
    @if($totals['total'] > 0)
        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white">
                        <h6 class="mb-0"><i class="bi bi-pie-chart me-2"></i>School-Wide Distribution</h6>
                    </div>
                    <div class="card-body d-flex align-items-center justify-content-center">
                        <canvas id="schoolPieChart" style="max-height: 280px;"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white">
                        <h6 class="mb-0"><i class="bi bi-bar-chart me-2"></i>Per-Grade Breakdown</h6>
                    </div>
                    <div class="card-body d-flex align-items-center justify-content-center">
                        <canvas id="gradeBarChart" style="max-height: 280px;"></canvas>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- ═══════════════════════════════════════════════════ --}}
    {{-- FORM 3A: Individual Learner Reading Profile        --}}
    {{-- ═══════════════════════════════════════════════════ --}}
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-success text-white">
            <h6 class="mb-0"><i class="bi bi-person-lines-fill me-2"></i>Form 3A &mdash; Individual Learner Reading Profile</h6>
        </div>
        <div class="card-body p-0">
            <div class="accordion" id="form3aAccordion">
                @forelse($form3a as $sectionId => $data)
                    @php $classInfo = $data['class']; $learners = $data['learners']; @endphp
                    <div class="accordion-item" id="section-{{ $classInfo['class_id'] }}">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button"
                                    data-bs-toggle="collapse" data-bs-target="#collapse-{{ $sectionId }}">
                                <span class="me-3">
                                    <strong>Grade {{ $classInfo['grade_level'] }} - {{ $classInfo['section'] }}</strong>
                                </span>
                                <span class="me-3 small text-muted">{{ $classInfo['teacher'] }}</span>
                                <span class="ms-auto me-3 d-flex gap-1">
                                    @if($classInfo['frustration'] > 0)
                                        <span class="badge bg-danger">{{ $classInfo['frustration'] }} F</span>
                                    @endif
                                    @if($classInfo['instructional'] > 0)
                                        <span class="badge bg-warning text-dark">{{ $classInfo['instructional'] }} I</span>
                                    @endif
                                    @if($classInfo['independent'] > 0)
                                        <span class="badge bg-success">{{ $classInfo['independent'] }} Ind</span>
                                    @endif
                                    @if($classInfo['not_assessed'] > 0)
                                        <span class="badge bg-secondary">{{ $classInfo['not_assessed'] }} N/A</span>
                                    @endif
                                </span>
                            </button>
                        </h2>
                        <div id="collapse-{{ $sectionId }}" class="accordion-collapse collapse"
                             data-bs-parent="#form3aAccordion">
                            <div class="accordion-body p-0">
                                @if(count($learners) > 0)
                                    <div class="table-responsive">
                                        <table class="table table-sm table-hover mb-0 align-middle">
                                            <thead class="table-light">
                                                <tr>
                                                    <th style="width: 40px;">#</th>
                                                    <th class="text-start">Learner Name</th>
                                                    <th style="width: 50px;">Sex</th>
                                                    <th style="width: 110px;">Reading Level</th>
                                                    <th style="width: 80px;">Accuracy</th>
                                                    <th style="width: 70px;">WPM</th>
                                                    <th style="width: 70px;">Errors</th>
                                                    <th style="width: 110px;">Primary Weakness</th>
                                                    <th style="width: 100px;">Last Assessed</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach($learners as $i => $l)
                                                    <tr>
                                                        <td class="text-muted small">{{ $i + 1 }}</td>
                                                        <td>
                                                            <a href="{{ route('learners.show', $l['id']) }}" class="text-decoration-none fw-semibold">
                                                                {{ $l['full_name'] }}
                                                            </a>
                                                            @if($l['lrn'])
                                                                <br><small class="text-muted">LRN: {{ $l['lrn'] }}</small>
                                                            @endif
                                                        </td>
                                                        <td class="text-center">
                                                            @if($l['gender'] === 'male')
                                                                <i class="bi bi-gender-male text-primary" title="Male"></i>
                                                            @elseif($l['gender'] === 'female')
                                                                <i class="bi bi-gender-female text-danger" title="Female"></i>
                                                            @else
                                                                <span class="text-muted">-</span>
                                                            @endif
                                                        </td>
                                                        <td class="text-center">
                                                            @if($l['reading_level'] === 'frustration')
                                                                <span class="badge bg-danger">Frustration</span>
                                                            @elseif($l['reading_level'] === 'instructional')
                                                                <span class="badge bg-warning text-dark">Instructional</span>
                                                            @elseif($l['reading_level'] === 'independent')
                                                                <span class="badge bg-success">Independent</span>
                                                            @else
                                                                <span class="badge bg-secondary">Not Assessed</span>
                                                            @endif
                                                        </td>
                                                        <td class="text-center">
                                                            @if($l['accuracy'] !== null)
                                                                <span class="{{ $l['accuracy'] >= 97 ? 'text-success' : ($l['accuracy'] >= 90 ? 'text-warning' : 'text-danger') }} fw-semibold">
                                                                    {{ number_format($l['accuracy'], 1) }}%
                                                                </span>
                                                            @else
                                                                <span class="text-muted">-</span>
                                                            @endif
                                                        </td>
                                                        <td class="text-center">
                                                            @if($l['wpm'] !== null)
                                                                {{ number_format($l['wpm'], 0) }}
                                                            @else
                                                                <span class="text-muted">-</span>
                                                            @endif
                                                        </td>
                                                        <td class="text-center">
                                                            @if($l['errors'] !== null)
                                                                @if($l['errors'] > 10)
                                                                    <span class="text-danger fw-semibold">{{ $l['errors'] }}</span>
                                                                @elseif($l['errors'] > 5)
                                                                    <span class="text-warning">{{ $l['errors'] }}</span>
                                                                @else
                                                                    {{ $l['errors'] }}
                                                                @endif
                                                            @else
                                                                <span class="text-muted">-</span>
                                                            @endif
                                                        </td>
                                                        <td class="text-center small">
                                                            @if($l['primary_weakness'] !== null)
                                                                @php
                                                                    $weaknesses = config('bigkas.weakness_categories', []);
                                                                    $wk = $weaknesses[$l['primary_weakness']] ?? null;
                                                                @endphp
                                                                @if($wk)
                                                                    <span class="badge bg-light text-dark border" title="{{ $wk['description'] }}">
                                                                        {{ $wk['code'] }}
                                                                    </span>
                                                                @else
                                                                    <span class="text-muted">-</span>
                                                                @endif
                                                            @else
                                                                <span class="text-muted">-</span>
                                                            @endif
                                                        </td>
                                                        <td class="text-center small text-muted">
                                                            @if($l['assessed_at'])
                                                                {{ $l['assessed_at']->format('M d, Y') }}
                                                            @else
                                                                <span class="text-muted">-</span>
                                                            @endif
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                            <tfoot class="table-light">
                                                <tr class="fw-semibold small">
                                                    <td colspan="3" class="text-end">Section Summary:</td>
                                                    <td colspan="6">
                                                        <span class="badge bg-danger me-1">{{ $classInfo['frustration'] }} Frustration</span>
                                                        <span class="badge bg-warning text-dark me-1">{{ $classInfo['instructional'] }} Instructional</span>
                                                        <span class="badge bg-success me-1">{{ $classInfo['independent'] }} Independent</span>
                                                        <span class="badge bg-secondary">{{ $classInfo['not_assessed'] }} Not Assessed</span>
                                                    </td>
                                                </tr>
                                            </tfoot>
                                        </table>
                                    </div>
                                @else
                                    <div class="text-center text-muted py-4">
                                        <i class="bi bi-people display-6 d-block mb-2"></i>
                                        No learners enrolled in this section.
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="text-center text-muted py-5">
                        <i class="bi bi-inbox display-4 d-block mb-2"></i>
                        No class data available for the selected filters.
                    </div>
                @endforelse
            </div>
        </div>
    </div>

    {{-- Legend --}}
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white">
            <h6 class="mb-0"><i class="bi bi-info-circle me-2"></i>Phil-IRI Reading Level Legend</h6>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <div class="d-flex align-items-start gap-2">
                        <span class="badge bg-danger mt-1">F</span>
                        <div>
                            <strong>Frustration Level</strong>
                            <p class="small text-muted mb-0">Accuracy below 90%. Reading material is too difficult. The learner needs easier material and intensive support.</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="d-flex align-items-start gap-2">
                        <span class="badge bg-warning text-dark mt-1">I</span>
                        <div>
                            <strong>Instructional Level</strong>
                            <p class="small text-muted mb-0">Accuracy 90&ndash;96%. Appropriate for guided reading. The learner benefits from teacher-assisted instruction.</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="d-flex align-items-start gap-2">
                        <span class="badge bg-success mt-1">Ind</span>
                        <div>
                            <strong>Independent Level</strong>
                            <p class="small text-muted mb-0">Accuracy 97&ndash;100%. The learner can read the material independently without teacher assistance.</p>
                        </div>
                    </div>
                </div>
            </div>
            <hr class="my-3">
            <div class="row g-2">
                <div class="col-12">
                    <small class="text-muted"><strong>Weakness Codes:</strong>
                        PHONEMIC = Phonemic Awareness |
                        DECODING = Decoding Accuracy |
                        FLUENCY = Oral Reading Fluency |
                        COMPREHENSION = Reading Comprehension
                    </small>
                </div>
            </div>
        </div>
    </div>

    @if($totals['total'] > 0)
        @push('scripts')
            <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    // ── Pie Chart: School-Wide Distribution ──
                    const pieCtx = document.getElementById('schoolPieChart');
                    if (pieCtx) {
                        new Chart(pieCtx, {
                            type: 'doughnut',
                            data: {
                                labels: ['Frustration', 'Instructional', 'Independent', 'Not Assessed'],
                                datasets: [{
                                    data: [{{ $totals['frustration'] }}, {{ $totals['instructional'] }}, {{ $totals['independent'] }}, {{ $totals['not_assessed'] }}],
                                    backgroundColor: ['#dc3545', '#ffc107', '#28a745', '#6c757d'],
                                    borderWidth: 2,
                                    borderColor: '#fff'
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: true,
                                plugins: {
                                    legend: { position: 'bottom', labels: { padding: 15, usePointStyle: true } }
                                }
                            }
                        });
                    }

                    // ── Bar Chart: Per-Grade Breakdown ──
                    const barCtx = document.getElementById('gradeBarChart');
                    if (barCtx) {
                        const gradeTotals = @json($gradeTotals);
                        const grades = Object.keys(gradeTotals).sort();
                        const labels = grades.map(g => 'Grade ' + g);

                        new Chart(barCtx, {
                            type: 'bar',
                            data: {
                                labels: labels,
                                datasets: [
                                    {
                                        label: 'Frustration',
                                        data: grades.map(g => gradeTotals[g].frustration),
                                        backgroundColor: '#dc3545'
                                    },
                                    {
                                        label: 'Instructional',
                                        data: grades.map(g => gradeTotals[g].instructional),
                                        backgroundColor: '#ffc107'
                                    },
                                    {
                                        label: 'Independent',
                                        data: grades.map(g => gradeTotals[g].independent),
                                        backgroundColor: '#28a745'
                                    },
                                    {
                                        label: 'Not Assessed',
                                        data: grades.map(g => gradeTotals[g].not_assessed),
                                        backgroundColor: '#6c757d'
                                    }
                                ]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: true,
                                scales: {
                                    x: { stacked: true },
                                    y: { stacked: true, beginAtZero: true, ticks: { stepSize: 1 } }
                                },
                                plugins: {
                                    legend: { position: 'bottom', labels: { padding: 15, usePointStyle: true } }
                                }
                            }
                        });
                    }

                    // ── Auto-open accordion if navigated via anchor ──
                    const hash = window.location.hash;
                    if (hash && hash.startsWith('#section-')) {
                        const target = document.querySelector(hash);
                        if (target) {
                            const collapseEl = target.querySelector('.accordion-collapse');
                            if (collapseEl) {
                                const bsCollapse = new bootstrap.Collapse(collapseEl, { show: true });
                            }
                            setTimeout(() => target.scrollIntoView({ behavior: 'smooth', block: 'start' }), 300);
                        }
                    }
                });
            </script>
        @endpush
    @endif
@endsection
