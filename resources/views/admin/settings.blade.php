@extends('layouts.app')

@section('title', 'System Settings')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-sliders me-2"></i>System Settings</h4>
        <a href="{{ route('dashboard') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Dashboard</a>
    </div>

    <form method="POST" action="{{ route('admin.settings.save') }}">
        @csrf

        <div class="row g-3">
            <div class="col-md-6">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white"><h6 class="mb-0">Application Settings</h6></div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label">App Name</label>
                            <input type="text" class="form-control" name="settings[app_name]" value="{{ $settings['app_name'] ?? 'BIGKAS-AI' }}">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Max Audio File Size (MB)</label>
                            <input type="number" class="form-control" name="settings[max_audio_mb]" value="{{ $settings['max_audio_mb'] ?? 20 }}" min="1" max="100">
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white"><h6 class="mb-0">Reading Assessment Settings</h6></div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label">Independent Level Threshold (%)</label>
                            <input type="number" class="form-control" name="settings[independent_threshold]" value="{{ $settings['independent_threshold'] ?? 97 }}" min="90" max="100">
                            @error('settings.independent_threshold')
                                <div class="text-danger small">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Instructional Level Threshold (%)</label>
                            <input type="number" class="form-control" name="settings[instructional_threshold]" value="{{ $settings['instructional_threshold'] ?? 90 }}" min="80" max="100">
                            @error('settings.instructional_threshold')
                                <div class="text-danger small">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="settings[ml_enabled]" value="1" id="mlEnabled"
                                {{ ($settings['ml_enabled'] ?? true) ? 'checked' : '' }}>
                            <label class="form-check-label" for="mlEnabled">Enable ML Classification</label>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="settings[auto_recommend]" value="1" id="autoRecommend"
                                {{ ($settings['auto_recommend'] ?? true) ? 'checked' : '' }}>
                            <label class="form-check-label" for="autoRecommend">Auto-recommend Interventions</label>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-4">
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i> Save Settings</button>
        </div>
    </form>
@endsection
