# q-pluck-chunked-insert

**Purpose.** Splitting the rows by hand into batches does not make an insert constant: one INSERT per batch.

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
        $ids = Customer::pluck('id')->all();
        foreach (array_chunk($ids, 1000) as $batch) { // PLANT: N/1000 inserts
            Order::insert(array_map(fn (int $id) => ['customer_id' => $id, 'status' => 'new'], $batch));
        }

        return response()->json(['created' => count($ids)]);
    }
}
```

## What the tool must say (`expect.php`)

The tool does not use the 1/1000 as a limit (S-32): the batches are N/1000, so the INSERTs grow with N. `array_chunk` keeps the origin of N (customers) on the outer list. Memory: `pluck` loads every id.

## Notes for the writer

- The route is `POST /t`: in `routes/web.php` write `Route::post('/t', [TestController::class, 'index']);`.
