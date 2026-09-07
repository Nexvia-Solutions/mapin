@extends('layouts.app')

@section('content')
    @include('dashboard.partials.stats')

    <x-alert type="success" />

    @component('components.card')
        Welcome back.
    @endcomponent
@endsection
