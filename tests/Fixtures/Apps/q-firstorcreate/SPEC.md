# q-firstorcreate

**Purpose.** firstOrCreate() inside a loop is one or two statements per iteration.

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
            Order::firstOrCreate(['customer_id' => $customer->id], ['status' => 'new']); // PLANT: SELECT, then INSERT if missing
        }

        return response()->json(['done' => true]);
    }
}
```

## What the tool must say (`expect.php`)

Queries O(N) (2 per row when the row is missing: a coefficient, shown only). Memory O(N) because `Customer::all()` loads every customer.

## Notes for the writer

- The route is `POST /t`: in `routes/web.php` write `Route::post('/t', [TestController::class, 'index']);`.
