@extends('layouts.app')

@section('title', 'Add Reading Material')

@section('content')
    <x-page-header title="Add Reading Material" icon="bi-plus-circle"
                   :back="route('materials.index')" back-label="Materials" />

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
