<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Orders</title>
</head>
<body>
    <table>
        @foreach ($orders as $order)
            <tr>
                <td>{{ $order->id }}</td>
                <td>{{ $order->customer->name }}</td>
                <td>{{ $order->total }}</td>
            </tr>
        @endforeach
    </table>
</body>
</html>
