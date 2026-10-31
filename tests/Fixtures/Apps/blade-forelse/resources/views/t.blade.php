@forelse ($orders as $order)
    <p>{{ $order->customer->name }}</p>{{-- PLANT --}}
@empty
    <p>No orders.</p>
@endforelse
