@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    <x-page-header title="Dashboard" :subtitle="'Welcome back, ' . Auth::user()->name" />

    @if(Auth::user()->role === 'admin')
        @include('dashboard.admin')
    @elseif(Auth::user()->role === 'student')
        @include('dashboard.student')
    @else
        @include('dashboard.teacher')
    @endif
@endsection
