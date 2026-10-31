# q-load-after

**Purpose.** load() after the fact is one query, and the later reads are free.

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
        $orders = Order::get();
        $orders->load('customer'); // PLANT: one query for every customer

        $names = [];
        foreach ($orders as $order) {
            $names[] = $order->customer->name;
        }

        return response()->json($names);
    }
}
```

## What the tool must say (`expect.php`)

`load` eager-loads in one extra query; inside the loop `customer` is already there.
