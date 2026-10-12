@extends('layouts.guest')

@section('title', 'Privacy Notice | BIGKAS-AI')

@section('content')
<section class="py-5">
    <div class="container" style="max-width: 820px;">
        <h1 class="fw-bold mb-2">Privacy Notice</h1>
        <p class="text-muted mb-4">BIGKAS-AI is a capstone prototype. This notice describes what the system actually does with data. It is not a substitute for a formal Data Privacy Act review by the school or division.</p>

        <h2 class="h4 fw-bold mt-4">What is collected</h2>
        <ul class="text-muted">
            <li><strong>Account details</strong> of teachers, parents and administrators: name, email, phone (stored encrypted), role.</li>
            <li><strong>Learner records</strong> entered by the school: name, LRN, grade, section, language. The learner's portal PIN is stored encrypted.</li>
            <li><strong>Reading recordings and results</strong>: the audio of the learner reading a passage, its transcript, the measured scores, comprehension answers, and the teacher's decision on each result.</li>
            <li><strong>Activity records</strong>: practice sessions, assigned activities, messages, and an audit log of key actions.</li>
        </ul>

        <h2 class="h4 fw-bold mt-4">Why it is used</h2>
        <p class="text-muted">To assess oral reading, recommend reading activities, show progress to the teacher and linked parent, and prepare DepEd Phil-IRI records. Results are suggestions that the teacher reviews and confirms.</p>

        <h2 class="h4 fw-bold mt-4">Who can see it</h2>
        <ul class="text-muted">
            <li>The teacher assigned to the learner's class.</li>
            <li>A parent or guardian linked to that learner, and only that learner.</li>
            <li>School administrators.</li>
        </ul>

        <h2 class="h4 fw-bold mt-4">How speech is processed</h2>
        <p class="text-muted">Recordings are transcribed by a speech model running on the school's own server. They are sent to an outside speech service only if the system operator has deliberately configured one.</p>

        <h2 class="h4 fw-bold mt-4">Safeguards in place</h2>
        <ul class="text-muted">
            <li>Role-based access, with each user limited to their own class or child.</li>
            <li>Encrypted identity fields and learner PINs; hashed passwords and one-time codes.</li>
            <li>Login rate limiting and temporary account lockout after repeated failures.</li>
            <li>Security headers and no browser caching of signed-in pages.</li>
        </ul>

        <h2 class="h4 fw-bold mt-4">What is still to be settled</h2>
        <ul class="text-muted">
            <li>A parental consent step and a retention period for voice recordings are to be agreed with the school and division before wide use.</li>
            <li>Questions about a learner's data should go to the school administrator.</li>
        </ul>

        <a href="{{ url('/') }}" class="btn btn-outline-primary mt-4">Back to home</a>
    </div>
</section>
@endsection
