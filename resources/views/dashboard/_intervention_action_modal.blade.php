{{--
    One modal serves every row in the Action Center. The row's button carries the
    log's details in data attributes and the script below fills this in, so a
    class with thirty open interventions does not put thirty modals in the DOM.
--}}
<div class="modal fade" id="interventionActionModal" tabindex="-1" aria-labelledby="interventionActionLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" id="interventionActionForm" class="modal-content border-0 shadow">
            @csrf
            @method('PUT')
            <input type="hidden" name="action" id="interventionAction" value="">

            <div class="modal-header border-bottom-0 pb-0">
                <div>
                    <h5 class="modal-title fw-bold" id="interventionActionLabel">Record progress</h5>
                    <p class="text-muted small mb-0" id="interventionActionContext"></p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <div class="bg-light rounded p-3 mb-3">
                    <div class="fw-semibold" id="interventionActionName"></div>
                    <div class="small text-muted mt-1" id="interventionActionStatus"></div>
                </div>

                <label class="form-label small fw-semibold text-muted text-uppercase">What happened?</label>
                <div class="d-grid gap-2 mb-3" id="interventionChoices">
                    <button type="button" class="btn btn-outline-primary text-start js-choice" data-choice="start">
                        <i class="bi bi-play-circle me-2"></i>
                        <strong>We have started this</strong>
                        <span class="d-block small text-muted ms-4">Keeps it on your list and starts counting from today.</span>
                    </button>
                    <button type="button" class="btn btn-outline-success text-start js-choice" data-choice="complete">
                        <i class="bi bi-check-circle me-2"></i>
                        <strong>We finished this</strong>
                        <span class="d-block small text-muted ms-4">Closes it and records how well it worked.</span>
                    </button>
                    <button type="button" class="btn btn-outline-secondary text-start js-choice" data-choice="skip">
                        <i class="bi bi-skip-forward-circle me-2"></i>
                        <strong>We are skipping this</strong>
                        <span class="d-block small text-muted ms-4">Removes it from your list with a reason on record.</span>
                    </button>
                </div>

                {{-- Shown only after "We finished this" --}}
                <div class="d-none" id="interventionCompleteFields">
                    <label for="effectiveness_rating" class="form-label small fw-semibold">
                        How well did it work for this learner?
                    </label>
                    <div class="d-flex align-items-center gap-3 mb-1">
                        <input type="range" class="form-range" min="1" max="10" step="1" value="7"
                               name="effectiveness_rating" id="effectiveness_rating">
                        <span class="badge bg-primary fs-6" id="effectivenessValue">7</span>
                    </div>
                    <div class="d-flex justify-content-between small text-muted mb-3">
                        <span>1 — no help at all</span>
                        <span>10 — worked very well</span>
                    </div>
                    <p class="small text-muted">
                        This rating feeds the intervention's effectiveness score, so other teachers
                        see what actually helps.
                    </p>
                    <label for="notes" class="form-label small fw-semibold">Notes <span class="text-muted">(optional)</span></label>
                    <textarea name="notes" id="notes" rows="3" class="form-control"
                              placeholder="What did you notice? e.g. Still struggles with long vowel sounds."></textarea>
                </div>

                {{-- Shown only after "We are skipping this" --}}
                <div class="d-none" id="interventionSkipFields">
                    <label for="reason" class="form-label small fw-semibold">Why are you skipping it?</label>
                    <textarea name="reason" id="reason" rows="3" class="form-control"
                              placeholder="e.g. Learner moved to another section."></textarea>
                    <div class="form-text">A short reason keeps the learner's record clear for whoever reads it next.</div>
                </div>
            </div>

            <div class="modal-footer border-top-0 pt-0">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary d-none" id="interventionActionSubmit">Save</button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const modalEl = document.getElementById('interventionActionModal');
    if (!modalEl) return;

    const form       = document.getElementById('interventionActionForm');
    const actionIn   = document.getElementById('interventionAction');
    const choices    = document.getElementById('interventionChoices');
    const submit     = document.getElementById('interventionActionSubmit');
    const completeFs = document.getElementById('interventionCompleteFields');
    const skipFs     = document.getElementById('interventionSkipFields');
    const reason     = document.getElementById('reason');
    const rating     = document.getElementById('effectiveness_rating');
    const ratingOut  = document.getElementById('effectivenessValue');

    // The route needs the log id, so the row hands us a ready-made URL.
    modalEl.addEventListener('show.bs.modal', function (event) {
        const btn = event.relatedTarget;
        if (!btn) return;

        form.setAttribute('action', btn.dataset.url);
        document.getElementById('interventionActionName').textContent    = btn.dataset.name;
        document.getElementById('interventionActionStatus').textContent  = btn.dataset.tracking;
        document.getElementById('interventionActionContext').textContent = 'For ' + btn.dataset.learner;

        // "We have started this" is meaningless once it is already under way.
        const startBtn = choices.querySelector('[data-choice="start"]');
        startBtn.classList.toggle('d-none', btn.dataset.status !== 'pending');

        reset();
    });

    function reset() {
        actionIn.value = '';
        submit.classList.add('d-none');
        completeFs.classList.add('d-none');
        skipFs.classList.add('d-none');
        reason.removeAttribute('required');
        choices.querySelectorAll('.js-choice').forEach(b => b.classList.remove('active'));
    }

    choices.querySelectorAll('.js-choice').forEach(function (button) {
        button.addEventListener('click', function () {
            const choice = button.dataset.choice;
            actionIn.value = choice;

            choices.querySelectorAll('.js-choice').forEach(b => b.classList.toggle('active', b === button));
            completeFs.classList.toggle('d-none', choice !== 'complete');
            skipFs.classList.toggle('d-none', choice !== 'skip');

            // Server-side validation requires it too; this just saves a round trip.
            if (choice === 'skip') {
                reason.setAttribute('required', 'required');
            } else {
                reason.removeAttribute('required');
            }

            submit.classList.remove('d-none');
            submit.textContent = choice === 'start' ? 'Mark as started'
                               : choice === 'complete' ? 'Mark as finished'
                               : 'Skip this intervention';
            submit.className = 'btn ' + (choice === 'complete' ? 'btn-success' : choice === 'skip' ? 'btn-secondary' : 'btn-primary');
        });
    });

    if (rating && ratingOut) {
        rating.addEventListener('input', () => ratingOut.textContent = rating.value);
    }

    modalEl.addEventListener('hidden.bs.modal', reset);
});
</script>
@endpush
