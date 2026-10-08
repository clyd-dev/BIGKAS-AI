@php
    $fil = $language === 'fil';
    $school = $class->school;
    $n = 0;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Phil-IRI Form {{ $formNo }} – Grade {{ $class->grade_level }} {{ $class->section }}</title>
    <style>
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 11px; color: #000; margin: 22px; }
        h1 { font-size: 14px; margin: 0; text-align: center; }
        h2 { font-size: 12px; margin: 2px 0 10px; text-align: center; font-weight: normal; }
        .form-no { text-align: right; font-weight: bold; font-size: 11px; }
        .head { width: 100%; margin-bottom: 8px; }
        .head td { padding: 2px 4px; }
        table.grid { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        .grid th, .grid td { border: 1px solid #000; padding: 4px 5px; text-align: center; }
        .grid th { background: #e9ecef; }
        .grid .name { text-align: left; }
        .grid .group td { background: #f4f6f8; font-weight: bold; text-align: left; }
        .note { font-size: 10px; color: #333; }
        .noprint { text-align: right; margin-bottom: 10px; }
        @page { margin: 12mm; }
        @media print { .noprint { display: none; } body { margin: 0; } }
    </style>
</head>
<body>
@unless($pdf)
    <div class="noprint"><button onclick="window.print()" style="padding: 6px 14px;">Print / Save as PDF</button></div>
@endunless

<div class="form-no">Phil-IRI Form {{ $formNo }}</div>
<h1>{{ $fil ? 'Talaan ng Pangkatang Pagtatasa ng Klase (TPPK)' : 'Screening Test Class Reading Record (STCRR)' }}</h1>
<h2>{{ \App\Models\ClassReport::PERIODS[$period] ?? $period }} &middot; S.Y. {{ $class->school_year }}</h2>

<table class="head">
    <tr>
        <td style="width: 50%;"><strong>{{ $fil ? 'Baitang' : 'Grade' }}:</strong> {{ $class->grade_level }}
            &nbsp;&nbsp; <strong>{{ $fil ? 'Seksyon' : 'Section' }}:</strong> {{ $class->section }}</td>
        <td><strong>{{ $fil ? 'Guro' : 'Teacher' }}:</strong> {{ $class->teacher?->name }}</td>
    </tr>
    <tr>
        <td><strong>{{ $fil ? 'Paaralan' : 'School' }}:</strong> {{ $school?->name }}</td>
        <td><strong>{{ $fil ? 'Petsa' : 'Date' }}:</strong> ______________________</td>
    </tr>
    <tr>
        <td colspan="2"><strong>{{ $fil ? 'Antas ng Pangkatang Pagtatasa' : 'Screening Test Level' }}:</strong> {{ $class->grade_level }}</td>
    </tr>
</table>

<table class="grid">
    <thead>
        <tr>
            <th rowspan="2" style="width: 4%;">#</th>
            <th rowspan="2">{{ $fil ? 'Pangalan' : 'Name' }}</th>
            <th rowspan="2" style="width: 8%;">{{ $fil ? 'Nakuha ang Pagtatasa' : 'Test Taken' }}<br>&#10003; / X</th>
            <th colspan="3">{{ $fil ? 'Bilang ng Tamang Sagot (ayon sa Uri ng Tanong)' : 'Number of Correct Responses' }}</th>
            <th rowspan="2" style="width: 9%;">{{ $fil ? 'Kabuuang Marka' : 'Overall Score' }}</th>
            <th rowspan="2" style="width: 7%;">{{ $fil ? 'Markang' : 'Score' }} &lt; 14</th>
            <th rowspan="2" style="width: 7%;">{{ $fil ? 'Markang' : 'Score' }} &ge; 14</th>
            <th rowspan="2" style="width: 13%;">Starting Point of Graded Passage</th>
        </tr>
        <tr>
            <th style="width: 8%;">Literal</th>
            <th style="width: 10%;">{{ $fil ? 'Panghihinuha' : 'Inferential' }}</th>
            <th style="width: 8%;">{{ $fil ? 'Kritikal' : 'Critical' }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach($groups as $label => $list)
            @continue($list->isEmpty())
            <tr class="group"><td colspan="10">{{ $label }}</td></tr>
            @foreach($list as $learner)
                @php $r = $results->get($learner->id); $n++; @endphp
                <tr>
                    <td>{{ $n }}</td>
                    <td class="name">{{ $learner->last_name }}, {{ $learner->first_name }}</td>
                    @if($r)
                        <td>{!! $r->test_taken ? '&#10003;' : 'X' !!}</td>
                        <td>{{ $r->literal_correct }}</td>
                        <td>{{ $r->inferential_correct }}</td>
                        <td>{{ $r->critical_correct }}</td>
                        <td>{{ $r->total_score !== null ? $r->total_score . ' / ' . \App\Services\GstScoring::maxScore() : '' }}</td>
                        <td>{!! $r->classification === 'needs_assessment' ? '&#10003;' : '' !!}</td>
                        <td>{!! $r->classification === 'at_grade_level' ? '&#10003;' : '' !!}</td>
                        <td>{{ $r->total_score !== null ? \App\Services\GstScoring::startingLevelLabel($r->starting_level) : '' }}</td>
                    @else
                        <td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td>
                    @endif
                </tr>
            @endforeach
        @endforeach
        @if($n === 0)
            <tr><td colspan="10" style="padding: 14px;">No learners in this section.</td></tr>
        @endif
    </tbody>
</table>

<p class="note">
    <strong>Tested:</strong> {{ $summary['tested'] }} &nbsp;&middot;&nbsp;
    <strong>{{ $fil ? 'Markang' : 'Score' }} &ge; 14:</strong> {{ $summary['at_grade'] }} &nbsp;&middot;&nbsp;
    <strong>{{ $fil ? 'Markang' : 'Score' }} &lt; 14:</strong> {{ $summary['below'] }}<br>
    * A score of 14 or more means the learner reads at grade level and takes no further tests. A score below 14 moves on to the
    individual graded-passage assessment, starting at the level shown.
</p>
</body>
</html>
