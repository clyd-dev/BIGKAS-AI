<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Student PIN slips</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 18px; font-family: Arial, Helvetica, sans-serif; color: #111; background: #fff; }

        .noprint { max-width: 190mm; margin: 0 auto 14px; font-size: 14px; }
        .noprint button { padding: 8px 16px; font-size: 14px; cursor: pointer; }
        .noprint .note { margin-top: 8px; color: #555; line-height: 1.45; }
        .noprint .warn { margin-top: 8px; padding: 8px 10px; background: #fff7e0; border: 1px solid #f0d58a; border-radius: 4px; }

        .slips { max-width: 190mm; margin: 0 auto; display: grid; grid-template-columns: 1fr 1fr; gap: 0; }
        .slip {
            border: 1px dashed #888; padding: 10mm 8mm; text-align: center;
            break-inside: avoid; page-break-inside: avoid;
        }
        .slip .school { font-size: 11px; color: #555; }
        .slip .name { font-size: 17px; font-weight: bold; margin: 6px 0 2px; }
        .slip .section { font-size: 12px; color: #555; margin-bottom: 8px; }
        .slip .label { font-size: 11px; letter-spacing: .08em; text-transform: uppercase; color: #555; }
        .slip .pin { font-family: "Courier New", monospace; font-size: 34px; font-weight: bold; letter-spacing: .18em; margin: 2px 0 8px; }
        .slip .how { font-size: 11px; color: #444; line-height: 1.4; }

        @page { size: A4 portrait; margin: 10mm; }
        @media print {
            body { padding: 0; }
            .noprint { display: none; }
        }
        @media (max-width: 520px) { .slips { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <div class="noprint">
        <button onclick="window.print()">Print / Save as PDF</button>
        <div class="note">
            Cut along the dotted lines and give each child <strong>only their own slip</strong>.
            Do not post this sheet where others can see it &mdash; the PIN is the only thing that
            signs a child in.
        </div>
        @if($missing > 0)
            <div class="warn">{{ $missing }} {{ $missing === 1 ? 'learner has' : 'learners have' }} no PIN yet, so
                {{ $missing === 1 ? 'is' : 'are' }} not on this sheet.</div>
        @endif
        @if($withheld > 0)
            <div class="warn">{{ $withheld }} {{ $withheld === 1 ? 'learner has' : 'learners have' }} a PIN that
                cannot be shown (it was issued before PINs could be viewed). It still works. To print a slip,
                issue {{ $withheld === 1 ? 'that learner' : 'those learners' }} a new PIN from the Student PINs page.</div>
        @endif
    </div>

    @if($slips->isEmpty())
        <div class="noprint"><p>There are no PINs to print.</p></div>
    @else
        <div class="slips">
            @foreach($slips as $learner)
                <div class="slip">
                    <div class="school">{{ $learner->school?->name ?? config('app.name') }}</div>
                    <div class="name">{{ $learner->getFullName() }}</div>
                    <div class="section">{{ $learner->schoolClass?->getDisplayName() }}</div>
                    <div class="label">My PIN</div>
                    <div class="pin">{{ $learner->pin }}</div>
                    <div class="how">
                        Go to <strong>{{ route('student.login') }}</strong><br>
                        and type this PIN to sign in.
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</body>
</html>
