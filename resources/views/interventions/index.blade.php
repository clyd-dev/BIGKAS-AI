@extends('layouts.app')

@section('title', 'Interventions')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-lightbulb me-2"></i>Interventions</h4>
    </div>

    {{-- Filters --}}
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" action="{{ route('interventions.index') }}" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small">Target Weakness</label>
                    <select name="target_weakness" class="form-select form-select-sm">
                        <option value="">All</option>
                        @foreach(config('bigkas.weakness_categories', []) as $id => $label)
                            <option value="{{ $id }}" {{ request('target_weakness') == $id ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Type</label>
                    <select name="type" class="form-select form-select-sm">
                        <option value="">All</option>
                        <option value="activity" {{ request('type') === 'activity' ? 'selected' : '' }}>Activity</option>
                        <option value="exercise" {{ request('type') === 'exercise' ? 'selected' : '' }}>Exercise</option>
                        <option value="strategy" {{ request('type') === 'strategy' ? 'selected' : '' }}>Strategy</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-sm btn-outline-primary w-100"><i class="bi bi-search me-1"></i> Filter</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Interventions Grid --}}
    <div class="row g-3">
        @forelse($interventions ?? [] as $intervention)
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <h6 class="card-title mb-0">{{ $intervention->name }}</h6>
                            <span class="badge bg-primary">{{ ucfirst($intervention->type ?? 'activity') }}</span>
                        </div>
                        <p class="text-muted small mb-2">{{ Str::limit($intervention->description, 120) }}</p>
                        <div class="d-flex gap-2 flex-wrap">
                            @php
                                $weaknessLabels = config('bigkas.weakness_categories', []);
                            @endphp
                            <span class="badge bg-warning text-dark">
                                <i class="bi bi-crosshair me-1"></i>{{ $weaknessLabels[$intervention->target_weakness] ?? 'General' }}
                            </span>
                            <span class="badge bg-light text-dark">
                                <i class="bi bi-clock me-1"></i>{{ $intervention->duration_minutes ?? '?' }} min
                            </span>
                            <span class="badge bg-light text-dark">
                                {{ ucfirst($intervention->difficulty ?? 'medium') }}
                            </span>
                        </div>
                    </div>
                    <div class="card-footer bg-white border-top-0">
                        <a href="{{ route('interventions.show', $intervention) }}" class="btn btn-sm btn-outline-primary w-100">
                            <i class="bi bi-eye me-1"></i> View Details
                        </a>
                    </div>
                </div>
            </div>
        @empty
            <div class="col-12">
                <div class="text-center text-muted py-5">
                    <i class="bi bi-lightbulb display-4"></i>
                    <p class="mt-2">No interventions found.</p>
                </div>
            </div>
        @endforelse
    </div>
@endsection
