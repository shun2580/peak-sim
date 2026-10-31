# q-save

**Purpose.** save() on a single model costs nothing that grows.

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
        $order = Order::first();
        $order->status = 'paid';
        $order->save(); // PLANT: one row

        return response()->json(['id' => $order->id]);
    }
}
```

## What the tool must say (`expect.php`)

`first()` reads one row; `save()` writes one row. Nothing loops.
