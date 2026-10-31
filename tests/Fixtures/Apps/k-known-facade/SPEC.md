# k-known-facade

**Purpose.** Cache::remember() around a scalar: both sides are constant.

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
        $total = Cache::remember('orders.count', 60, fn () => Order::count()); // PLANT: cached scalar

        return response()->json(['total' => $total]);
    }
}
```

## What the tool must say (`expect.php`)

A COUNT on a miss, a scalar on a hit. The cache view is still reported (the route is cached), with nothing growing on either side.
