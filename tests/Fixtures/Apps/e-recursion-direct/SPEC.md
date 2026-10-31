# e-recursion-direct

**Purpose.** A method that calls itself is cut off with a RECURSION gap.

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
        $orders = Order::get();
        $this->walk(3); // PLANT: direct recursion

        return response()->json(count($orders));
    }

    private function walk(int $depth): int
    {
        return $depth <= 0 ? 0 : $this->walk($depth - 1);
    }
}
```

## What the tool must say (`expect.php`)

Re-entering `walk` is a gap: both axes `unknown`. `Order::get()` was found before the gap, so memory has the lower bound `O(N)`.
