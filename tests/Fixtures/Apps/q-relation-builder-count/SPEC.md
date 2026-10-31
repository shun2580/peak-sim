# q-relation-builder-count

**Purpose.** items()->count() (with parentheses) is a query per order, unlike the items attribute.

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
        $total = 0;
        foreach (Order::get() as $order) {
            $total += $order->items()->count(); // PLANT: a COUNT query per order
        }

        return response()->json(['total' => $total]);
    }
}
```

## What the tool must say (`expect.php`)

The relation *builder* `items()` runs a COUNT per iteration: queries O(N). Memory is O(N) because `Order::get()` loads every order.
