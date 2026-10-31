# q-upsert

**Purpose.** upsert() with one row per customer is one statement carrying N rows.

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
        $rows = Customer::pluck('id')
            ->map(fn (int $id) => ['id' => $id, 'status' => 'new'])
            ->all();
        Order::upsert($rows, ['id'], ['status']); // PLANT: one statement, N rows

        return response()->json(['upserted' => count($rows)]);
    }
}
```

## What the tool must say (`expect.php`)

Same verdict as `q-pluck-insert`.

## Notes for the writer

- The route is `POST /t`: in `routes/web.php` write `Route::post('/t', [TestController::class, 'index']);`.
