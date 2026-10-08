@extends('layouts.app')

@section('title', 'Edit Intervention')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-pencil me-2"></i>Edit Intervention</h4>
        <a href="{{ route('interventions.show', $intervention) }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Back</a>
    </div>

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
