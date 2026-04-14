@extends('layouts.app')

@section('title', 'My Assessments')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-clipboard-data me-2"></i>My Assessments</h4>
    </div>

    @if($learner ?? false)
        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Date</th>
                                <th>Material</th>
                                <th>Language</th>
                                <th>Accuracy</th>
                                <th>WPM</th>
                                <th>Reading Level</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($assessments ?? [] as $assessment)
                                @php $result = $assessment->results->first(); @endphp
                                <tr>
                                    <td>{{ $assessment->created_at?->format('M d, Y') }}</td>
                                    <td>{{ $assessment->material?->title ?? 'N/A' }}</td>
                                    <td>{{ ucfirst($assessment->language) }}</td>
                                    <td>
                                        @if($result?->accuracy_rate)
                                            <span class="{{ $result->accuracy_rate >= 90 ? 'text-success' : 'text-danger' }} fw-bold">
                                                {{ number_format($result->accuracy_rate, 1) }}%
                                            </span>
                                        @else
                                            -
                                        @endif
                                    </td>
                                    <td>{{ $result?->words_per_minute ?? '-' }}</td>
                                    <td>
                                        @if($result?->reading_level === 'independent')
                                            <span class="badge bg-success">Independent</span>
                                        @elseif($result?->reading_level === 'instructional')
                                            <span class="badge bg-warning text-dark">Instructional</span>
                                        @elseif($result?->reading_level === 'frustration')
                                            <span class="badge bg-danger">Frustration</span>
                                        @else
                                            <span class="badge bg-secondary">Pending</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if($result)
                                            <a href="{{ route('student.assessments.results', $assessment) }}" class="btn btn-sm btn-outline-primary">
                                                <i class="bi bi-eye me-1"></i> View
                                            </a>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-4">
                                        <i class="bi bi-clipboard-x display-6 d-block mb-2"></i>
                                        No assessments yet. Your teacher will conduct assessments for you.
                                    </td>
                                </tr>
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
