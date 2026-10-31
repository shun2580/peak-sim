# e-union-ternary

**Purpose.** A ternary is a branch like if/else: the worse side counts.

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
use Illuminate\Http\Request;

class TestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $orders = $request->query('all') ? Order::get() : Order::limit(10)->get(); // PLANT: either side

        $total = 0;
        foreach ($orders as $order) {
            $total += $order->customer->id;
        }

        return response()->json(['total' => $total]);
    }
}
```

## What the tool must say (`expect.php`)

Same verdict as `e-union-if`.
