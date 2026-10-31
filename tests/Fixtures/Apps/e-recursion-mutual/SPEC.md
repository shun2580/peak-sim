# e-recursion-mutual

**Purpose.** Two methods that call each other are cut off with a RECURSION gap.

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
        $this->ping(3); // PLANT: ping -> pong -> ping

        return response()->json(count($orders));
    }

    private function ping(int $depth): int
    {
        return $depth <= 0 ? 0 : $this->pong($depth - 1);
    }

    private function pong(int $depth): int
    {
        return $depth <= 0 ? 0 : $this->ping($depth - 1);
    }
}
```

## What the tool must say (`expect.php`)

Same verdict as `e-recursion-direct`; the cycle goes through two methods.
