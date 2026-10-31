# q-updateorcreate

**Purpose.** updateOrCreate() inside a loop is one or two statements per iteration.

Read `../_kit/SPEC.md` first (shared layout, standard models, rules). Only the differences are listed here.

**Routes** (as exported in `export.php`):

- `POST /t` → `App\Http\Controllers\TestController@index`

## Files to write

### `app/Http/Controllers/TestController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Order;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        foreach (Customer::all() as $customer) {
            Order::updateOrCreate(['customer_id' => $customer->id], ['status' => 'paid']); // PLANT: SELECT, then UPDATE or INSERT
        }

        return response()->json(['done' => true]);
    }
}
```

## What the tool must say (`expect.php`)

Same verdict as `q-firstorcreate`.

## Notes for the writer

- The route is `POST /t`: in `routes/web.php` write `Route::post('/t', [TestController::class, 'index']);`.
