# q-create

**Purpose.** create() is one statement.

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
        $order = Order::create(['status' => 'new', 'total' => 0]); // PLANT: one INSERT

        return response()->json(['id' => $order->id]);
    }
}
```

## What the tool must say (`expect.php`)

One INSERT, no loop.

## Notes for the writer

- The route is `POST /t`: in `routes/web.php` write `Route::post('/t', [TestController::class, 'index']);`.
