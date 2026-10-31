@php
    $total = 0;
@endphp
@foreach ($orders as $order)
    @php
        $total += $order->customer->id; // PLANT
    @endphp
@endforeach
<p>{{ $total }}</p>
