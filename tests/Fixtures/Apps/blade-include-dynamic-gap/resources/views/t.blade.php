@foreach ($orders as $order)
    @include($partial, ['order' => $order]){{-- PLANT: the name is a variable --}}
@endforeach
