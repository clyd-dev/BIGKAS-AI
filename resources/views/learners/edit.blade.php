@extends('layouts.app')

@section('title', 'Edit Learner')

@section('content')
    <x-page-header title="Edit Learner" icon="bi-pencil"
                   :back="route('learners.show', $learner)" back-label="Learner profile" />

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
