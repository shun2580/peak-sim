# q-with-dotted

**Purpose.** A dotted eager load is one query per level, however many rows there are.

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
        $orders = Order::with('items.product')->get(); // PLANT: two levels

        $names = [];
        foreach ($orders as $order) {
            foreach ($order->items as $item) {
                $names[] = $item->product->name;
            }
        }

        return response()->json($names);
    }
}
```

## What the tool must say (`expect.php`)

Three queries in all (orders, items, products): constant. Memory: every order holds its items: `O(N*M)`.
