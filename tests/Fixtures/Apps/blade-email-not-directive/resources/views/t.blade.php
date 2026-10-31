@foreach ($orders as $order)
    <a href="mailto:support@example.com">{{ $order->customer->name }}</a>{{-- PLANT --}}
@endforeach
