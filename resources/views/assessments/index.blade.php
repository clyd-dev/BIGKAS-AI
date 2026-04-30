@extends('layouts.app')

@section('title', 'Assessments')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-clipboard-check me-2"></i>Assessments</h4>
        <a href="{{ route('assessments.create') }}" class="btn btn-primary">
            <i class="bi bi-plus-circle me-1"></i> New Assessment
        </a>
    </div>

    {{-- Filters --}}
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" action="{{ route('assessments.index') }}" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small">Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">All</option>
                        <option value="pending" {{ request('status') === 'pending' ? 'selected' : '' }}>Pending</option>
                        <option value="audio_uploaded" {{ request('status') === 'audio_uploaded' ? 'selected' : '' }}>Audio Uploaded</option>
                        <option value="completed" {{ request('status') === 'completed' ? 'selected' : '' }}>Completed</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Language</label>
                    <select name="language" class="form-select form-select-sm">
                        <option value="">All</option>
                        <option value="english" {{ request('language') === 'english' ? 'selected' : '' }}>English</option>
                        <option value="filipino" {{ request('language') === 'filipino' ? 'selected' : '' }}>Filipino</option>
                        <option value="hiligaynon" {{ request('language') === 'hiligaynon' ? 'selected' : '' }}>Hiligaynon</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-sm btn-outline-primary w-100"><i class="bi bi-search me-1"></i> Filter</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Assessments Table --}}
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Learner</th>
                            <th>Material</th>
                            <th>Language</th>
                            <th>Status</th>
                            <th>Accuracy</th>
                            <th>WPM</th>
                            <th>Level</th>
                            <th>Date</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($assessments as $assessment)
                            <tr>
                                <td>{{ $assessment->learner?->full_name ?? 'N/A' }}</td>
                                <td>{{ Str::limit($assessment->material?->title ?? 'N/A', 30) }}</td>
                                <td>{{ ucfirst($assessment->language) }}</td>
                                <td>
                                    @if($assessment->status === 'completed')
                                        <span class="badge bg-success">Completed</span>
                                    @elseif($assessment->status === 'audio_uploaded')
                                        <span class="badge bg-info">Audio Ready</span>
                                    @else
                                        <span class="badge bg-secondary">Pending</span>
                                    @endif
                                </td>
                                <td>{{ $assessment->accuracy_rate ?? '-' }}%</td>
                                <td>{{ $assessment->words_per_minute ?? '-' }}</td>
                                <td>
                                    @php $level = $assessment->reading_level; @endphp
                                    @if($level === 'independent')
                                        <span class="badge bg-success">Ind.</span>
                                    @elseif($level === 'instructional')
                                        <span class="badge bg-warning text-dark">Inst.</span>
                                    @elseif($level === 'frustration')
                                        <span class="badge bg-danger">Frus.</span>
                                    @else
                                        <span class="badge bg-secondary">-</span>
                                    @endif
                                </td>
                                <td>{{ $assessment->created_at?->format('M d, Y') }}</td>
                                <td class="text-end">
                                    @if($assessment->status === 'completed')
                                        <a href="{{ route('assessments.results', $assessment) }}" class="btn btn-sm btn-outline-primary" title="Results">
                                            <i class="bi bi-bar-chart"></i>
                                        </a>
                                    @elseif($assessment->status === 'audio_uploaded')
                                        <form method="POST" action="{{ route('assessments.analyze', $assessment) }}" class="d-inline">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-primary" title="Analyze">
                                                <i class="bi bi-cpu"></i> Analyze
                                            </button>
                                        </form>
                                    @else
                                        <a href="{{ route('assessments.show', $assessment) }}" class="btn btn-sm btn-outline-primary" title="Continue">
                                            <i class="bi bi-mic"></i>
                                        </a>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="text-center text-muted py-4">
                                    No assessments yet. <a href="{{ route('assessments.create') }}">Start your first assessment</a>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    @if(method_exists($assessments ?? collect(), 'links'))
        <div class="mt-3">{{ $assessments->links() }}</div>
    @endif
@endsection
