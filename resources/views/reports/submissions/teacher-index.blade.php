@extends('layouts.app')

@section('title', 'My Reports to Principal')

@section('content')
    <x-page-header title="My Reports to Principal" icon="bi-send"
                   :back="route('reports.index')" back-label="Reports">
        <x-slot:actions>
            <a href="{{ route('reports.submissions.create') }}" class="btn btn-primary btn-sm">
                <i class="bi bi-plus-circle me-1"></i> Submit New Report
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Grade &amp; Section</th>
                            <th>Period</th>
                            <th>Submitted</th>
                            <th>Status</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($reports as $report)
                            <tr>
                                <td class="fw-semibold">Grade {{ $report->schoolClass?->grade_level }} – {{ $report->schoolClass?->section }}</td>
                                <td>{{ $report->periodLabel() }}<div class="small text-muted">{{ $report->school_year }}</div></td>
                                <td>{{ $report->submitted_at?->format('M d, Y') }}</td>
                                <td>@include('reports.submissions._status', ['status' => $report->status])</td>
                                <td class="text-end">
                                    <a href="{{ route('reports.submissions.show', $report) }}" class="btn btn-sm btn-primary px-3">
                                        <i class="bi bi-eye me-1"></i> View
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-muted py-4">You haven't submitted a report yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="d-flex flex-wrap justify-content-between align-items-center mt-3 gap-2">
        <div class="small text-muted">
            @if($reports->total() > 0)
                Showing {{ $reports->firstItem() }}–{{ $reports->lastItem() }} of {{ $reports->total() }}
            @endif
        </div>
        {{ $reports->onEachSide(1)->links('partials.pagination') }}
    </div>
@endsection
