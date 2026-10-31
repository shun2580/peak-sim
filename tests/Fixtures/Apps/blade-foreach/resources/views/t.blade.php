@foreach ($orders as $order)
    <p>{{ $order->customer->name }}</p>{{-- PLANT: relation read in the loop --}}
@endforeach
