@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    @php
        $tiles = [
            ['Users',          $stats['total_users'],         'bi-people',         'primary', route('admin.users'),     'Manage users'],
            ['Learners',       $stats['total_learners'],      'bi-person-hearts',  'success', route('learners.index'),  'View learners'],
            ['Assessments',    $stats['total_assessments'],   'bi-clipboard-check','info',    route('assessments.index'), 'View assessments'],
            ['Materials',      $stats['total_materials'],     'bi-journal-text',   'warning', route('admin.materials'), 'View materials'],
            ['Interventions',  $stats['total_interventions'], 'bi-lightbulb',      'secondary', route('admin.interventions'), 'Manage interventions'],
        ];
    @endphp

    <x-page-header title="Dashboard" icon="bi-speedometer2">
        <x-slot:meta>
            <p class="pg-sub mb-0">
                Welcome back, {{ auth()->user()->first_name ?? auth()->user()->name ?? 'Admin' }}.
                @if($school) <i class="bi bi-building ms-2 me-1"></i>{{ $school->name }} @endif
                <span class="ms-2"><i class="bi bi-calendar3 me-1"></i>{{ now()->format('l, F j, Y') }}</span>
            </p>
        </x-slot:meta>
        <x-slot:actions>
            <a href="{{ route('reports.form2.index') }}" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-file-earmark-check me-1"></i> DepEd Form 2
            </a>
            <a href="{{ route('admin.phil-iri') }}" class="btn btn-primary btn-sm">
                <i class="bi bi-journal-bookmark-fill me-1"></i> Phil-IRI Forms
            </a>
        </x-slot:actions>
    </x-page-header>

    {{-- Key counts, each linking to where it is managed --}}
    <div class="row g-3 mb-4">
        @foreach($tiles as [$label, $value, $icon, $color, $url, $linkText])
            <div class="col-6 col-md-4 col-xl">
                <a href="{{ $url }}" class="card border-0 shadow-sm h-100 text-decoration-none">
                    <div class="card-body py-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <small class="text-muted fw-bold text-uppercase">{{ $label }}</small>
                            <span class="bg-{{ $color }} bg-opacity-10 text-{{ $color }} rounded d-flex align-items-center justify-content-center" style="width: 36px; height: 36px;">
                                <i class="bi {{ $icon }} fs-5"></i>
                            </span>
                        </div>
                        <div class="display-6 fw-bold text-dark">{{ number_format($value) }}</div>
                        <div class="small text-muted mt-1">{{ $linkText }} <i class="bi bi-arrow-right"></i></div>
                    </div>
                </a>
            </div>
        @endforeach
    </div>

    {{-- ═══ NEEDS YOUR ATTENTION (compact, sits under the count tiles) ═══ --}}
    @php
        $palette = ['danger' => 'danger', 'warning' => 'warning', 'info' => 'primary'];
        $actionable = $attention->whereIn('severity', ['danger', 'warning'])->count();
    @endphp
    <section class="card border-0 shadow-sm mb-4" style="border-left: 4px solid var(--bs-{{ $actionable ? 'danger' : ($attention->isNotEmpty() ? 'primary' : 'success') }}) !important;" aria-labelledby="attentionTitle">
        <div class="card-header bg-white border-0 py-2 d-flex justify-content-between align-items-center gap-2">
            <h2 class="h6 mb-0 fw-semibold" id="attentionTitle"><i class="bi bi-bell-fill text-warning me-2"></i>Needs your attention</h2>
            <span id="attentionCount" class="badge rounded-pill bg-{{ $actionable ? 'danger' : ($attention->isNotEmpty() ? 'secondary' : 'success') }}">
                @if($actionable) {{ $actionable }} to do @elseif($attention->isNotEmpty()) Nothing urgent @else All clear @endif
            </span>
        </div>
        <div class="card-body pt-0 pb-3">
            <div class="row g-2" id="attentionItems">
                @foreach($attention as $item)
                    @php $c = $palette[$item['severity']]; @endphp
                    <div class="col-12 col-md-6 col-xl-3" data-attention="{{ $item['key'] }}" @if($item['severity'] !== 'info') data-actionable="1" @endif>
                        <div class="h-100 d-flex align-items-center gap-2 px-2 py-2 rounded-3 border border-{{ $c }} border-opacity-50 bg-{{ $c }} bg-opacity-10" title="{{ $item['detail'] }}">
                            <span class="rounded-2 bg-{{ $c }} text-{{ $c === 'warning' ? 'dark' : 'white' }} d-flex align-items-center justify-content-center flex-shrink-0" style="width: 30px; height: 30px;">
                                <i class="bi {{ $item['icon'] }}"></i>
                            </span>
                            <div class="flex-grow-1 small fw-semibold lh-sm">
                                {{ $item['title'] }}
                                <span class="badge rounded-pill bg-{{ $c }} {{ $c === 'warning' ? 'text-dark' : '' }} ms-1">{{ $item['count'] }}</span>
                            </div>
                            <a href="{{ $item['url'] }}" class="btn btn-sm btn-{{ $c }} py-0 px-2 flex-shrink-0" aria-label="{{ $item['action'] }}: {{ $item['title'] }}">{{ $item['action'] }}</a>
                        </div>
                    </div>
                @endforeach
            </div>
            <div id="attentionClear" class="text-center text-muted small py-1 {{ $attention->isNotEmpty() ? 'd-none' : '' }}">
                <i class="bi bi-check-circle-fill text-success me-1"></i>Nothing needs your attention right now.
            </div>
        </div>
    </section>

    {{-- School reading progress + health of the AI services --}}
    <div class="row g-3 mb-4">
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-semibold d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <span><i class="bi bi-graph-up-arrow me-1"></i> School Reading Progress</span>
                    @if($progress['trend']['change'] !== null)
                        @php $ch = $progress['trend']['change']; @endphp
                        <span class="badge bg-{{ $ch >= 0 ? 'success' : 'danger' }}">
                            <i class="bi bi-{{ $ch >= 0 ? 'arrow-up' : 'arrow-down' }}-right"></i>
                            {{ $ch > 0 ? '+' : '' }}{{ $ch }} pts accuracy
                        </span>
                    @endif
                </div>
                <div class="card-body">
                    <div class="row g-3 text-center mb-3">
                        <div class="col-6 col-md-3">
                            <div class="h4 mb-0">{{ $progress['coverage']['assessed'] }}<span class="text-muted fs-6"> / {{ $progress['coverage']['learners'] }}</span></div>
                            <div class="small text-muted">Learners assessed</div>
                            <div class="progress mt-1" style="height: 6px;"><div class="progress-bar" style="width: {{ $progress['coverage']['percent'] }}%"></div></div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="h4 mb-0 text-success">{{ $progress['movement']['improved'] }}</div>
                            <div class="small text-muted" title="Their latest reading level is higher than their first, e.g. Frustration to Instructional.">Moved up a level</div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="h4 mb-0 text-secondary">{{ $progress['movement']['steady'] }}</div>
                            <div class="small text-muted" title="Their latest reading level is the same as their first.">Holding steady</div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="h4 mb-0 text-danger">{{ $progress['movement']['declined'] }}</div>
                            <div class="small text-muted" title="Their latest reading level is lower than their first, e.g. Independent to Instructional.">Slipped a level</div>
                        </div>
                    </div>

                    @if($progress['trend']['has_data'])
                        <div style="height: 220px;"><canvas id="progressChart"></canvas></div>
                    @else
                        <div class="text-center text-muted py-4">
                            <i class="bi bi-graph-up display-6 d-block mb-2"></i>Progress shows here once readings have been assessed.
                        </div>
                    @endif
                    <div class="small text-muted mt-2">
                        Lines show the school's monthly average accuracy and reading speed. The three counts compare each learner's <strong>first</strong> and
                        <strong>latest</strong> reading level in the same language (Frustration &rarr; Instructional &rarr; Independent).
                        Learners read only once aren't counted: {{ $progress['movement']['total'] }} learner{{ $progress['movement']['total'] === 1 ? '' : 's' }} so far.
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-activity me-1"></i> AI &amp; Speech Services</span>
                    <span class="badge bg-secondary" id="statusOverall">Checking&hellip;</span>
                </div>
                <div class="card-body pt-2 pb-1" id="systemStatus">
                    @foreach(['AI / ML classifier', 'Speech-to-text (Whisper)'] as $name)
                        <div class="py-3 border-bottom placeholder-glow"><span class="placeholder col-8"></span><div class="small text-muted mt-1">{{ $name }} &mdash; checking&hellip;</div></div>
                    @endforeach
                </div>
                <div class="card-footer bg-white d-flex justify-content-between align-items-center">
                    <span class="small text-muted" id="statusStamp">&nbsp;</span>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="statusRecheck"><i class="bi bi-arrow-repeat me-1"></i>Check again</button>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        {{-- Left: recent activity --}}
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-clock-history me-1"></i> Recent Activity</span>
                    <a href="{{ route('admin.logs') }}" class="small text-decoration-none">View all</a>
                </div>
                <div class="card-body p-0">
                    @forelse($recentActivity as $log)
                        <div class="d-flex align-items-start gap-2 px-3 py-2 border-bottom">
                            <i class="bi bi-circle-fill text-primary mt-2" style="font-size: 0.45rem;"></i>
                            <div class="flex-grow-1" style="min-width: 0;">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="fw-semibold text-truncate small">{{ $log->user?->name ?? 'System' }}</span>
                                    <span class="text-muted ms-2" style="font-size: 0.72rem; white-space: nowrap;">{{ $log->created_at?->diffForHumans() }}</span>
                                </div>
                                <div class="text-muted" style="font-size: 0.8rem;">{{ $log->description ?? $log->action ?? '' }}</div>
                            </div>
                        </div>
                    @empty
                        <div class="text-center text-muted py-5">
                            <i class="bi bi-inbox display-6 d-block mb-2"></i>No activity recorded yet
                        </div>
                    @endforelse
                </div>
            </div>
        </div>

        {{-- Right: school-wide reading weaknesses --}}
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-semibold"><i class="bi bi-pie-chart me-1"></i> School-Wide Reading Weaknesses</div>
                <div class="card-body d-flex align-items-center justify-content-center">
                    @if(array_sum($weaknessData) > 0)
                        <div style="width: 100%; height: 300px;"><canvas id="weaknessChart"></canvas></div>
                    @else
                        <div class="text-center text-muted py-5">
                            <i class="bi bi-pie-chart display-6 d-block mb-2"></i>No analysed assessments yet
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
    // ── AI & speech services: checked after the page loads so a slow service never delays the dashboard ──
    (function () {
        var url = @json(route('admin.system-status'));
        var box = document.getElementById('systemStatus');
        var btn = document.getElementById('statusRecheck');
        var stamp = document.getElementById('statusStamp');
        var overall = document.getElementById('statusOverall');
        var colors = { online: 'success', degraded: 'warning', offline: 'danger', disabled: 'secondary' };

        function esc(v) { var d = document.createElement('div'); d.textContent = v == null ? '' : v; return d.innerHTML; }

        // An offline or half-working service is something the principal should see at the top of the page.
        function attention(services) {
            var host = document.getElementById('attentionItems');
            host.querySelectorAll('[data-service-alert]').forEach(function (n) { n.remove(); });
            services.filter(function (s) { return s.state === 'offline' || s.state === 'degraded'; }).reverse().forEach(function (s) {
                var c = s.state === 'offline' ? 'danger' : 'warning';
                host.insertAdjacentHTML('afterbegin',
                    '<div class="col-12 col-md-6 col-xl-3" data-service-alert data-actionable="1">'
                    + '<div class="h-100 d-flex align-items-center gap-2 px-2 py-2 rounded-3 border border-' + c + ' border-opacity-50 bg-' + c + ' bg-opacity-10" title="' + esc(s.detail) + '">'
                    + '<span class="rounded-2 bg-' + c + ' text-' + (c === 'warning' ? 'dark' : 'white') + ' d-flex align-items-center justify-content-center flex-shrink-0" style="width:30px;height:30px;"><i class="bi ' + esc(s.icon) + '"></i></span>'
                    + '<div class="flex-grow-1 small fw-semibold lh-sm">' + esc(s.label) + (s.state === 'offline' ? ' is offline' : ' needs a look') + '</div>'
                    + '<a href="#systemStatus" class="btn btn-sm btn-' + c + ' py-0 px-2 flex-shrink-0">Details</a>'
                    + '</div></div>');
            });
            var todo = host.querySelectorAll('[data-actionable]').length;
            var badge = document.getElementById('attentionCount');
            badge.className = 'badge rounded-pill fs-6 bg-' + (todo ? 'danger' : (host.children.length ? 'secondary' : 'success'));
            badge.textContent = todo ? todo + ' to do' : (host.children.length ? 'Nothing urgent' : 'All clear');
            document.getElementById('attentionClear').classList.toggle('d-none', host.children.length > 0);
        }

        function render(data) {
            var worst = 'online';
            box.innerHTML = data.services.map(function (s) {
                var c = colors[s.state] || 'secondary';
                if (s.state === 'offline') worst = 'offline';
                else if (s.state === 'degraded' && worst !== 'offline') worst = 'degraded';
                return '<div class="d-flex gap-3 align-items-start py-3 border-bottom">'
                    + '<span class="rounded bg-' + c + ' bg-opacity-10 text-' + c + ' d-flex align-items-center justify-content-center flex-shrink-0" style="width:40px;height:40px;">'
                    + '<i class="bi ' + esc(s.icon) + ' fs-5"></i></span>'
                    + '<div class="flex-grow-1"><div class="d-flex justify-content-between align-items-center gap-2">'
                    + '<div class="fw-semibold">' + esc(s.label) + '</div><span class="badge bg-' + c + '">' + esc(s.headline) + '</span></div>'
                    + '<div class="small text-muted">' + esc(s.mode) + '</div><div class="small mt-1">' + esc(s.detail) + '</div></div></div>';
            }).join('');
            overall.className = 'badge bg-' + ({ online: 'success', degraded: 'warning', offline: 'danger' }[worst]);
            overall.textContent = { online: 'All running', degraded: 'Needs a look', offline: 'Attention needed' }[worst];
            stamp.textContent = 'Checked ' + new Date(data.checked_at).toLocaleTimeString();
            attention(data.services);
        }

        function load(fresh) {
            btn.disabled = true;
            fetch(url + (fresh ? '?fresh=1' : ''), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                .then(function (r) { if (!r.ok) throw new Error('bad'); return r.json(); })
                .then(render)
                .catch(function () {
                    box.innerHTML = '<div class="text-danger small py-3">Could not check the services just now. Try again.</div>';
                    overall.className = 'badge bg-secondary'; overall.textContent = 'Unknown';
                })
                .finally(function () { btn.disabled = false; });
        }

        btn.addEventListener('click', function () { load(true); });
        load(false);
    })();
</script>

@if($progress['trend']['has_data'])
<script>
    new Chart(document.getElementById('progressChart'), {
        type: 'line',
        data: {
            labels: {!! json_encode($progress['trend']['labels']) !!},
            datasets: [
                { label: 'Accuracy (%)', data: {!! json_encode($progress['trend']['accuracy']) !!}, borderColor: '#198754', backgroundColor: 'rgba(25,135,84,.12)', fill: true, tension: .3, spanGaps: true, yAxisID: 'y' },
                { label: 'Words per minute', data: {!! json_encode($progress['trend']['wpm']) !!}, borderColor: '#0d6efd', borderDash: [5, 4], tension: .3, spanGaps: true, yAxisID: 'y1' }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
            scales: {
                y:  { min: 0, max: 100, title: { display: true, text: 'Accuracy %' } },
                y1: { position: 'right', beginAtZero: true, grid: { drawOnChartArea: false }, title: { display: true, text: 'WPM' } }
            },
            plugins: { legend: { position: 'bottom' } }
        }
    });
</script>
@endif
@if(array_sum($weaknessData) > 0)
<script>
    new Chart(document.getElementById('weaknessChart'), {
        type: 'doughnut',
        data: {
            labels: {!! json_encode($weaknessLabels) !!},
            datasets: [{ data: {!! json_encode($weaknessData) !!}, backgroundColor: ['#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0'], borderWidth: 1 }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
    });
</script>
@endif
@endpush
