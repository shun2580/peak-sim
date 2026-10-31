# c-map

**Purpose.** A relation read inside a Collection map() callback is an N+1.

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
        $names = Order::get()->map(fn (Order $order) => $order->customer->name); // PLANT: callback runs once per order

        return response()->json($names);
    }
}
```

## What the tool must say (`expect.php`)

The callback of `map` runs N times: it is a loop level. `customer` is not eager-loaded.
