# c-each

**Purpose.** Collection each() is a loop like map().

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
        Order::get()->each(function (Order $order) use (&$total): void { // PLANT: callback runs once per order
            $total += $order->customer->id;
        });

        return response()->json(['total' => $total]);
    }
}
```

## What the tool must say (`expect.php`)

Same verdict as `c-map`.
