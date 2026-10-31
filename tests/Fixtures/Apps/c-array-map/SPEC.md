# c-array-map

**Purpose.** array_map() with a callback is a loop over the array.

Read `../_kit/SPEC.md` first (shared layout, standard models, rules). Only the differences are listed here.

**Routes** (as exported in `export.php`):

- `GET /t` → `App\Http\Controllers\TestController@index`

## Files to write

### `app/Http/Controllers/TestController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        $orders = Order::get()->all();
        $names = array_map(fn (Order $order) => $order->customer->name, $orders); // PLANT: callback runs once per order

        return response()->json($names);
    }
}
```

## What the tool must say (`expect.php`)

`all()` keeps the origin of N (orders) on the array; `array_map` runs the callback for each element.
