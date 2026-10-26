@extends('layouts.app')

@section('title', 'Student PINs')

@section('content')
    <x-page-header title="Student PINs" icon="bi-key" :back="route('learners.index')" back-label="Learners"
                   subtitle="Give every child their sign-in PIN at once, then print the slips.">
        <x-slot:actions>
            <a href="{{ route('learners.pins.sheet', array_filter(['class_id' => $classId])) }}" target="_blank"
               class="btn btn-outline-primary btn-sm">
                <i class="bi bi-printer me-1"></i> Print current PINs
            </a>
        </x-slot:actions>
    </x-page-header>

    {{-- Section filter --}}
    @if($classes->count() > 1)
        <div class="d-flex flex-wrap gap-2 mb-3">
            <a href="{{ route('learners.pins.index') }}" class="btn btn-sm {{ $classId ? 'btn-outline-secondary' : 'btn-secondary' }}">All sections</a>
            @foreach($classes as $class)
                <a href="{{ route('learners.pins.index', ['class_id' => $class->id]) }}"
                   class="btn btn-sm {{ $classId === $class->id ? 'btn-secondary' : 'btn-outline-secondary' }}">
                    {{ $class->getDisplayName() }}
                </a>
            @endforeach
        </div>
    @endif

    {{-- What state they are in --}}
    <div class="row g-2 mb-3">
        <div class="col-6 col-md-4">
            <div class="card border-0 shadow-sm h-100"><div class="card-body py-2">
                <div class="fs-4 fw-bold {{ $counts['none'] ? 'text-danger' : 'text-success' }}">{{ $counts['none'] }}</div>
                <div class="small text-muted">without a PIN</div>
            </div></div>
        </div>
        <div class="col-6 col-md-4">
            <div class="card border-0 shadow-sm h-100"><div class="card-body py-2">
                <div class="fs-4 fw-bold">{{ $counts['readable'] }}</div>
                <div class="small text-muted">have a PIN</div>
            </div></div>
        </div>
        <div class="col-12 col-md-4">
            <div class="card border-0 shadow-sm h-100"><div class="card-body py-2">
                <div class="fs-4 fw-bold {{ $counts['legacy'] ? 'text-warning' : '' }}">{{ $counts['legacy'] }}</div>
                <div class="small text-muted">have a PIN that cannot be shown</div>
            </div></div>
        </div>
    </div>

    @if($learners->isEmpty())
        <div class="card border-0 shadow-sm"><div class="card-body text-center text-muted py-5">
            No active learners to issue PINs to.
            <div class="mt-2"><a href="{{ route('learners.create') }}">Add a learner</a></div>
        </div></div>
    @else
        <form method="POST" action="{{ route('learners.pins.store') }}" id="pinForm">
            @csrf

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body d-flex flex-wrap gap-2 align-items-center">
                    <button type="button" class="btn btn-sm btn-outline-primary" data-select="none-pin">
                        Select learners without a PIN
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-select="all">Select all</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-select="clear">Clear</button>
                    <span class="ms-auto small text-muted"><span id="pinSelected">0</span> selected</span>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-3">
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 2.5rem;"></th>
                                <th>Learner</th>
                                <th>Section</th>
                                <th>PIN</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($learners as $learner)
                                @php
                                    $state = ! $learner->hasPin() ? 'none' : ($learner->hasLegacyPin() ? 'legacy' : 'has');
                                @endphp
                                <tr>
                                    <td>
                                        <input type="checkbox" class="form-check-input pin-box" name="learner_ids[]"
                                               value="{{ $learner->id }}" data-state="{{ $state }}"
                                               id="l{{ $learner->id }}" {{ $state === 'none' ? 'checked' : '' }}>
                                    </td>
                                    <td><label for="l{{ $learner->id }}" class="mb-0">{{ $learner->getFullName() }}</label></td>
                                    <td class="small text-muted">{{ $learner->schoolClass?->getDisplayName() }}</td>
                                    <td class="small">
                                        @if($state === 'none')
                                            <span class="badge bg-danger-subtle text-danger-emphasis">No PIN</span>
                                        @elseif($state === 'legacy')
                                            <span class="badge bg-warning-subtle text-warning-emphasis">PIN cannot be shown</span>
                                        @else
                                            <span class="badge bg-success-subtle text-success-emphasis">Has a PIN</span>
                                        @endif
                                        @if($learner->hasPin() && $learner->pin_created_at)
                                            <span class="text-muted ms-1">issued {{ $learner->pin_created_at->format('M j, Y') }}</span>
                                            @if($learner->isPinExpired()) <span class="text-danger">&middot; expired</span> @endif
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-4" id="replaceBox">
                <div class="card-body">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="replace_existing" value="1" id="replaceExisting">
                        <label class="form-check-label" for="replaceExisting">
                            <strong>Replace PINs that learners already have</strong>
                            <div class="small text-muted">
                                Off by default: a child who already has a PIN keeps it. Turn this on only to give
                                the ticked learners who have one a <em>new</em> PIN &mdash; their old PIN stops
                                working straight away, so they will need their new slip.
                            </div>
                        </label>
                    </div>
                </div>
            </div>

            <div class="d-flex flex-wrap gap-2 align-items-center">
                <button type="submit" class="btn btn-primary" id="pinSubmit">
                    <i class="bi bi-key me-1"></i> Generate PINs
                </button>
                <span class="small text-muted">
                    You will get a printable sheet. PINs are never shown on this page.
                </span>
            </div>
        </form>
    @endif
@endsection

@push('scripts')
<script>
    (function () {
        var form = document.getElementById('pinForm');
        if (!form) return;

        var boxes = Array.prototype.slice.call(form.querySelectorAll('.pin-box'));
        var counter = document.getElementById('pinSelected');
        var replace = document.getElementById('replaceExisting');

        function refresh() {
            counter.textContent = boxes.filter(function (b) { return b.checked; }).length;
        }

        form.querySelectorAll('[data-select]').forEach(function (button) {
            button.addEventListener('click', function () {
                var mode = button.getAttribute('data-select');
                boxes.forEach(function (b) {
                    b.checked = mode === 'all' || (mode === 'none-pin' && b.getAttribute('data-state') === 'none');
                });
                refresh();
            });
        });
        boxes.forEach(function (b) { b.addEventListener('change', refresh); });
        refresh();

        form.addEventListener('submit', function (event) {
            var ticked = boxes.filter(function (b) { return b.checked; });
            if (!ticked.length) {
                event.preventDefault();
                alert('Tick at least one learner.');
                return;
            }
            var withPin = ticked.filter(function (b) { return b.getAttribute('data-state') !== 'none'; }).length;
            if (replace.checked && withPin > 0 &&
                !confirm(withPin + ' learner(s) already have a PIN. Their old PIN will stop working. Continue?')) {
                event.preventDefault();
            }
        });
    })();
</script>
@endpush
