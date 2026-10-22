@extends('layouts.app')

@section('title', 'Group Assessments')

{{--
    The teacher's own batches, newest first, each a link back to its board.

    This page exists because there was no way back: a teacher who closed a board
    and went somewhere else had to type the URL in again. The same table idiom as
    every other list in this app (assessments, learners), the same pagination
    partial, so nothing here is a new pattern to learn.

    Expects: $batches (paginator of AssessmentBatch) and $progress, keyed by batch
    id with ['total', 'done'] - counted in the controller from BatchEntryState, so
    this page cannot claim a batch is finished while its board shows work left.
--}}
@section('content')
    <x-page-header title="Group Assessments" icon="bi-people"
                   :back="route('assessments.index')" back-label="Assessments"
                   subtitle="Every group you have set up. Open one to go back to its board.">
        <x-slot:actions>
            <a href="{{ route('assessments.batch.create') }}" class="btn btn-primary btn-sm">
                <i class="bi bi-plus-circle me-1"></i> New group
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Group</th>
                            <th>Progress</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($batches as $batch)
                            @php
                                $counts = $progress[$batch->id] ?? ['total' => 0, 'done' => 0];
                                $open = ! in_array($batch->status, [
                                    \App\Models\AssessmentBatch::STATUS_COMPLETED,
                                    \App\Models\AssessmentBatch::STATUS_CANCELLED,
                                ], true);
                            @endphp
                            <tr>
                                <td>
                                    <div class="fw-semibold">{{ $batch->title ?: 'Group Assessment' }}</div>
                                    <div class="small text-muted">
                                        Started {{ $batch->created_at?->format('M d, Y g:ia') }}
                                        @if($batch->defaultMaterial)
                                            · {{ $batch->defaultMaterial->title }}
                                        @endif
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center gap-2 flex-wrap">
                                        @if($batch->status === \App\Models\AssessmentBatch::STATUS_CANCELLED)
                                            <span class="badge bg-secondary">Cancelled</span>
                                        @elseif($batch->status === \App\Models\AssessmentBatch::STATUS_COMPLETED)
                                            <span class="badge bg-success">Finished</span>
                                        @elseif($batch->status === \App\Models\AssessmentBatch::STATUS_ANALYZING)
                                            <span class="badge bg-warning text-dark">Being analysed</span>
                                        @else
                                            <span class="badge bg-info text-dark">Collecting recordings</span>
                                        @endif

                                        <span class="small text-muted">
                                            {{ $counts['done'] }} of {{ $counts['total'] }} done
                                        </span>

                                        @if($open)
                                            <span class="small fw-semibold text-primary">still open</span>
                                        @endif
                                    </div>
                                </td>
                                <td class="text-end">
                                    <a href="{{ route('assessments.batch.board', $batch) }}" class="btn btn-sm btn-primary px-3">
                                        <i class="bi bi-grid-3x3-gap me-1"></i> Open board
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="text-center text-muted py-5">
                                    <i class="bi bi-people fs-1 d-block mb-2"></i>
                                    <p class="mb-1">No group assessments yet.</p>
                                    <p class="small mb-0">
                                        <a href="{{ route('assessments.batch.create') }}">Set up a group</a>
                                        to assess a whole class in one sitting.
                                    </p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Pagination --}}
    <div class="d-flex flex-wrap justify-content-between align-items-center mt-3 gap-2">
        <div class="small text-muted">
            @if($batches->total() > 0)
                Showing {{ $batches->firstItem() }}–{{ $batches->lastItem() }} of {{ $batches->total() }}
            @endif
        </div>
        {{ $batches->onEachSide(1)->links('partials.pagination') }}
    </div>
@endsection
