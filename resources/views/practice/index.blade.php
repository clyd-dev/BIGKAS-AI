@extends('layouts.app')

@section('title', 'Practice Center')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-controller me-2"></i>Practice Center</h4>
    </div>

    <div class="row g-4">
        {{-- Phonemic Awareness --}}
        <div class="col-md-4">
            <a href="{{ route('practice.phonemic') }}" class="card border-0 shadow-sm text-decoration-none h-100">
                <div class="card-body text-center py-5">
                    <i class="bi bi-ear display-3 text-primary"></i>
                    <h5 class="mt-3">Phonemic Awareness</h5>
                    <p class="text-muted small mb-0">Practice identifying and manipulating individual sounds in words.</p>
                </div>
                <div class="card-footer bg-primary text-white text-center">
                    <i class="bi bi-play-fill me-1"></i> Start Practice
                </div>
            </a>
        </div>

        {{-- Sight Words --}}
        <div class="col-md-4">
            <a href="{{ route('practice.sight-words') }}" class="card border-0 shadow-sm text-decoration-none h-100">
                <div class="card-body text-center py-5">
                    <i class="bi bi-eye display-3 text-success"></i>
                    <h5 class="mt-3">Sight Words</h5>
                    <p class="text-muted small mb-0">Practice recognizing common sight words by grade level.</p>
                </div>
                <div class="card-footer bg-success text-white text-center">
                    <i class="bi bi-play-fill me-1"></i> Start Practice
                </div>
            </a>
        </div>

        {{-- Guided Reading --}}
        <div class="col-md-4">
            <a href="{{ route('practice.reading') }}" class="card border-0 shadow-sm text-decoration-none h-100">
                <div class="card-body text-center py-5">
                    <i class="bi bi-book display-3 text-warning"></i>
                    <h5 class="mt-3">Guided Reading</h5>
                    <p class="text-muted small mb-0">Practice reading passages with adjustable font size and pacing.</p>
                </div>
                <div class="card-footer bg-warning text-dark text-center">
                    <i class="bi bi-play-fill me-1"></i> Start Practice
                </div>
            </a>
        </div>
    </div>

    {{-- Recent Practice Sessions --}}
    <div class="card border-0 shadow-sm mt-4">
        <div class="card-header bg-white">
            <h6 class="mb-0"><i class="bi bi-clock-history me-1"></i> Recent Practice Sessions</h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
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
                                <td>{{ $session->learner?->full_name ?? 'N/A' }}</td>
                                <td>{{ ucfirst(str_replace('_', ' ', $session->session_type)) }}</td>
                                <td>
                                    @if($session->score !== null)
                                        <span class="badge {{ $session->score >= 80 ? 'bg-success' : ($session->score >= 60 ? 'bg-warning text-dark' : 'bg-danger') }}">
                                            {{ $session->score }}%
                                        </span>
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                                <td>{{ $session->time_spent ? gmdate('i:s', $session->time_spent) : '-' }}</td>
                                <td>{{ $session->created_at?->diffForHumans() }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">No practice sessions yet</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
