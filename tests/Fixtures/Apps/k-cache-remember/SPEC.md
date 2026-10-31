# k-cache-remember

**Purpose.** N+1 wrapped in Cache::remember: the miss costs O(N) queries, the hit O(1), and the hit still restores every row.

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
        $names = Cache::remember('orders.names', 300, fn () => Order::get() // PLANT: cached N+1
            ->map(fn (Order $order) => $order->customer->name)
            ->all());

        return response()->json($names);
    }
}
```

## What the tool must say (`expect.php`)

The route verdict is the miss side. The hit side has no query but restores the whole array (memory O(N), assuming a serialising cache driver). That assumption concerns the **hit** view only, so the route confidence stays `high`.
