# q-pluck-insert

**Purpose.** The N of an insert comes from the table that was plucked (customers), not from the table written (orders).

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
        $now = now();
        $rows = Customer::pluck('id')
            ->map(fn (int $id) => ['customer_id' => $id, 'status' => 'new', 'created_at' => $now]) // PLANT: N comes from customers
            ->all();
        Order::insert($rows);

        return response()->json(['created' => count($rows)]);
    }
}
```

## What the tool must say (`expect.php`)

One INSERT statement (queries O(1)) carrying N rows (memory O(N)). `n_table` is `customers`: the origin of N travels through `pluck` → `map` → `all` → `insert`.

## Notes for the writer

- The route is `POST /t`: in `routes/web.php` write `Route::post('/t', [TestController::class, 'index']);`.
