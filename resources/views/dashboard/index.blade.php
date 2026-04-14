@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0">Dashboard</h4>
        <span class="text-muted">Welcome back, {{ Auth::user()->name }}</span>
    </div>

    @if(Auth::user()->role === 'admin')
        @include('dashboard.admin')
    @elseif(Auth::user()->role === 'student')
        @include('dashboard.student')
    @else
        @include('dashboard.teacher')
    @endif
@endsection
