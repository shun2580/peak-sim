# q-create-many

**Purpose.** createMany() on a relation runs one INSERT per model it is given.

Read `../_kit/SPEC.md` first (shared layout, standard models, rules). Only the differences are listed here.

**Routes** (as exported in `export.php`):

- `POST /t` → `App\Http\Controllers\TestController@index`

## Files to write

### `app/Http/Controllers/TestController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = Customer::pluck('id')
            ->map(fn (int $id) => ['customer_id' => $id, 'status' => 'new'])
            ->all();
        Customer::first()->orders()->createMany($rows); // PLANT: one INSERT per row

        return response()->json(['created' => count($rows)]);
    }
}
```

## What the tool must say (`expect.php`)

The number of statements equals the size of the argument, and that size comes from `customers` (the plucked ids).

## Notes for the writer

- The route is `POST /t`: in `routes/web.php` write `Route::post('/t', [TestController::class, 'index']);`.
