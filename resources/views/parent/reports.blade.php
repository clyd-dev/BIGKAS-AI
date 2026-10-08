@extends('layouts.parent')

@section('title', 'Reports')

@php use App\Support\ParentFriendly as PF; @endphp

@section('content')
<div class="pp">
    <h4 class="mb-1"><i class="bi bi-bar-chart me-2"></i>Reports</h4>
    <p class="text-muted">A report sums up how your child is reading. You can read it, save it as a PDF, or send it to the teacher.</p>

    @forelse($learners as $learner)
        @php
            $latest = $latestByLearner[$learner->id] ?? null;
            $lv     = PF::level($latest?->reading_level ?? $learner->reading_level);
        @endphp
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <div class="d-flex gap-3 align-items-center mb-3">
                    <span style="font-size:2.2rem" aria-hidden="true">{{ $lv['emoji'] }}</span>
                    <div class="flex-grow-1">
                        <div class="fw-bold fs-5">{{ $learner->full_name }}</div>
                        <div class="text-muted small">Grade {{ $learner->grade_level }}</div>
                        <span class="pp-pill pp-{{ $lv['tone'] }} mt-1">{{ $lv['title'] }}</span>
                    </div>
                </div>
                <div class="d-grid gap-2 d-sm-flex">
                    <a href="{{ route('reports.learner', $learner) }}" class="btn btn-primary"><i class="bi bi-file-earmark-text"></i> Open report</a>
                    <a href="{{ route('reports.pdf', $learner) }}" class="btn btn-outline-danger"><i class="bi bi-file-earmark-pdf"></i> Save as PDF</a>
                </div>
            </div>
        </div>
    @empty
        <div class="card border-0 shadow-sm"><div class="card-body text-center py-5">
            <div style="font-size:2.5rem" aria-hidden="true">📄</div>
            <h5 class="mt-2">No reports yet</h5>
            <p class="text-muted mb-0">Reports appear once a child is linked to your account.</p>
        </div></div>
    @endforelse
</div>
@endsection
