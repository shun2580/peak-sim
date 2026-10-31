# c-accumulate-nested

**Purpose.** Collecting the items of every order into one array.

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
        $all = [];
        foreach (Order::get() as $order) {
            foreach ($order->items as $item) {
                $all[] = $item->id; // PLANT: keeps every item of every order
            }
        }

        return response()->json($all);
    }
}
```

## What the tool must say (`expect.php`)

Queries: `$order->items` is lazy per order, O(N). Memory: all items of all orders end up in `$all`: `O(N*M)`.
