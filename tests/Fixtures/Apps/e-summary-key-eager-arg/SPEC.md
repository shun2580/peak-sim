# e-summary-key-eager-arg

**Purpose.** The same method called with an eager-loaded argument and with a lazy one must give two different answers.

Read `../_kit/SPEC.md` first (shared layout, standard models, rules). Only the differences are listed here.

**Routes** (as exported in `export.php`):

- `GET /a-lazy` → `App\Http\Controllers\TestController@lazy`
- `GET /b-eager` → `App\Http\Controllers\TestController@eager`
- `GET /c-both` → `App\Http\Controllers\TestController@both`

## Files to write

### `app/Services/OrderNamer.php`

```php
<?php

namespace App\Services;

class OrderNamer
{
    public function names($orders): array
    {
        $names = [];
        foreach ($orders as $order) {
            $names[] = $order->customer->name; // PLANT: lazy unless $orders was loaded with customer
        }

        return $names;
    }
}
```

### `app/Http/Controllers/TestController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\OrderNamer;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function __construct(private readonly OrderNamer $namer)
    {
    }

    public function lazy(): JsonResponse
    {
        return response()->json($this->namer->names(Order::get()));
    }

    public function eager(): JsonResponse
    {
        return response()->json($this->namer->names(Order::with('customer')->get()));
    }

    public function both(): JsonResponse
    {
        $lazy = $this->namer->names(Order::get());
        $eager = $this->namer->names(Order::with('customer')->get());

        return response()->json([$lazy, $eager]);
    }
}
```

## What the tool must say (`expect.php`)

The result of `OrderNamer::names()` depends on whether the orders it receives already carry `customer`. If the tool remembers one answer per method (and forgets the argument), the route analysed second inherits the first one's answer: one of the first two routes would be wrong. `/c-both` calls both ways: the worse one wins.

## Notes for the writer

- Three routes: write `Route::get('/a-lazy', [TestController::class, 'lazy']);` and the same for `/b-eager` → `eager`, `/c-both` → `both`.
