@foreach ($orders as $order)
    @foreach ($order->items as $item)
        <p>{{ $item->product->name }}</p>{{-- PLANT --}}
    @endforeach
@endforeach
