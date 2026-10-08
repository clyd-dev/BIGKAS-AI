@php $fil = $form['fil']; @endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Phil-IRI Form {{ $form['formNo'] }} – {{ $form['name'] }}</title>
    <style>
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 11px; color: #000; margin: 22px; }
        h1 { font-size: 14px; margin: 0; text-align: center; }
        h2 { font-size: 12px; margin: 2px 0 10px; text-align: center; font-weight: normal; }
        .form-no { text-align: right; font-weight: bold; }
        .head { width: 100%; margin-bottom: 8px; }
        .head td { padding: 2px 4px; }
        .part { font-weight: bold; background: #e9ecef; padding: 4px 6px; margin: 10px 0 6px; border: 1px solid #000; }
        table.grid { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        .grid th, .grid td { border: 1px solid #000; padding: 4px 6px; text-align: center; }
        .grid th { background: #f1f3f5; }
        .grid .left { text-align: left; }
        .grid .total td { font-weight: bold; background: #f4f6f8; }
        .box { display: inline-block; width: 10px; height: 10px; border: 1px solid #000; margin: 0 3px 0 8px; vertical-align: middle; }
        .note { font-size: 10px; color: #333; margin-top: 6px; }
        .noprint { text-align: right; margin-bottom: 10px; }
        @page { margin: 12mm; }
        @media print { .noprint { display: none; } body { margin: 0; } }
    </style>
</head>
<body>
@unless($pdf)
    <div class="noprint">
        <button onclick="window.print()" style="padding: 6px 14px;">Print / Save as PDF</button>
        <a href="{{ route('assessments.form3.pdf', $assessment) }}" style="margin-left: 8px;">Download PDF</a>
    </div>
@endunless

<div class="form-no">Phil-IRI Form {{ $form['formNo'] }}</div>
<h1>{{ $fil ? 'Markahang Papel ng Panggradong Lebel na Teksto' : 'Grade Level Passage Rating Sheet' }}</h1>
<h2>{{ $fil ? 'Filipino' : 'English' }}</h2>

<table class="head">
    <tr>
        <td style="width: 55%;"><strong>{{ $fil ? 'Pangalan' : 'Name' }}:</strong> {{ $form['name'] }}</td>
        <td><strong>{{ $fil ? 'Baitang / Seksyon' : 'Grade / Section' }}:</strong> {{ $form['grade'] }}{{ $form['section'] ? ' / ' . $form['section'] : '' }}</td>
    </tr>
    <tr>
        <td><strong>{{ $fil ? 'Paaralan' : 'School' }}:</strong> {{ $form['school'] }}</td>
        <td><strong>{{ $fil ? 'Petsa' : 'Date' }}:</strong> {{ $form['date'] }}</td>
    </tr>
    <tr>
        <td><strong>{{ $fil ? 'Seleksyon' : 'Passage' }}:</strong> {{ $form['passage'] }}</td>
        <td><strong>Level:</strong> {{ $form['level'] }} &nbsp;&nbsp; <strong>Set:</strong> ______ <span style="font-size: 9px;">(A, B, C or D)</span></td>
    </tr>
    <tr>
        <td colspan="2">
            <strong>{{ $fil ? 'Uri ng Pagtatasa' : 'Type of Test' }}:</strong>
            <span class="box"></span> {{ $fil ? 'Panimulang Pagtatasa (Pretest)' : 'Pretest' }}
            <span class="box"></span> {{ $fil ? 'Panapos na Pagtatasa (Posttest)' : 'Posttest' }}
        </td>
    </tr>
    <tr>
        <td colspan="2"><strong>{{ $fil ? 'Tagapangasiwa' : 'Test administrator' }}:</strong> {{ $form['administrator'] }}</td>
    </tr>
</table>

{{-- ───── PART A ───── --}}
<div class="part">PART A &mdash; {{ $fil ? 'Pag-unawa' : 'Comprehension' }}</div>
<table class="head">
    <tr>
        <td style="width: 55%;"><strong>{{ $fil ? 'Kabuuang Oras sa Pagbasa ng Teksto' : 'Total Time in Reading the Text' }}:</strong>
            @if($form['minutes'] !== null) {{ $form['minutes'] }} min {{ $form['seconds'] }} sec @else ______ @endif</td>
        <td><strong>{{ $fil ? 'Bilis ng Pagbasa' : 'Reading Rate' }}:</strong>
            @if($form['wpm'] !== null) {{ $form['wpm'] }} {{ $fil ? 'salita bawat minuto' : 'words per minute' }} @else ______ @endif</td>
    </tr>
</table>
<table class="grid">
    <thead>
        <tr>
            <th class="left" style="width: 38%;">{{ $fil ? 'Mga Sagot sa Tanong' : 'Responses to Questions' }}</th>
            @foreach(range(1, 8) as $q) <th>Q{{ $q }}</th> @endforeach
        </tr>
    </thead>
    <tbody>
        <tr><td class="left">&#10003; / X</td>@foreach(range(1, 8) as $q)<td>&nbsp;</td>@endforeach</tr>
    </tbody>
</table>
<table class="head">
    <tr>
        <td style="width: 30%;"><strong>{{ $fil ? 'Marka' : 'Score' }}:</strong> ______ / ______</td>
        <td style="width: 20%;"><strong>%:</strong> ______</td>
        <td><strong>{{ $fil ? 'Antas ng Pag-unawa' : 'Comprehension Level' }}:</strong> ______________</td>
    </tr>
</table>

{{-- ───── PART B ───── --}}
<div class="part">PART B &mdash; {{ $fil ? 'Pagbasa ng Salita' : 'Word Reading' }}</div>
<table class="grid">
    <thead>
        <tr>
            <th style="width: 6%;">#</th>
            <th class="left">{{ $fil ? 'Uri ng Mali' : 'Types of Miscues' }}</th>
            <th style="width: 30%;">{{ $fil ? 'Bilang ng Salitang Mali ang Basa' : 'Number of Miscues' }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach($form['miscues'] as $i => [$en, $tl, $count])
            <tr>
                <td>{{ $i + 1 }}</td>
                <td class="left">{{ $en }} ({{ $tl }})</td>
                <td>{{ $count ?? '' }}</td>
            </tr>
        @endforeach
        <tr class="total"><td></td><td class="left">{{ $fil ? 'Kabuuan' : 'Total Miscues' }}</td><td>{{ $form['total_miscues'] }}</td></tr>
        <tr><td></td><td class="left">{{ $fil ? 'Bilang ng Salita sa Seleksyon' : 'Number of Words in the Passage' }}</td><td>{{ $form['words'] }}</td></tr>
        <tr><td></td><td class="left">{{ $fil ? 'Marka sa Pagbasa ng Salita' : 'Word Reading Score' }}</td><td>{{ $form['word_score'] !== null ? $form['word_score'] . '%' : '' }}</td></tr>
        <tr class="total"><td></td><td class="left">{{ $fil ? 'Antas ng Pagbasa ng Salita' : 'Word Reading Level' }}</td><td>{{ $form['word_level'] ? ucfirst($form['word_level']) : '' }}</td></tr>
    </tbody>
</table>

<div class="part">{{ $fil ? 'Profil sa Pagbasa ng Mag-aaral' : "Learner's Reading Profile" }}</div>
<table class="grid">
    <tr>
        <th class="left" style="width: 40%;">{{ $fil ? 'Antas ng Pagbasa ng Salita' : 'Word Reading Level' }}</th>
        <td>{{ $form['word_level'] ? ucfirst($form['word_level']) : '' }}</td>
    </tr>
    <tr>
        <th class="left">{{ $fil ? 'Antas ng Pag-unawa' : 'Comprehension Level' }}</th>
        <td>&nbsp;</td>
    </tr>
</table>

<p class="note">
    Filled from the recorded oral reading: the miscue counts of Omission, Substitution, Insertion and Repetition, the number of words and
    the reading rate. Total reading time is worked out from the words and the reading rate.
    Type of test, set, the responses to questions, Mispronunciation, Transposition and Reversal are completed by the teacher.
    Word reading level: Independent 97% and above, Instructional 90&ndash;96%, Frustration below 90%.
</p>
</body>
</html>
