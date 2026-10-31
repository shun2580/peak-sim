# k-cache-forever

**Purpose.** rememberForever() is Cache::remember() without an expiry.

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
        $names = Cache::rememberForever('orders.names', fn () => Order::get() // PLANT: cached N+1
            ->map(fn (Order $order) => $order->customer->name)
            ->all());

        return response()->json($names);
    }
}
```

## What the tool must say (`expect.php`)

Same verdict as `k-cache-remember`.
