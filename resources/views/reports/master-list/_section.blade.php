@php
    use App\Services\LearnerMasterList as ML;
    $short = ['independent' => 'Ind.', 'instructional' => 'Instr.', 'frustration' => 'Frust.'];
@endphp
<table class="ml-table">
    <thead>
        <tr>
            <th rowspan="2" class="ml-no">#</th>
            <th rowspan="2" class="ml-name">Full name</th>
            <th rowspan="2">LRN</th>
            @foreach($list['languages'] as $code => $label)
                <th colspan="4">{{ $label }}</th>
            @endforeach
            <th rowspan="2">Readings</th>
            <th rowspan="2">Progress</th>
        </tr>
        <tr>
            @foreach($list['languages'] as $code => $label)
                <th>Oral reading</th><th>Comprehension</th><th>Overall level</th><th>WPM</th>
            @endforeach
        </tr>
    </thead>
    <tbody>
        @forelse($section['rows'] as $i => $row)
            <tr>
                <td class="ml-no">{{ $i + 1 }}</td>
                <td class="ml-name">{{ $row['name'] }}</td>
                <td>{{ $row['lrn'] ?: '-' }}</td>
                @if($row['readings'] === 0)
                    <td colspan="{{ count($list['languages']) * 4 }}" class="ml-none">Not assessed</td>
                @endif
                @foreach($row['readings'] === 0 ? [] : $list['languages'] as $code => $label)
                    @php $b = $row['languages'][$code]; @endphp
                    @if($b)
                        <td>{{ $b['oral_pct'] !== null ? number_format($b['oral_pct'], 0) . '%' : '-' }}
                            <span class="ml-lv ml-{{ $b['oral'] }}">{{ $short[$b['oral']] ?? '' }}</span></td>
                        <td>{{ $b['comp_pct'] !== null ? number_format($b['comp_pct'], 0) . '%' : '-' }}
                            <span class="ml-lv ml-{{ $b['comp'] }}">{{ $short[$b['comp']] ?? '' }}</span></td>
                        <td><strong class="ml-{{ $b['overall'] }}">{{ ML::levelLabel($b['overall']) }}</strong></td>
                        <td>{{ $b['wpm'] ?? '-' }}</td>
                    @else
                        <td colspan="4" class="ml-none">Not assessed</td>
                    @endif
                @endforeach
                <td>{{ $row['readings'] }}</td>
                <td class="ml-prog ml-p-{{ $row['progress'] }}">{{ ML::progressLabel($row['progress']) }}</td>
            </tr>
        @empty
            <tr><td colspan="{{ 5 + count($list['languages']) * 4 }}" class="ml-none">No learners in this section.</td></tr>
        @endforelse
    </tbody>
</table>
@php $s = $section['summary']; @endphp
<p class="ml-summary">
    {{ $s['learners'] }} {{ \Illuminate\Support\Str::plural('learner', $s['learners']) }} &middot;
    {{ $s['assessed'] }} assessed &middot;
    Moved up {{ $s['up'] }} &middot; Steady {{ $s['steady'] }} &middot; Slipped {{ $s['down'] }}
</p>
