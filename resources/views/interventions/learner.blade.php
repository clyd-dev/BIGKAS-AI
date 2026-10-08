@extends('layouts.app')

@section('title', 'Interventions - ' . $learner->full_name)

@section('content')
    <x-page-header :title="$learner->full_name" icon="bi-lightbulb" subtitle="Interventions"
                   :back="route('learners.show', $learner)" back-label="Learner profile" />

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Intervention</th>
                            <th>Target Weakness</th>
                            <th>Status</th>
                            <th>Assigned</th>
                            <th>Completed</th>
                            <th>Notes</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php $weaknessLabels = config('bigkas.weakness_categories', []); @endphp
                        @forelse($logs ?? [] as $log)
                            <tr>
                                <td><strong>{{ $log->intervention?->name ?? 'N/A' }}</strong></td>
                                <td>{{ $weaknessLabels[$log->intervention?->target_weakness]['name'] ?? 'General' }}</td>
                                <td>
                                    <span class="badge {{ $log->status === 'completed' ? 'bg-success' : ($log->status === 'in_progress' ? 'bg-primary' : ($log->status === 'skipped' ? 'bg-secondary' : 'bg-warning text-dark')) }}">
                                        {{ ucfirst(str_replace('_', ' ', $log->status)) }}
                                    </span>
                                </td>
                                <td>{{ $log->created_at ? \Carbon\Carbon::parse($log->created_at)->format('M d, Y') : '-' }}</td>
                                <td>{{ $log->completed_at ? \Carbon\Carbon::parse($log->completed_at)->format('M d, Y') : '-' }}</td>
                                <td>{{ Str::limit($log->notes, 40) }}</td>
                                <td class="text-end">
                                    @if($log->status !== 'completed')
                                        <form method="POST" action="{{ route('intervention-logs.update', $log) }}" class="d-inline">
                                            @csrf @method('PUT')
                                            <input type="hidden" name="status" value="completed">
                                            <button type="submit" class="btn btn-sm btn-success" title="Mark Complete">
                                                <i class="bi bi-check-lg"></i>
                                            </button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">No interventions assigned yet</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
