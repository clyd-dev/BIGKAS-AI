@extends('layouts.app')

@section('title', 'Admin Panel')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-gear me-2"></i>Admin Panel</h4>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm bg-primary text-white">
                <div class="card-body text-center">
                    <i class="bi bi-people display-6"></i>
                    <h3 class="mt-2 mb-0">{{ $stats['total_users'] ?? 0 }}</h3>
                    <small>Total Users</small>
                </div>
                <a href="{{ route('admin.users') }}" class="card-footer text-white text-center text-decoration-none bg-transparent border-top border-white border-opacity-25">
                    Manage Users <i class="bi bi-arrow-right ms-1"></i>
                </a>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm bg-success text-white">
                <div class="card-body text-center">
                    <i class="bi bi-building display-6"></i>
                    <h3 class="mt-2 mb-0">{{ $stats['total_schools'] ?? 0 }}</h3>
                    <small>Schools</small>
                </div>
                <a href="{{ route('admin.schools') }}" class="card-footer text-white text-center text-decoration-none bg-transparent border-top border-white border-opacity-25">
                    Manage Schools <i class="bi bi-arrow-right ms-1"></i>
                </a>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm bg-info text-white">
                <div class="card-body text-center">
                    <i class="bi bi-lightbulb display-6"></i>
                    <h3 class="mt-2 mb-0">{{ $stats['total_interventions'] ?? 0 }}</h3>
                    <small>Interventions</small>
                </div>
                <a href="{{ route('admin.interventions') }}" class="card-footer text-white text-center text-decoration-none bg-transparent border-top border-white border-opacity-25">
                    Manage Interventions <i class="bi bi-arrow-right ms-1"></i>
                </a>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm bg-warning text-dark">
                <div class="card-body text-center">
                    <i class="bi bi-journal-text display-6"></i>
                    <h3 class="mt-2 mb-0">{{ $stats['total_materials'] ?? 0 }}</h3>
                    <small>Materials</small>
                </div>
                <a href="{{ route('admin.materials') }}" class="card-footer text-dark text-center text-decoration-none bg-transparent border-top border-dark border-opacity-25">
                    View Materials <i class="bi bi-arrow-right ms-1"></i>
                </a>
            </div>
        </div>
    </div>

    {{-- System Info --}}
    <div class="row g-3">
        <div class="col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white"><h6 class="mb-0">System Information</h6></div>
                <div class="card-body">
                    <table class="table table-sm table-borderless mb-0">
                        <tr><th class="text-muted" width="40%">App Version</th><td>BIGKAS-AI v1.0</td></tr>
                        <tr><th class="text-muted">Laravel</th><td>{{ app()->version() }}</td></tr>
                        <tr><th class="text-muted">PHP</th><td>{{ phpversion() }}</td></tr>
                        <tr><th class="text-muted">Database</th><td>{{ config('database.default') }}</td></tr>
                        <tr><th class="text-muted">Timezone</th><td>{{ config('app.timezone') }}</td></tr>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white"><h6 class="mb-0">Quick Actions</h6></div>
                <div class="card-body">
                    <div class="d-grid gap-2">
                        <a href="{{ route('admin.users') }}" class="btn btn-outline-primary"><i class="bi bi-person-badge me-1"></i> Manage Users</a>
                        <a href="{{ route('admin.schools') }}" class="btn btn-outline-success"><i class="bi bi-building me-1"></i> Manage Schools</a>
                        <a href="{{ route('admin.settings') }}" class="btn btn-outline-secondary"><i class="bi bi-sliders me-1"></i> System Settings</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
