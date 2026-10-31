# q-with-columns

**Purpose.** with() with a column list still eager-loads.

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
        $orders = Order::with('customer:id,name')->get(); // PLANT: eager load with columns

        $names = [];
        foreach ($orders as $order) {
            $names[] = $order->customer->name;
        }

        return response()->json($names);
    }
}
```

## What the tool must say (`expect.php`)

Same verdict as `q-with-closure`.
