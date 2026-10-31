@if ($orders->isNotEmpty())
    @foreach ($orders as $order)
        @unless ($order->total > 100)
            <p>{{ $order->customer->name }}</p>{{-- PLANT --}}
        @endunless
    @endforeach
@endif
