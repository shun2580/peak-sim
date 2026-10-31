# q-relation-builder-create

**Purpose.** Creating through a relation builder inside a loop is a statement per iteration.

Read `../_kit/SPEC.md` first (shared layout, standard models, rules). Only the differences are listed here.

**Routes** (as exported in `export.php`):

- `POST /t` → `App\Http\Controllers\TestController@index`

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
        foreach (Order::get() as $order) {
            $order->items()->create(['quantity' => 1]); // PLANT: one INSERT per order
        }

        return response()->json(['done' => true]);
    }
}
```

## What the tool must say (`expect.php`)

Queries O(N); memory O(N) from `Order::get()`.

## Notes for the writer

- The route is `POST /t`: in `routes/web.php` write `Route::post('/t', [TestController::class, 'index']);`.
