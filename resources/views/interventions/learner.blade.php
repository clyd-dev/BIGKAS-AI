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
                                    <span class="badge bg-{{ $log->getStatusColor() }}">{{ $log->getStatusDisplay() }}</span>
                                    <div class="small text-muted mt-1">{{ $log->trackingSummary() }}</div>
                                    @if($log->effectiveness_rating)
                                        <div class="small text-success mt-1">
                                            <i class="bi bi-star-fill me-1"></i>Rated {{ $log->effectiveness_rating }}/10
                                        </div>
                                    @endif
                                </td>
                                <td>{{ $log->created_at ? \Carbon\Carbon::parse($log->created_at)->format('M d, Y') : '-' }}</td>
                                <td>{{ $log->completed_at ? \Carbon\Carbon::parse($log->completed_at)->format('M d, Y') : '-' }}</td>
                                <td>{{ Str::limit($log->notes, 40) }}</td>
                                <td class="text-end">
                                    {{-- These post `action`, which is what updateLog() reads. The old
                                         form posted `status` and silently did nothing. --}}
                                    @if($log->isOpen())
                                        <div class="btn-group btn-group-sm">
                                            @if($log->status === 'pending')
                                                <form method="POST" action="{{ route('intervention-logs.update', $log) }}" class="d-inline">
                                                    @csrf @method('PUT')
                                                    <input type="hidden" name="action" value="start">
                                                    <button type="submit" class="btn btn-outline-primary" title="Mark as started">
                                                        <i class="bi bi-play-fill"></i>
                                                    </button>
                                                </form>
                                            @endif
                                            <form method="POST" action="{{ route('intervention-logs.update', $log) }}" class="d-inline">
                                                @csrf @method('PUT')
                                                <input type="hidden" name="action" value="complete">
                                                <button type="submit" class="btn btn-success" title="Mark complete">
                                                    <i class="bi bi-check-lg"></i>
                                                </button>
                                            </form>
                                        </div>
                                    @else
                                        <span class="text-muted small">&mdash;</span>
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
