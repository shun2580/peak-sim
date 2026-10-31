# q-withsum

**Purpose.** withSum() adds a column, not a query.

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
        $orders = Order::withSum('items', 'quantity')->get(); // PLANT: summed in the same query

        return response()->json($orders->map(fn (Order $order) => ['id' => $order->id, 'quantity' => $order->items_sum_quantity]));
    }
}
```

## What the tool must say (`expect.php`)

Same verdict as `q-withcount`.
