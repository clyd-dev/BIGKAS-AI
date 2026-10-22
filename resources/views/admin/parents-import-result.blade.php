<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Parent Credentials</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        @media print {
            .no-print { display: none !important; }
            .slip { page-break-inside: avoid; }
        }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .slip { border: 1px dashed #999; border-radius: 10px; padding: 14px 18px; margin-bottom: 12px; }
    </style>
</head>
<body class="p-4">
    <div class="no-print text-end mb-3">
        <button onclick="window.print()" class="btn btn-primary btn-sm"><i class="bi bi-printer"></i> Print</button>
        <a href="{{ route('admin.users') }}" class="btn btn-outline-secondary btn-sm">Done, back to Users</a>
    </div>

    <div class="alert alert-warning no-print">
        <strong>Every account below shares the same password.</strong> Keep this list controlled, and ask
        parents to change their password after their first login (Profile &rarr; Change Password) once the
        test is done.
    </div>

    <h4 class="fw-bold mb-1">BIGKAS-AI &mdash; Parent Login Slips</h4>
    <p class="text-muted mb-4">{{ count($created) }} account(s) created on {{ now()->format('F j, Y') }}. Cut along the lines and give one slip per parent.
        Tell each parent to type their login <strong>exactly</strong> as printed, including any "(2)" suffix.</p>

    @foreach($created as $row)
        <div class="slip">
            <div class="row">
                <div class="col-7">
                    <div class="fw-bold">{{ $row['name'] }}</div>
                    @if($row['learner_label'])
                        <div class="small text-muted">Child: {{ $row['learner_label'] }}</div>
                    @endif
                    @if(!empty($row['grades']))
                        <div class="small text-muted">Grade {{ implode(' & ', $row['grades']) }}</div>
                    @endif
                </div>
                <div class="col-5 text-end small">
                    <div>Login: <span class="font-monospace">{{ $row['login'] }}</span></div>
                    <div>Password: <span class="font-monospace fw-bold">{{ $row['password'] }}</span></div>
                </div>
            </div>
        </div>
    @endforeach
</body>
</html>
