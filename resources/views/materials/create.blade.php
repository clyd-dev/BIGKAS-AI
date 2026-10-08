@extends('layouts.app')

@section('title', 'Add Reading Material')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-plus-circle me-2"></i>Add Reading Material</h4>
        <a href="{{ route('materials.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Back</a>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ route('materials.store') }}">
                @csrf
                @include('materials._form')
                @include('materials._questions')

                <div class="mt-4">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i> Save Material</button>
                    <a href="{{ route('materials.index') }}" class="btn btn-outline-secondary ms-2">Cancel</a>
                </div>
            </form>
        </div>
    </div>
@endsection
