@extends('layouts.app')

@section('title', 'Edit Learner')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-pencil me-2"></i>Edit Learner</h4>
        <a href="{{ route('learners.show', $learner) }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i> Back
        </a>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ route('learners.update', $learner) }}">
                @csrf @method('PUT')
                @include('learners._form')

                <div class="mt-4">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle me-1"></i> Update Learner
                    </button>
                    <a href="{{ route('learners.show', $learner) }}" class="btn btn-outline-secondary ms-2">Cancel</a>
                </div>
            </form>
        </div>
    </div>
@endsection
