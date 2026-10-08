{{-- Form 2 table for one language. Expects $lang (from Form2Builder) and $code ('fil'|'en'). --}}
@php $fil = $code === 'fil'; @endphp
<table class="form2-table" style="width: 100%; border-collapse: collapse;">
    <thead>
        <tr>
            <th style="width: 12%;">Grade</th>
            <th>Sections</th>
            <th style="width: 15%;">Enrolment</th>
            <th style="width: 17%;">{{ $fil ? 'Markang' : 'Scores' }} &ge; 14</th>
            <th style="width: 17%;">{{ $fil ? 'Markang' : 'Scores' }} &lt; 14</th>
        </tr>
    </thead>
    <tbody>
        @forelse($lang['grades'] as $grade)
            <tr class="grade-row">
                <td>{{ $grade['roman'] }}</td>
                <td></td>
                <td>{{ $grade['total']['enrolment'] }}</td>
                <td>{{ $grade['total']['at_grade'] }}</td>
                <td>{{ $grade['total']['below'] }}</td>
            </tr>
            @foreach($grade['sections'] as $s)
                <tr>
                    <td></td>
                    <td style="text-align: left; padding-left: 10px;">{{ $s['section'] }}</td>
                    <td>{{ $s['enrolment'] }}</td>
                    <td>{{ $s['at_grade'] }}</td>
                    <td>{{ $s['below'] }}</td>
                </tr>
            @endforeach
        @empty
            <tr><td colspan="5" style="padding: 14px;">No submitted section reports yet.</td></tr>
        @endforelse
        <tr class="total-row">
            <td colspan="2">Total</td>
            <td>{{ $lang['total']['enrolment'] }}</td>
            <td>{{ $lang['total']['at_grade'] }}</td>
            <td>{{ $lang['total']['below'] }}</td>
        </tr>
    </tbody>
</table>
@if($lang['total']['not_tested'] > 0)
    <p style="font-size: 11px; margin-top: 6px;">
        Note: {{ $lang['total']['not_tested'] }} enrolled learner(s) were not tested and are not counted in either score column.
    </p>
@endif
