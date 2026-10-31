# blade-compact

**Purpose.** view() with compact() passes variables by name.

Read `../_kit/SPEC.md` first (shared layout, standard models, rules). Only the differences are listed here.

**Routes** (as exported in `export.php`):

- `GET /t` → `App\Http\Controllers\TestController@index`

## Files to write

### `app/Http/Controllers/TestController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Contracts\View\View;

class TestController extends Controller
{
    public function index(): View
    {
        $orders = Order::get();

        return view('t', compact('orders')); // PLANT: compact()
    }
}
```

### `resources/views/t.blade.php`

```blade
@foreach ($orders as $order)
    <p>{{ $order->customer->name }}</p>{{-- PLANT --}}
@endforeach
```

## What the tool must say (`expect.php`)

Same verdict as `blade-foreach`: `compact('orders')` is `['orders' => $orders]`.
