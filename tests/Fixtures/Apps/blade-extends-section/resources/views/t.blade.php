@extends('layouts.app')

@section('content')
    @foreach ($orders as $order)
        <p>{{ $order->customer->name }}</p>{{-- PLANT --}}
    @endforeach
@endsection
