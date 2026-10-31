@foreach ($orders as $order)
    @can('view', $order)
        <p>{{ $order->id }}</p>
    @endcan
@endforeach
