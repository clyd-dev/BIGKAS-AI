{{-- Reading profile snapshot of one section, per language. Expects $snapshot. --}}
<div class="row g-3">
    @foreach($snapshot['languages'] as $lang)
        @php
            $assessed = $lang['assessed'];
            $pct = fn ($n) => $assessed > 0 ? round($n / $assessed * 100) : 0;
        @endphp
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h6 class="mb-0">{{ $lang['label'] }}</h6>
                    <span class="small text-muted">{{ $assessed }} of {{ $snapshot['enrolment'] }} assessed</span>
                </div>
                <div class="card-body">
                    @if($assessed > 0)
                        <div class="progress mb-3" style="height: 10px;">
                            <div class="progress-bar bg-success" style="width: {{ $pct($lang['levels']['independent']) }}%"></div>
                            <div class="progress-bar bg-warning" style="width: {{ $pct($lang['levels']['instructional']) }}%"></div>
                            <div class="progress-bar bg-danger" style="width: {{ $pct($lang['levels']['frustration']) }}%"></div>
                        </div>
                    @endif
                    <table class="table table-sm mb-0">
                        <tr><td><span class="badge bg-success">Independent</span></td><td class="text-end">{{ $lang['levels']['independent'] }}</td></tr>
                        <tr><td><span class="badge bg-warning text-dark">Instructional</span></td><td class="text-end">{{ $lang['levels']['instructional'] }}</td></tr>
                        <tr><td><span class="badge bg-danger">Frustration</span></td><td class="text-end">{{ $lang['levels']['frustration'] }}</td></tr>
                        <tr><td><span class="badge bg-secondary">Not yet assessed</span></td><td class="text-end">{{ $lang['not_assessed'] }}</td></tr>
                        <tr class="border-top"><td class="text-muted">Average accuracy</td><td class="text-end">{{ $lang['avg_accuracy'] !== null ? $lang['avg_accuracy'].'%' : '—' }}</td></tr>
                        <tr><td class="text-muted">Average WPM</td><td class="text-end">{{ $lang['avg_wpm'] ?? '—' }}</td></tr>
                    </table>
                </div>
            </div>
        </div>
    @endforeach
</div>

@if(!empty($snapshot['screening']))
    {{-- Group Screening Test (Phil-IRI Forms 1A/1B) --}}
    <div class="card border-0 shadow-sm mt-3">
        <div class="card-header bg-white"><h6 class="mb-0">Group Screening Test</h6></div>
        <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Test</th><th class="text-center">Enrolment</th><th class="text-center">Score ≥ 14</th>
                        <th class="text-center">Score &lt; 14</th><th class="text-center">Not tested</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($snapshot['screening'] as $code => $row)
                        <tr>
                            <td>{{ $row['label'] }} (Form {{ $code === 'fil' ? '1A' : '1B' }})</td>
                            <td class="text-center">{{ $row['enrolment'] }}</td>
                            <td class="text-center text-success fw-semibold">{{ $row['at_grade'] }}</td>
                            <td class="text-center text-danger fw-semibold">{{ $row['below'] }}</td>
                            <td class="text-center text-muted">{{ $row['not_tested'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
