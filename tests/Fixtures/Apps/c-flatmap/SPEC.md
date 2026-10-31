# c-flatmap

**Purpose.** flatMap() over a to-many relation: one query per order, and the items multiply memory.

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
        $ids = Order::get()->flatMap(fn (Order $order) => $order->items->pluck('id')); // PLANT: items read once per order

        return response()->json($ids);
    }
}
```

## What the tool must say (`expect.php`)

Queries: `items` is lazy per order, O(N). Memory: every order's items are held: `O(N*M)` (N = orders, M = items per order).
