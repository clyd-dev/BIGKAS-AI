@extends('layouts.app')

@section('title', $intervention->name)

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-lightbulb me-2"></i>{{ $intervention->name }}</h4>
        <a href="{{ route('interventions.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Back</a>
    </div>

    <div class="row g-3">
        <div class="col-md-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white"><h6 class="mb-0">Description</h6></div>
                <div class="card-body">
                    <p>{{ $intervention->description }}</p>
                </div>
            </div>

            @if($intervention->instructions)
                <div class="card border-0 shadow-sm mt-3">
                    <div class="card-header bg-white"><h6 class="mb-0">Instructions</h6></div>
                    <div class="card-body">
                        {!! nl2br(e($intervention->instructions)) !!}
                    </div>
                </div>
            @endif

            {{-- Assign to Learner --}}
            <div class="card border-0 shadow-sm mt-3">
                <div class="card-header bg-white"><h6 class="mb-0">Assign to Learner</h6></div>
                <div class="card-body">
                    <form method="POST" action="{{ route('interventions.assign') }}">
                        @csrf
                        <input type="hidden" name="intervention_id" value="{{ $intervention->id }}">
                        <div class="row g-2">
                            <div class="col-md-5">
                                <select name="learner_id" class="form-select form-select-sm" required>
                                    <option value="">Select learner...</option>
                                    @foreach($learners ?? [] as $learner)
                                        <option value="{{ $learner->id }}">{{ $learner->full_name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-5">
                                <input type="text" name="notes" class="form-control form-control-sm" placeholder="Notes (optional)">
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-sm btn-primary w-100">Assign</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white"><h6 class="mb-0">Details</h6></div>
                <div class="card-body">
                    @php $weaknessLabels = config('bigkas.weakness_categories', []); @endphp
                    <table class="table table-borderless table-sm mb-0">
                        <tr><th class="text-muted">Target</th><td>{{ $weaknessLabels[$intervention->target_weakness]['name'] ?? 'General' }}</td></tr>
                        <tr><th class="text-muted">Type</th><td>{{ ucfirst($intervention->type ?? 'activity') }}</td></tr>
                        <tr><th class="text-muted">Difficulty</th><td>{{ ucfirst($intervention->difficulty ?? 'medium') }}</td></tr>
                        <tr><th class="text-muted">Duration</th><td>{{ $intervention->duration_minutes ?? '?' }} minutes</td></tr>
                    </table>
                </div>
            </div>

            {{-- Recent Assignments --}}
            <div class="card border-0 shadow-sm mt-3">
                <div class="card-header bg-white"><h6 class="mb-0">Recent Assignments</h6></div>
                <div class="card-body p-0">
                    <div class="list-group list-group-flush">
                        @forelse($recentLogs ?? [] as $log)
                            <div class="list-group-item">
                                <div class="d-flex justify-content-between">
                                    <strong class="small">{{ $log->learner?->full_name ?? 'N/A' }}</strong>
                                    <span class="badge {{ $log->status === 'completed' ? 'bg-success' : ($log->status === 'in_progress' ? 'bg-primary' : 'bg-secondary') }}">
                                        {{ ucfirst(str_replace('_', ' ', $log->status)) }}
                                    </span>
                                </div>
                                <small class="text-muted">{{ $log->created_at ? \Carbon\Carbon::parse($log->created_at)->diffForHumans() : '' }}</small>
                            </div>
                        @empty
                            <div class="list-group-item text-muted small text-center">No assignments yet</div>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
