{{--
    Legend of what an acceptable password is. Ticks items off as the user types.
    Pass passwordId / confirmId when the fields are not called password / password_confirmation
    (the admin create-user form does this). Needs the shared password-input component on the same page.
--}}
@php
    $passwordId = $passwordId ?? 'password';
    $confirmId  = $confirmId ?? 'password_confirmation';
    $boxId      = 'rules-' . $passwordId;
@endphp
<div class="border rounded p-3 mb-3 bg-light" id="{{ $boxId }}" role="group" aria-labelledby="{{ $boxId }}-title">
    <div class="fw-semibold small mb-2" id="{{ $boxId }}-title">Your password must have:</div>
    <ul class="list-unstyled small mb-2" aria-live="polite">
        @foreach(\App\Support\PasswordPolicy::requirements() as $r)
            <li data-rule="{{ $r['id'] }}" data-pattern="{{ $r['pattern'] }}" class="text-muted">
                <i class="bi bi-circle me-2"></i>{{ $r['label'] }}
            </li>
        @endforeach
        <li data-rule="match" class="text-muted">
            <i class="bi bi-circle me-2"></i>Both password boxes match
        </li>
    </ul>
    <div class="small text-muted">
        Letters, numbers, spaces and symbols (such as ! @ # $ %) are all allowed. Symbols are optional.
        Tip: a short phrase you can remember, with a capital letter and a number, works well.
    </div>
</div>

<script>
    (function () {
        var pw = document.getElementById(@json($passwordId));
        var confirmBox = document.getElementById(@json($confirmId));
        var box = document.getElementById(@json($boxId));
        if (!pw || !box) return;

        function mark(li, ok) {
            li.className = ok ? 'text-success' : 'text-muted';
            li.querySelector('i').className = (ok ? 'bi bi-check-circle-fill' : 'bi bi-circle') + ' me-2';
        }

        function update() {
            box.querySelectorAll('[data-pattern]').forEach(function (li) {
                mark(li, new RegExp(li.getAttribute('data-pattern')).test(pw.value));
            });
            var match = box.querySelector('[data-rule="match"]');
            mark(match, pw.value.length > 0 && confirmBox && confirmBox.value === pw.value);
        }

        pw.addEventListener('input', update);
        if (confirmBox) confirmBox.addEventListener('input', update);
        update();
    })();
</script>
