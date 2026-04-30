@extends('layouts.app')

@section('title', 'Learner Portal Oversight')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-person-hearts me-2"></i>Learner Portal Oversight</h4>
        <a href="{{ route('admin.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i> Admin Panel
        </a>
    </div>

    {{-- Filters --}}
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body py-2">
            <form method="GET" action="{{ route('admin.learner-portal') }}" class="row g-2 align-items-end">
                <div class="col-md-4">
                    <input type="text" name="search" class="form-control form-control-sm"
                           placeholder="Search by name or LRN..." value="{{ request('search') }}">
                </div>
                <div class="col-md-3">
                    <select name="school_id" class="form-select form-select-sm">
                        <option value="">All Schools</option>
                        @foreach($schools as $school)
                            <option value="{{ $school->id }}" {{ request('school_id') == $school->id ? 'selected' : '' }}>
                                {{ $school->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2 d-flex gap-1">
                    <button type="submit" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-search"></i> Filter
                    </button>
                    <a href="{{ route('admin.learner-portal') }}" class="btn btn-sm btn-outline-secondary">Clear</a>
                </div>
            </form>
        </div>
    </div>

    {{-- Learners Table --}}
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <span class="fw-semibold">Learners — sorted by XP (highest first)</span>
            <span class="badge bg-secondary">{{ $learners->total() }} total</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 30px;">#</th>
                            <th>Learner</th>
                            <th>School / Class</th>
                            <th>Reading Level</th>
                            <th>PIN</th>
                            <th class="text-center">XP</th>
                            <th class="text-center">Streak</th>
                            <th class="text-center">Badges</th>
                            <th>Last Active</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php $rank = ($learners->currentPage() - 1) * $learners->perPage() + 1; @endphp
                        @forelse($learners as $learner)
                            @php
                                $levelInfo = $learner->getReadingLevelInfo();
                            @endphp
                            <tr>
                                <td class="text-muted small">{{ $rank++ }}</td>
                                <td>
                                    <div class="fw-semibold">{{ $learner->getFullName() }}</div>
                                    <div class="text-muted small">
                                        Grade {{ $learner->grade_level }}
                                        @if($learner->lrn)
                                            &middot; LRN: {{ $learner->lrn }}
                                        @endif
                                    </div>
                                </td>
                                <td class="small text-muted">
                                    {{ $learner->school?->name ?? '-' }}<br>
                                    {{ $learner->schoolClass?->name ?? '-' }}
                                </td>
                                <td>
                                    <span class="badge"
                                          style="background-color: {{ $levelInfo['color'] ?? '#6c757d' }};">
                                        {{ $levelInfo['name'] ?? 'Not Assessed' }}
                                    </span>
                                </td>
                                <td>
                                    @if($learner->pin)
                                        <code class="user-select-all">{{ $learner->pin }}</code>
                                    @else
                                        <span class="text-muted small">No PIN</span>
                                    @endif
                                </td>
                                <td class="text-center">
                                    <span class="fw-bold text-primary">{{ number_format($learner->total_xp) }}</span>
                                </td>
                                <td class="text-center">
                                    @if($learner->current_streak > 0)
                                        <span title="Current: {{ $learner->current_streak }} / Longest: {{ $learner->longest_streak }}">
                                            🔥 {{ $learner->current_streak }}
                                        </span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-warning text-dark">
                                        🏅 {{ $learner->badges_count }}
                                    </span>
                                </td>
                                <td class="small text-muted">
                                    {{ $learner->last_activity_date?->diffForHumans() ?? 'Never' }}
                                </td>
                                <td class="text-end">
                                    <div class="d-flex gap-1 justify-content-end">
                                        {{-- Generate / Regenerate PIN --}}
                                        <form method="POST"
                                              action="{{ route('admin.learner-portal.generate-pin', $learner) }}">
                                            @csrf
                                            <button type="submit"
                                                    class="btn btn-sm btn-outline-primary"
                                                    title="{{ $learner->pin ? 'Regenerate PIN' : 'Generate PIN' }}"
                                                    onclick="return confirm('{{ $learner->pin ? 'Regenerate PIN for ' . addslashes($learner->getFullName()) . '? The old PIN will stop working.' : 'Generate PIN for ' . addslashes($learner->getFullName()) . '?' }}')">
                                                <i class="bi bi-key"></i>
                                            </button>
                                        </form>

                                        {{-- Reset XP & Streak --}}
                                        <form method="POST"
                                              action="{{ route('admin.learner-portal.reset-xp', $learner) }}">
                                            @csrf
                                            <button type="submit"
                                                    class="btn btn-sm btn-outline-danger"
                                                    title="Reset XP & Streak"
                                                    onclick="return confirm('Reset XP and streak for {{ addslashes($learner->getFullName()) }}? This cannot be undone.')">
                                                <i class="bi bi-arrow-counterclockwise"></i>
                                            </button>
                                        </form>

                                        {{-- View progress --}}
                                        <a href="{{ route('learners.progress', $learner) }}"
                                           class="btn btn-sm btn-outline-secondary"
                                           title="View Progress">
                                            <i class="bi bi-graph-up"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="text-center text-muted py-5">
                                    <i class="bi bi-people display-6 d-block mb-2"></i>
                                    No learners found
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="mt-3">{{ $learners->withQueryString()->links() }}</div>

    {{-- Legend --}}
    <div class="card border-0 shadow-sm mt-4">
        <div class="card-body py-2">
            <div class="d-flex flex-wrap gap-3 align-items-center small text-muted">
                <span><i class="bi bi-key text-primary me-1"></i> Generate PIN — creates/replaces student login PIN</span>
                <span><i class="bi bi-arrow-counterclockwise text-danger me-1"></i> Reset XP — zeroes XP, streak, and last activity</span>
                <span><i class="bi bi-graph-up text-secondary me-1"></i> View Progress — opens full learner assessment history</span>
            </div>
        </div>
    </div>
@endsection
