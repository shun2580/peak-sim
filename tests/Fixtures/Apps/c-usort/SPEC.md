# c-usort

**Purpose.** usort() calls its comparator per element, and the comparator may query.

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
        $orders = Order::get()->all();
        usort($orders, fn (Order $a, Order $b) => $a->customer->id <=> $b->customer->id); // PLANT: comparator reads a relation

        return response()->json($orders);
    }
}
```

## What the tool must say (`expect.php`)

The comparator is treated as running once per element (the tool does not model the n log n comparisons): queries O(N).
