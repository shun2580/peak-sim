# c-array-filter

**Purpose.** array_filter() with a callback is a loop over the array.

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
        $kept = array_filter($orders, fn (Order $order) => $order->customer->id > 1); // PLANT: callback runs once per order

        return response()->json(array_values($kept));
    }
}
```

## What the tool must say (`expect.php`)

Same verdict as `c-array-map`.
