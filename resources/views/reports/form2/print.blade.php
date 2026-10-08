<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Phil-IRI Form 2 – {{ $form['school_year'] }}</title>
    <style>
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 12px; color: #000; margin: 24px; }
        h1 { font-size: 15px; margin: 0; text-align: center; }
        h2 { font-size: 13px; margin: 2px 0 14px; text-align: center; font-weight: normal; }
        .form-no { text-align: right; font-size: 11px; font-weight: bold; }
        .head td { padding: 2px 4px; font-size: 12px; }
        .form2-table th, .form2-table td { border: 1px solid #000; padding: 5px 6px; text-align: center; }
        .form2-table th { background: #e9ecef; }
        .form2-table .grade-row td { font-weight: bold; background: #f4f6f8; }
        .form2-table .total-row td { font-weight: bold; background: #e9ecef; }
        .page { page-break-after: always; }
        .page:last-of-type { page-break-after: auto; }
        .sign { margin-top: 40px; width: 100%; }
        .sign td { width: 50%; text-align: center; font-size: 11px; padding-top: 28px; }
        .sign .line { border-top: 1px solid #000; margin: 0 30px; padding-top: 3px; }
        .noprint { text-align: right; margin-bottom: 12px; }
        @media print { .noprint { display: none; } body { margin: 0; } }
    </style>
</head>
<body>
@unless($pdf)
    <div class="noprint">
        <button onclick="window.print()" style="padding: 6px 14px;">Print / Save as PDF</button>
    </div>
@endunless

@foreach($form['languages'] as $code => $lang)
    @php $fil = $code === 'fil'; $school = $form['school']; @endphp
    <div class="page">
        <div class="form-no">Phil-IRI Form 2</div>
        <h1>{{ $fil ? 'Talaan ng Paaralan sa Pagbabasa (TPP)' : 'School Reading Profile (SRP)' }}</h1>
        <h2>{{ $fil ? 'Pagtatasa sa Pagbasa sa Filipino' : 'Reading Assessment in English' }}
            &middot; {{ \App\Models\ClassReport::PERIODS[$form['period']] ?? $form['period'] }}
            &middot; S.Y. {{ $form['school_year'] }}</h2>

        <table class="head" style="width: 100%; margin-bottom: 10px;">
            <tr>
                <td style="width: 50%;"><strong>{{ $fil ? 'Paaralan' : 'School' }}:</strong> {{ $school['name'] }}</td>
                <td><strong>{{ $fil ? 'Sangay' : 'Division' }}:</strong> {{ $school['division'] }}</td>
            </tr>
            <tr>
                <td><strong>{{ $fil ? 'Distrito' : 'District' }}:</strong> {{ $school['district'] }}</td>
                <td><strong>{{ $fil ? 'Rehiyon' : 'Region' }}:</strong> {{ $school['region'] }}</td>
            </tr>
        </table>

        @include('reports.form2._table', ['lang' => $lang, 'code' => $code])

        <table class="sign">
            <tr>
                <td><div class="line">Prepared by / Teacher-in-charge</div></td>
                <td><div class="line">{{ $school['principal'] ?: 'School Head' }}<br>School Principal / School Head</div></td>
            </tr>
        </table>
    </div>
@endforeach
</body>
</html>
