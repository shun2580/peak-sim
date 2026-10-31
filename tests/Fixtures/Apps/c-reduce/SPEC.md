# c-reduce

**Purpose.** reduce() runs its callback once per element.

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
        $total = Order::get()->reduce(fn ($carry, Order $order) => $carry + $order->customer->id, 0); // PLANT: callback runs once per order

        return response()->json(['total' => $total]);
    }
}
```

## What the tool must say (`expect.php`)

Same verdict as `c-map`.
