# k-known-function

**Purpose.** Plain helpers (now, round, count, in_array) are known and create no gap.

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
        $orders = Order::limit(10)->get();

        return response()->json([
            'at' => now(),
            'rounded' => round(count($orders) / 2),
            'listed' => in_array('new', ['new', 'paid'], true),
        ]);
    }
}
```

## What the tool must say (`expect.php`)

Every call here is on the allow-list (`resources/known-calls.php`) and none touches the database. `limit(10)` bounds the read. No gap, no loop.
