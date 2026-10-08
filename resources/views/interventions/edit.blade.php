@extends('layouts.app')

@section('title', 'Edit Intervention')

@section('content')
    <x-page-header title="Edit Intervention" icon="bi-pencil"
                   :back="route('interventions.show', $intervention)" back-label="Intervention" />

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ route('interventions.update', $intervention) }}">
                @csrf @method('PUT')
                @include('interventions._form')
                <div class="mt-4">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i> Update Intervention</button>
                    <a href="{{ route('interventions.show', $intervention) }}" class="btn btn-outline-secondary ms-2">Cancel</a>
                </div>
            </form>
        </div>
    </div>
@endsection
