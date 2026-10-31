# k-cache-in-loop

**Purpose.** A cache lookup per iteration: the miss is an N+1, the hit reads no relation, and what is cached is a scalar.

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
use Illuminate\Support\Facades\Cache;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        $names = [];
        foreach (Order::get() as $order) {
            $names[] = Cache::remember('customer.name.'.$order->id, 300, fn () => $order->customer->name); // PLANT: cached per order
        }

        return response()->json($names);
    }
}
```

## What the tool must say (`expect.php`)

On a miss every iteration queries `customer` (O(N)). On a hit the loop reads only the cache: the single `Order::get()` remains (queries O(1), memory O(N)). What is cached per iteration is one string, not a table-sized list, so nothing is "restored in full": `restores_all_on_hit` is false.
