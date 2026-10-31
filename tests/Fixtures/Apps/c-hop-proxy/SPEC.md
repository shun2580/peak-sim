# c-hop-proxy

**Purpose.** A higher-order proxy ($orders->each->save()) is a loop with a statement per element.

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
        Order::get()->each->save(); // PLANT: higher-order proxy

        return response()->json(['done' => true]);
    }
}
```

## What the tool must say (`expect.php`)

`each->save()` saves every order: queries O(N). Memory O(N).

## Notes for the writer

- The route is `POST /t`: in `routes/web.php` write `Route::post('/t', [TestController::class, 'index']);`.
