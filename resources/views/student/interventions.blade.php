@extends('layouts.app')

@section('title', 'My Interventions')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-lightbulb me-2"></i>My Interventions</h4>
    </div>

    @if($learner ?? false)
        {{-- Active Interventions --}}
        <h6 class="text-muted mb-3">Active Interventions</h6>
        <div class="row g-3 mb-4">
            @forelse($activeLogs ?? [] as $log)
                <div class="col-md-4">
                    <div class="card border-0 shadow-sm h-100 border-start border-primary border-4">
                        <div class="card-body">
                            <h6 class="card-title">{{ $log->intervention?->name ?? 'Intervention' }}</h6>
                            <p class="text-muted small">{{ Str::limit($log->intervention?->description, 100) }}</p>
                            <span class="badge {{ $log->status === 'in_progress' ? 'bg-primary' : 'bg-warning text-dark' }}">
                                {{ ucfirst(str_replace('_', ' ', $log->status)) }}
                            </span>
                            @if($log->intervention?->instructions)
                                <hr>
                                <p class="small mb-0"><strong>Instructions:</strong></p>
                                <p class="small text-muted">{{ Str::limit($log->intervention->instructions, 200) }}</p>
                            @endif
                        </div>
                        <div class="card-footer bg-white">
                            <small class="text-muted">Assigned {{ $log->assigned_at ? \Carbon\Carbon::parse($log->assigned_at)->diffForHumans() : '' }}</small>
                        </div>
                    </div>
                </div>
            @empty
                <div class="col-12">
                    <div class="alert alert-success">
                        <i class="bi bi-check-circle me-1"></i> No pending interventions. Keep up the good work!
                    </div>
                </div>
            @endforelse
        </div>

        {{-- Completed Interventions --}}
        <h6 class="text-muted mb-3">Completed Interventions</h6>
        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr><th>Intervention</th><th>Status</th><th>Assigned</th><th>Completed</th></tr>
                        </thead>
                        <tbody>
                            @forelse($completedLogs ?? [] as $log)
                                <tr>
                                    <td>{{ $log->intervention?->name ?? 'N/A' }}</td>
                                    <td><span class="badge bg-success">Completed</span></td>
                                    <td>{{ $log->assigned_at ? \Carbon\Carbon::parse($log->assigned_at)->format('M d, Y') : '-' }}</td>
                                    <td>{{ $log->completed_at ? \Carbon\Carbon::parse($log->completed_at)->format('M d, Y') : '-' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-center text-muted py-3">No completed interventions yet</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @else
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-5">
                <i class="bi bi-link-45deg display-1 text-muted"></i>
                <h4 class="mt-3">Account Not Linked</h4>
                <p class="text-muted">Your student account hasn't been linked to a learner profile yet.</p>
            </div>
        </div>
    @endif
@endsection
