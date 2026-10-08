<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Phil-IRI Form 4 – {{ $form['name'] }}</title>
    <style>
        body { margin: 18px; }
        .noprint { text-align: right; margin-bottom: 10px; font-family: Arial, sans-serif; }
        @page { size: A4 landscape; margin: 10mm; }
        @media print { .noprint { display: none; } body { margin: 0; } }
        @include('learners._form4_css')
    </style>
</head>
<body>
@unless($pdf)
    <div class="noprint"><button onclick="window.print()" style="padding: 6px 14px;">Print / Save as PDF</button></div>
@endunless
<div class="f4">
    @include('learners._form4_pages', ['form' => $form])
</div>
</body>
</html>
