@extends('layouts.guest')

@section('title', 'Welcome')

@section('content')
    {{-- Hero Section --}}
    <section class="bg-primary text-white py-5">
        <div class="container text-center py-5">
            <h1 class="display-4 fw-bold mb-3">BIGKAS</h1>
            <p class="lead mb-4">
                AI/ML-Assisted Reading Assessment and Intervention System<br>
                for Philippine Public Elementary and Secondary Schools
            </p>
            <div class="d-flex justify-content-center gap-3">
                <a href="{{ route('register') }}" class="btn btn-light btn-lg px-4">
                    <i class="bi bi-person-plus me-1"></i> Get Started
                </a>
                <a href="{{ route('login') }}" class="btn btn-outline-light btn-lg px-4">
                    <i class="bi bi-box-arrow-in-right me-1"></i> Login
                </a>
            </div>
        </div>
    </section>

    {{-- Features Section --}}
    <section class="py-5">
        <div class="container">
            <h2 class="text-center mb-5">How It Works</h2>
            <div class="row g-4">
                <div class="col-md-3">
                    <div class="card h-100 border-0 shadow-sm text-center p-4">
                        <div class="mb-3"><i class="bi bi-mic display-4 text-primary"></i></div>
                        <h5>Record Reading</h5>
                        <p class="text-muted small">Students read a passage aloud while their voice is recorded through the browser.</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card h-100 border-0 shadow-sm text-center p-4">
                        <div class="mb-3"><i class="bi bi-cpu display-4 text-success"></i></div>
                        <h5>AI Analysis</h5>
                        <p class="text-muted small">Speech-to-text and ML algorithms analyze accuracy, fluency, and error patterns.</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card h-100 border-0 shadow-sm text-center p-4">
                        <div class="mb-3"><i class="bi bi-clipboard-data display-4 text-warning"></i></div>
                        <h5>Classify Weaknesses</h5>
                        <p class="text-muted small">Identifies reading weaknesses: phonemic awareness, decoding, fluency, or comprehension.</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card h-100 border-0 shadow-sm text-center p-4">
                        <div class="mb-3"><i class="bi bi-lightbulb display-4 text-danger"></i></div>
                        <h5>Recommend Interventions</h5>
                        <p class="text-muted small">Suggests targeted interventions and practice activities for each learner.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- Supported Languages --}}
    <section class="bg-light py-5">
        <div class="container text-center">
            <h2 class="mb-4">Multilingual Support</h2>
            <div class="row justify-content-center g-3">
                <div class="col-auto">
                    <span class="badge bg-primary fs-6 px-3 py-2"><i class="bi bi-translate me-1"></i> English</span>
                </div>
                <div class="col-auto">
                    <span class="badge bg-success fs-6 px-3 py-2"><i class="bi bi-translate me-1"></i> Filipino</span>
                </div>
                <div class="col-auto">
                    <span class="badge bg-info fs-6 px-3 py-2"><i class="bi bi-translate me-1"></i> Hiligaynon</span>
                </div>
            </div>
            <p class="text-muted mt-3">Aligned with DepEd's Mother Tongue-Based Multilingual Education (MTB-MLE) program</p>
        </div>
    </section>

    {{-- Target Area --}}
    <section class="py-5">
        <div class="container text-center">
            <h2 class="mb-3">Serving Sagay City Division</h2>
            <p class="text-muted">Negros Occidental, Philippines</p>
            <p class="text-muted">Designed for learners in public elementary and secondary schools, aligned with Phil-IRI reading level standards.</p>
        </div>
    </section>
@endsection
