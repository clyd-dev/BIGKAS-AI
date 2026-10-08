@extends('layouts.app')

@section('title', 'Add Intervention')

@section('content')
    <x-page-header title="Add Intervention" icon="bi-plus-circle"
                   :back="route('interventions.index')" back-label="Interventions" />

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ route('interventions.store') }}">
                @csrf
                @include('interventions._form')
                <div class="mt-4">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i> Save Intervention</button>
                    <a href="{{ route('interventions.index') }}" class="btn btn-outline-secondary ms-2">Cancel</a>
                </div>
            </form>
        </div>
    </div>
@endsection
