@extends('layouts.app')

@section('title', 'Analysis Engine')

@section('content')
<x-page-header title="Analysis Engine" icon="bi-activity"
               subtitle="Whether recordings can be scored, what is waiting, and every time it stopped" />

@php
    $levelColor = ['healthy' => 'success', 'degraded' => 'warning', 'down' => 'danger'];
    $stateColor = ['online' => 'success', 'degraded' => 'warning', 'offline' => 'danger', 'disabled' => 'secondary'];
@endphp

<div class="row g-3 mb-4">
    @foreach($status['services'] as $service)
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex gap-3">
                    <span class="rounded bg-{{ $stateColor[$service['state']] ?? 'secondary' }} bg-opacity-10 text-{{ $stateColor[$service['state']] ?? 'secondary' }} d-flex align-items-center justify-content-center flex-shrink-0" style="width:44px;height:44px;">
                        <i class="bi {{ $service['icon'] }} fs-4"></i>
                    </span>
                    <div>
                        <div class="fw-semibold">{{ $service['label'] }}
                            <span class="badge bg-{{ $stateColor[$service['state']] ?? 'secondary' }} ms-1">{{ $service['headline'] }}</span>
                        </div>
                        <div class="small text-muted">{{ $service['detail'] }}</div>
                    </div>
                </div>
            </div>
        </div>
    @endforeach
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
        <span><i class="bi bi-hourglass-split me-1"></i> Waiting to be scored</span>
        <span class="badge bg-{{ $waiting->isEmpty() ? 'success' : 'warning text-dark' }}">{{ $status['backlog']['waiting'] }}</span>
    </div>
    <div class="card-body">
        <p class="small text-muted mb-3">
            Recordings are always saved, even while the engine is down. They are scored again automatically,
            oldest first, as soon as it is back. Nothing is estimated in the meantime.
        </p>
        @if($waiting->isEmpty())
            <div class="text-success small"><i class="bi bi-check-circle me-1"></i> Nothing is waiting.</div>
        @else
            @include('admin._rescore_table', ['rows' => $waiting, 'showAttempts' => false])
        @endif
    </div>
</div>

@if($givenUp->isNotEmpty())
    <div class="card border-0 shadow-sm mb-4 border-start border-danger border-4">
        <div class="card-header bg-white fw-semibold text-danger">
            <i class="bi bi-exclamation-octagon me-1"></i> Could not be scored ({{ $givenUp->count() }})
        </div>
        <div class="card-body">
            <p class="small text-muted mb-3">
                These failed {{ $maxAttempts }} times while the engine was running, so the recording itself is
                probably the problem (damaged or empty). The teacher can still press Analyze, or the child can read again.
            </p>
            @include('admin._rescore_table', ['rows' => $givenUp, 'showAttempts' => true])
        </div>
    </div>
@endif

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-clock-history me-1"></i> Engine history</div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead class="table-light">
                <tr><th>When</th><th>State</th><th>What happened</th><th>Admin told</th></tr>
            </thead>
            <tbody>
                @forelse($events as $event)
                    <tr>
                        <td class="text-nowrap small">
                            {{ $event->created_at->format('M j, g:i A') }}
                            <div class="text-secondary" style="font-size: 0.7rem;">{{ $event->created_at->diffForHumans() }}</div>
                        </td>
                        <td><span class="badge bg-{{ $levelColor[$event->level] ?? 'secondary' }}">{{ ucfirst($event->level) }}</span></td>
                        <td class="small">{{ $event->message }}</td>
                        <td class="small text-nowrap">{{ $event->alerted_at ? $event->alerted_at->diffForHumans() : '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="text-center text-muted py-4 small">
                        Nothing recorded yet. The history starts once the scheduler is running
                        (<code>php artisan schedule:run</code> every minute).
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
