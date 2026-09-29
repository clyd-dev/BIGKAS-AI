@extends('layouts.app')

@section('title', 'Practice Center')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="bi bi-controller me-2"></i>Practice Center</h4>
            <p class="text-muted mb-0">Reinforce reading skills with targeted activities</p>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-md-4">
            <a href="{{ route('practice.phonemic') }}" class="card border-0 shadow-sm text-decoration-none h-100 practice-card">
                <div class="card-body text-center py-5">
                    <div class="practice-icon bg-primary bg-opacity-10 text-primary">
                        <i class="bi bi-ear"></i>
                    </div>
                    <h5 class="mt-3 mb-2">Phonemic Awareness</h5>
                    <p class="text-muted small mb-0">Identify and manipulate individual sounds in words</p>
                </div>
                <div class="card-footer bg-primary bg-opacity-10 text-primary border-0 text-center py-3">
                    <i class="bi bi-play-fill me-1"></i> Start Practice
                </div>
            </a>
        </div>

        <div class="col-md-4">
            <a href="{{ route('practice.sight-words') }}" class="card border-0 shadow-sm text-decoration-none h-100 practice-card">
                <div class="card-body text-center py-5">
                    <div class="practice-icon bg-success bg-opacity-10 text-success">
                        <i class="bi bi-eye"></i>
                    </div>
                    <h5 class="mt-3 mb-2">Sight Words</h5>
                    <p class="text-muted small mb-0">Recognize common words by grade level</p>
                </div>
                <div class="card-footer bg-success bg-opacity-10 text-success border-0 text-center py-3">
                    <i class="bi bi-play-fill me-1"></i> Start Practice
                </div>
            </a>
        </div>

        <div class="col-md-4">
            <a href="{{ route('practice.reading') }}" class="card border-0 shadow-sm text-decoration-none h-100 practice-card">
                <div class="card-body text-center py-5">
                    <div class="practice-icon bg-warning bg-opacity-10 text-warning">
                        <i class="bi bi-book"></i>
                    </div>
                    <h5 class="mt-3 mb-2">Guided Reading</h5>
                    <p class="text-muted small mb-0">Read passages with AI-powered analysis</p>
                </div>
                <div class="card-footer bg-warning bg-opacity-10 text-warning border-0 text-center py-3">
                    <i class="bi bi-play-fill me-1"></i> Start Practice
                </div>
            </a>
        </div>
    </div>

    <div class="card border-0 shadow-sm mt-4">
        <div class="card-header bg-white py-3">
            <h6 class="mb-0"><i class="bi bi-clock-history me-2"></i>Recent Practice Sessions</h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Learner</th>
                            <th>Activity</th>
                            <th>Score</th>
                            <th>Duration</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($recentSessions ?? [] as $session)
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center me-2" style="width:32px;height:32px;">
                                            <i class="bi bi-person small"></i>
                                        </div>
                                        <span class="fw-medium">{{ $session->learner?->full_name ?? 'N/A' }}</span>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border">
                                        {{ ucfirst(str_replace('_', ' ', $session->session_type)) }}
                                    </span>
                                </td>
                                <td>
                                    @if($session->score !== null)
                                        <span class="badge {{ $session->score >= 80 ? 'bg-success' : ($session->score >= 60 ? 'bg-warning text-dark' : 'bg-danger') }}">
                                            {{ $session->score }}%
                                        </span>
                                    @else
                                        <span class="text-muted">&mdash;</span>
                                    @endif
                                </td>
                                <td>{{ $session->time_spent ? gmdate('i:s', $session->time_spent) : '&mdash;' }}</td>
                                <td>{{ $session->created_at?->diffForHumans() }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center text-muted py-5">
                                    <i class="bi bi-inbox display-4 d-block mb-2 opacity-25"></i>
                                    No practice sessions yet
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection

@push('styles')
<style>
    .practice-card {
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .practice-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 0.5rem 1.5rem rgba(0, 0, 0, 0.1) !important;
    }
    .practice-icon {
        width: 64px;
        height: 64px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto;
        font-size: 1.75rem;
    }
</style>
@endpush
