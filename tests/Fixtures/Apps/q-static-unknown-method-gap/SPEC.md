# q-static-unknown-method-gap

**Purpose.** A static method that is neither on the model nor a scope is unknown (a macro, maybe).

Read `../_kit/SPEC.md` first (shared layout, standard models, rules). Only the differences are listed here.

**Routes** (as exported in `export.php`):

- `GET /t` → `App\Http\Controllers\TestController@index`

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
        return response()->json(Order::mysteryMethod()->get()); // PLANT: no such method
    }
}
```

## What the tool must say (`expect.php`)

Nothing in the model defines `mysteryMethod`; it may come from a macro. Gap `UNKNOWN_BUILDER_METHOD`, both axes `unknown`.
Because `mysteryMethod()` is unknown, nothing proves that the following `->get()` is a query, so no query event is found (`events_found` is 0). The gap and the `unknown` axes carry the verdict.
