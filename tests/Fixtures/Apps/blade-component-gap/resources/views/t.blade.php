@foreach ($orders as $order)
    <x-order-row :order="$order" />{{-- PLANT: a component --}}
@endforeach
