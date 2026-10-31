# c-filter

**Purpose.** A relation read inside a Collection filter() callback is an N+1.

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
        $kept = Order::get()->filter(fn (Order $order) => $order->customer->id > 1); // PLANT: callback runs once per order

        return response()->json($kept->values());
    }
}
```

## What the tool must say (`expect.php`)

The callback of `filter` runs for every order. Contrast with `/ng/collection-filter`, whose `where()` compares values and reads no relation.
