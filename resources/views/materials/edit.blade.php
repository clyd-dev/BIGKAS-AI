@extends('layouts.app')

@section('title', 'Edit Material')

@section('content')
    <x-page-header title="Edit Material" icon="bi-pencil"
                   :back="route('materials.show', $material)" back-label="Material" />

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ route('materials.update', $material) }}">
                @csrf @method('PUT')
                @include('materials._form')
                @include('materials._questions')

                <div class="mt-4">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i> Update Material</button>
                    <a href="{{ route('materials.show', $material) }}" class="btn btn-outline-secondary ms-2">Cancel</a>
                </div>
            </form>
        </div>
    </div>
@endsection
