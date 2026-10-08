{{--
    Password field with a show/hide (eye) button.

    <x-password-input name="password" placeholder="Enter your password" autocomplete="current-password" autofocus />

    The error for the field (if any) is shown under it. Works without JavaScript as a normal password field.
--}}
@props([
    'name' => 'password',
    'id' => null,
    'placeholder' => '',
    'autocomplete' => 'current-password',
    'required' => true,
    'autofocus' => false,
])
@php $id = $id ?? $name; @endphp

<div class="input-group has-validation">
    <span class="input-group-text"><i class="bi bi-lock"></i></span>
    <input type="password"
           class="form-control @error($name) is-invalid @enderror"
           id="{{ $id }}" name="{{ $name }}"
           placeholder="{{ $placeholder }}"
           autocomplete="{{ $autocomplete }}"
           @if($required) required @endif
           @if($autofocus) autofocus @endif>
    <button type="button" class="btn btn-outline-secondary" data-password-toggle="{{ $id }}"
            aria-label="Show password" aria-pressed="false" title="Show password">
        <i class="bi bi-eye"></i>
    </button>
    @error($name)
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

@once
<script>
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-password-toggle]');
        if (!btn) return;
        var input = document.getElementById(btn.getAttribute('data-password-toggle'));
        if (!input) return;
        var show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        btn.setAttribute('aria-pressed', show ? 'true' : 'false');
        btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        btn.title = show ? 'Hide password' : 'Show password';
        btn.querySelector('i').className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
        input.focus();
    });
</script>
@endonce
