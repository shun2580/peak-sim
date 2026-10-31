# e-union-if

**Purpose.** After an if/else the variable may hold either branch; the loop is judged on the worse one.

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
use Illuminate\Http\Request;

class TestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if ($request->query('all')) {
            $orders = Order::get();
        } else {
            $orders = Order::limit(10)->get();
        }

        $total = 0;
        foreach ($orders as $order) {
            $total += $order->customer->id; // PLANT: the loop runs over the worse branch
        }

        return response()->json(['total' => $total]);
    }
}
```

## What the tool must say (`expect.php`)

`$orders` is "all orders" or "at most 10". The merge keeps the worse side (unbounded) and records `BRANCH_WORST_CASE`, which lowers confidence to `medium`.
