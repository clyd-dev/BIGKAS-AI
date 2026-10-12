@extends('layouts.guest')

@section('title', 'Empowering Every Learner\'s Reading Journey | BIGKAS-AI')

@push('styles')
<style>
    .hero-section {
        background: linear-gradient(135deg, #0d6efd 0%, #0047AB 100%);
        padding: 5rem 0;
    }
    .feature-icon {
        width: 64px;
        height: 64px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 16px;
        margin-bottom: 1.5rem;
    }
    .card-hover {
        transition: transform 0.3s ease, box-shadow 0.3s ease;
    }
    .card-hover:hover {
        transform: translateY(-5px);
        box-shadow: 0 .5rem 1rem rgba(0,0,0,.15)!important;
    }
    .portal-card {
        border-top: 4px solid #0d6efd;
    }
</style>
@endpush

@section('content')
    {{-- Hero Section --}}
    <section class="hero-section text-white position-relative overflow-hidden">
        <div class="container py-5 position-relative z-1">
            <div class="row align-items-center">
                <div class="col-lg-8 mx-auto text-center">
                    <span class="badge bg-white text-primary mb-3 px-3 py-2 rounded-pill shadow-sm">
                        <i aria-hidden="true" class="bi bi-stars me-1"></i> Smarter Reading Assessments
                    </span>
                    <h1 class="display-2 fw-bold mb-4 tracking-tight">BIGKAS-AI</h1>
                    <p class="lead mb-5 opacity-90 fw-medium" style="max-width: 900px; margin: 0 auto; line-height: 1.6;">
                        AN INTELLIGENT READING PROGRESS ASSESSMENT AND INTERVENTION SYSTEM USING MACHINE LEARNING FOR LEARNER MONITORING AND EDUCATIONAL DECISION SUPPORT
                    </p>
                    <div class="d-flex justify-content-center gap-3 flex-wrap">
                        <a href="{{ route('register') }}" class="btn btn-light btn-lg px-5 shadow-sm rounded-pill fw-medium">
                            Create an Account
                        </a>
                        <a href="{{ route('login') }}" class="btn btn-outline-light btn-lg px-5 rounded-pill fw-medium">
                            Login to Portal
                        </a>
                    </div>
                    <p class="mt-4 mb-0 small opacity-90">
                        Learner? <a href="{{ route('student.login') }}" class="text-white fw-semibold">Log in with your PIN</a>
                    </p>
                    <p class="mt-2 mb-0 small opacity-75">
                        Results are suggestions that the teacher reviews and confirms.
                    </p>
                </div>
            </div>
        </div>
        <!-- Decorative Background Elements -->
        <div class="position-absolute top-0 start-0 w-100 h-100 overflow-hidden" style="opacity: 0.1; pointer-events: none;">
            <i aria-hidden="true" class="bi bi-book position-absolute" style="font-size: 15rem; top: -5%; left: -5%; transform: rotate(-15deg);"></i>
            <i aria-hidden="true" class="bi bi-mic position-absolute" style="font-size: 12rem; bottom: 10%; right: -2%; transform: rotate(15deg);"></i>
        </div>
    </section>

    {{-- Key Benefits Section --}}
    <section id="features" class="py-5 bg-light">
        <div class="container py-5">
            <div class="text-center mb-5">
                <h2 class="fw-bold">Why Choose BIGKAS-AI?</h2>
                <p class="text-muted">Transform how reading is assessed and improved in your classroom and home.</p>
            </div>
            <div class="row g-4">
                <div class="col-md-4">
                    <div class="card h-100 border-0 shadow-sm card-hover p-4 rounded-4">
                        <div class="feature-icon bg-primary bg-opacity-10 text-primary">
                            <i aria-hidden="true" class="bi bi-clock-history fs-3"></i>
                        </div>
                        <h3 class="h4 fw-bold mb-3">Streamlined Reading Assessment</h3>
                        <p class="text-muted mb-0">Automate speech transcription and the calculation of reading metrics, such as words per minute and reading accuracy, to help reduce the time spent on manual scoring.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card h-100 border-0 shadow-sm card-hover p-4 rounded-4">
                        <div class="feature-icon bg-success bg-opacity-10 text-success">
                            <i aria-hidden="true" class="bi bi-bullseye fs-3"></i>
                        </div>
                        <h3 class="h4 fw-bold mb-3">Data-Driven Reading Insights</h3>
                        <p class="text-muted mb-0">Use machine learning-assisted analysis and reading assessment results to help identify learners' reading difficulties and guide appropriate interventions in areas such as phonemic awareness, decoding, and fluency.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card h-100 border-0 shadow-sm card-hover p-4 rounded-4">
                        <div class="feature-icon bg-warning bg-opacity-10 text-warning">
                            <i aria-hidden="true" class="bi bi-house-heart fs-3"></i>
                        </div>
                        <h3 class="h4 fw-bold mb-3">Connect Home and School</h3>
                        <p class="text-muted mb-0">Support collaboration between teachers and parents through learner progress dashboards, assessment results, and personalized home-based reading strategies.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- How It Works Section --}}
    <section class="py-5">
        <div class="container py-5">
            <h2 class="text-center fw-bold mb-5">How It Works</h2>
            <div class="row g-4 position-relative">
                <!-- Step 1 -->
                <div class="col-md-3 text-center">
                    <div class="d-inline-flex align-items-center justify-content-center bg-primary text-white rounded-circle mb-3 shadow" style="width: 60px; height: 60px; font-size: 1.5rem; z-index: 2; position: relative;">
                        1
                    </div>
                    <h3 class="h5 fw-bold">Record & Read</h3>
                    <p class="text-muted small px-2">A learner reads a grade-matched passage aloud. The teacher records it, or the learner records on their own device with a PIN.</p>
                </div>
                <!-- Step 2 -->
                <div class="col-md-3 text-center">
                    <div class="d-inline-flex align-items-center justify-content-center bg-primary text-white rounded-circle mb-3 shadow" style="width: 60px; height: 60px; font-size: 1.5rem; z-index: 2; position: relative;">
                        2
                    </div>
                    <h3 class="h5 fw-bold">AI-Assisted Reading Analysis</h3>
                    <p class="text-muted small px-2">The system transcribes recorded speech and analyzes reading accuracy, words per minute, and identified reading errors to support learner assessment.</p>
                </div>
                <!-- Step 3 -->
                <div class="col-md-3 text-center">
                    <div class="d-inline-flex align-items-center justify-content-center bg-primary text-white rounded-circle mb-3 shadow" style="width: 60px; height: 60px; font-size: 1.5rem; z-index: 2; position: relative;">
                        3
                    </div>
                    <h3 class="h5 fw-bold">Actionable Reports</h3>
                    <p class="text-muted small px-2">Prepares printable DepEd Phil-IRI records from the assessment data, ready for the teacher to review and complete.</p>
                </div>
                <!-- Step 4 -->
                <div class="col-md-3 text-center">
                    <div class="d-inline-flex align-items-center justify-content-center bg-primary text-white rounded-circle mb-3 shadow" style="width: 60px; height: 60px; font-size: 1.5rem; z-index: 2; position: relative;">
                        4
                    </div>
                    <h3 class="h5 fw-bold">Guided Interventions</h3>
                    <p class="text-muted small px-2">Provides tailored practice activities assigned directly to the student's dashboard.</p>
                </div>
            </div>
        </div>
    </section>

    {{-- Multilingual Support & Portals --}}
    <section class="bg-light py-5">
        <div class="container py-5">
            <div class="row align-items-center mb-5">
                <div class="col-lg-6 mb-4 mb-lg-0">
                    <h2 class="fw-bold mb-3">Supporting Philippine Learners</h2>
                    <p class="text-muted lead mb-4">
                        BIGKAS-AI supports automated oral reading assessment in English and Filipino.
                    </p>
                    <div class="d-flex gap-3">
                        <div class="d-flex align-items-center bg-white px-4 py-2 rounded-pill shadow-sm border">
                            <i aria-hidden="true" class="bi bi-check-circle-fill text-primary me-2"></i>
                            <span class="fw-medium">English</span>
                        </div>
                        <div class="d-flex align-items-center bg-white px-4 py-2 rounded-pill shadow-sm border">
                            <i aria-hidden="true" class="bi bi-check-circle-fill text-success me-2"></i>
                            <span class="fw-medium">Filipino (Tagalog)</span>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="card border-0 shadow-sm rounded-4 portal-card" id="teachers">
                        <div class="card-body p-4">
                            <h3 class="h4 fw-bold mb-3"><i aria-hidden="true" class="bi bi-person-video3 text-primary me-2"></i> For Teachers</h3>
                            <ul class="list-unstyled text-muted mb-0">
                                <li class="mb-2"><i aria-hidden="true" class="bi bi-arrow-right-short text-primary"></i> Streamlined class management & tracking.</li>
                                <li class="mb-2"><i aria-hidden="true" class="bi bi-arrow-right-short text-primary"></i> Printable Phil-IRI records: screening, Form 3A/3B and Form 4.</li>
                                <li class="mb-2"><i aria-hidden="true" class="bi bi-arrow-right-short text-primary"></i> Assign targeted interventions in one click.</li>
                                <li><i aria-hidden="true" class="bi bi-arrow-right-short text-primary"></i> Review, accept or override every AI result.</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4 justify-content-end">
                <div class="col-lg-6">
                    <div class="card border-0 shadow-sm rounded-4 portal-card" style="border-top-color: #198754;" id="parents">
                        <div class="card-body p-4">
                            <h3 class="h4 fw-bold mb-3"><i aria-hidden="true" class="bi bi-people text-success me-2"></i> For Parents</h3>
                            <ul class="list-unstyled text-muted mb-0">
                                <li class="mb-2"><i aria-hidden="true" class="bi bi-arrow-right-short text-success"></i> Clear, easy-to-read progress reports.</li>
                                <li class="mb-2"><i aria-hidden="true" class="bi bi-arrow-right-short text-success"></i> Simple home activities to reinforce learning.</li>
                                <li><i aria-hidden="true" class="bi bi-arrow-right-short text-success"></i> Direct communication with teachers.</li>
                            </ul>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="card border-0 shadow-sm rounded-4 portal-card" style="border-top-color: #ffc107;" id="students">
                        <div class="card-body p-4">
                            <h3 class="h4 fw-bold mb-3"><i aria-hidden="true" class="bi bi-backpack text-warning me-2"></i> For Students</h3>
                            <ul class="list-unstyled text-muted mb-0">
                                <li class="mb-2"><i aria-hidden="true" class="bi bi-arrow-right-short text-warning"></i> Fun, engaging practice center.</li>
                                <li class="mb-2"><i aria-hidden="true" class="bi bi-arrow-right-short text-warning"></i> Earn badges and track reading streaks.</li>
                                <li><i aria-hidden="true" class="bi bi-arrow-right-short text-warning"></i> PIN-based secure, easy login.</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

@endsection
