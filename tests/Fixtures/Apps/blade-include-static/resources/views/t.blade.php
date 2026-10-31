@foreach ($orders as $order)
    @include('partials.row', ['order' => $order])
@endforeach
