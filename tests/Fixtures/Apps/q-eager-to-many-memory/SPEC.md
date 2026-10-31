# q-eager-to-many-memory

**Purpose.** Eager-loading a to-many relation keeps queries flat but multiplies memory: every order carries its items.

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
        $orders = Order::with('items')->get(); // PLANT: to-many eager load

        return response()->json($orders);
    }
}
```

## What the tool must say (`expect.php`)

Two queries however many orders there are. Memory: N orders, each holding M items: `O(N*M)` with N = orders and M = items per order.
