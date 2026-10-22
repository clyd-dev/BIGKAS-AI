<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Learner Reading Master List - {{ $list['school_year'] }}</title>
    <style>
        @page { size: A4 landscape; margin: 12mm; }
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 11px; color: #000; margin: 0; }
        h1 { font-size: 15px; margin: 0; text-align: center; }
        h2 { font-size: 12px; margin: 2px 0 8px; text-align: center; font-weight: normal; }
        .conf { text-align: right; font-size: 9px; font-weight: bold; letter-spacing: .5px; }
        .head td { padding: 1px 4px; font-size: 11px; }
        .page { page-break-after: always; }
        .page:last-of-type { page-break-after: auto; }
        .sign { margin-top: 22px; width: 100%; }
        .sign td { width: 50%; text-align: center; font-size: 10px; padding-top: 16px; }
        .sign .line { border-top: 1px solid #000; margin: 0 40px; padding-top: 3px; }
        .legend { font-size: 9px; color: #333; margin-top: 8px; }
        .noprint { text-align: right; margin: 12px; }
        @media print { .noprint { display: none; } }
    </style>
    @include('reports.master-list._styles')
</head>
<body>
@unless($pdf)
    <div class="noprint"><button onclick="window.print()" style="padding: 6px 14px;">Print / Save as PDF</button></div>
@endunless

@forelse($list['sections'] as $section)
    @php $school = $list['school']; @endphp
    <div class="page">
        <div class="conf">CONFIDENTIAL - FOR SCHOOL USE ONLY</div>
        <h1>Learner Reading Master List</h1>
        <h2>{{ $school['name'] }} &middot; S.Y. {{ $list['school_year'] }}</h2>
        <table class="head" style="width: 100%;">
            <tr>
                <td style="width: 34%;"><strong>Grade &amp; Section:</strong> {{ $section['label'] }}</td>
                <td style="width: 33%;"><strong>Adviser:</strong> {{ $section['teacher'] ?: '-' }}</td>
                <td><strong>Division:</strong> {{ $school['division'] }} &middot; <strong>District:</strong> {{ $school['district'] }}</td>
            </tr>
        </table>

        @include('reports.master-list._section')

        <div class="legend">
            Levels follow DepEd Phil-IRI: oral reading Independent 97%+, Instructional 90-96%, Frustration below 90%;
            comprehension Independent 80%+, Instructional 59-79%, Frustration 58% or below. Overall level is the lower of the two.
            Scores are the learner's latest measured reading. Progress compares the first and latest reading in the same language.
        </div>
        <table class="sign">
            <tr>
                <td><div class="line">{{ $section['teacher'] ?: 'Class Adviser' }}<br>Class Adviser</div></td>
                <td><div class="line">{{ $school['principal'] ?: 'School Head' }}<br>School Principal / School Head</div></td>
            </tr>
        </table>
        <div class="legend">Printed {{ $list['generated_at'] }}</div>
    </div>
@empty
    <p style="text-align:center">No learners match this selection.</p>
@endforelse
</body>
</html>
