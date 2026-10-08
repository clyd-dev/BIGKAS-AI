@extends('layouts.app')

@section('title', 'Interventions')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-lightbulb me-2"></i>Interventions</h4>
        <a href="{{ route('dashboard') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Dashboard</a>
    </div>

    <div class="alert alert-info">
        <i class="bi bi-info-circle me-1"></i> Read-only overview. Creating, editing, and deleting interventions is managed by teachers.
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white"><h6 class="mb-0">Interventions ({{ $interventions->total() }})</h6></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr><th>Name</th><th>Target</th><th>Activity Type</th><th>Grade Level</th><th>Duration</th></tr>
                    </thead>
                    <tbody>
                        @forelse($interventions as $intervention)
                            <tr>
                                <td><strong>{{ $intervention->name }}</strong></td>
                                <td>{{ $weaknessCategories[$intervention->target_weakness]['name'] ?? 'General' }}</td>
                                <td>{{ $intervention->getActivityTypeName() }}</td>
                                <td>{{ $intervention->getGradeLevelRange() }}</td>
                                <td>{{ $intervention->getDurationDisplay() }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-muted py-4">No interventions</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    @if(method_exists($interventions, 'links'))
        <div class="mt-3">{{ $interventions->links() }}</div>
    @endif
@endsection
