{{-- Shown to a teacher who has not been given a grade & section yet. --}}
@if(auth()->user()?->isTeacher() && ! auth()->user()->hasAssignedClass())
    <div class="alert alert-warning d-flex align-items-start gap-3" role="status">
        <i class="bi bi-hourglass-split fs-4"></i>
        <div>
            <strong>Waiting for your grade &amp; section.</strong>
            The principal has been told about your new account. Until a grade and section is assigned to you,
            adding learners and conducting assessments are turned off. You can still look around.
        </div>
    </div>
@endif
