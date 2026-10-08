@extends('layouts.app')

@section('title', 'System Activity Logs')

@section('content')
<x-page-header title="Activity Logs" icon="bi-clock-history"
               subtitle="Every action by admins, teachers, parents and learners" />

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" action="{{ route('admin.logs') }}" class="row g-3 align-items-end">
            <div class="col-md-8">
                <label class="form-label text-muted small">Filter by Role</label>
                <select name="role" class="form-select">
                    <option value="">All Users & Learners</option>
                    <option value="admin" {{ request('role') == 'admin' ? 'selected' : '' }}>Admins</option>
                    <option value="teacher" {{ request('role') == 'teacher' ? 'selected' : '' }}>Teachers</option>
                    <option value="parent" {{ request('role') == 'parent' ? 'selected' : '' }}>Parents</option>
                    <option value="learner" {{ request('role') == 'learner' ? 'selected' : '' }}>Learners</option>
                </select>
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-primary w-100">Filter Logs</button>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light">
                    <tr>
                        <th class="ps-4">Timestamp</th>
                        <th>User / Entity</th>
                        <th>Role</th>
                        <th>Action</th>
                        <th>Description</th>
                        <th class="pe-4">IP Address</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($logs as $log)
                        <tr>
                            <td class="ps-4 text-muted small whitespace-nowrap">
                                {{ $log->created_at ? $log->created_at->format('M d, Y h:i A') : 'N/A' }}
                                <div class="text-secondary" style="font-size: 0.7rem;">{{ $log->created_at?->diffForHumans() }}</div>
                            </td>
                            <td class="fw-semibold">
                                @if($log->subject_type === 'learner')
                                    <span class="text-primary"><i class="bi bi-person-hearts me-1"></i>Learner Entity</span>
                                @else
                                    {{ $log->user->name ?? 'System' }}
                                @endif
                            </td>
                            <td>
                                @if($log->subject_type === 'learner')
                                    <span class="badge bg-secondary">Learner</span>
                                @elseif($log->user)
                                    @php
                                        $roleColor = [
                                            'admin' => 'danger',
                                            'teacher' => 'success',
                                            'parent' => 'info',
                                            'student' => 'warning'
                                        ][$log->user->role] ?? 'secondary';
                                    @endphp
                                    <span class="badge bg-{{ $roleColor }}">{{ ucfirst($log->user->role) }}</span>
                                @else
                                    <span class="badge bg-dark">System</span>
                                @endif
                            </td>
                            <td><span class="badge bg-light text-dark border">{{ $log->action }}</span></td>
                            <td class="small">{{ $log->description ?? 'No description' }}</td>
                            <td class="pe-4 text-muted small">{{ $log->ip_address ?? 'N/A' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-5">
                                <i class="bi bi-inbox display-4 d-block mb-3"></i>
                                No activity logs found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @if($logs->hasPages())
        <div class="card-footer bg-white pt-3 pb-2">
            {{ $logs->links() }}
        </div>
    @endif
</div>
@endsection
