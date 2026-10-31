# q-find-plain-assumed

**Purpose.** find() with an argument that may be one id or an array of ids is taken as one row, and says so.

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
use Illuminate\Http\Request;

class TestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $order = Order::find($request->query('id')); // PLANT: one id, or an array of ids?

        return response()->json(['total' => $order->total]);
    }
}
```

## What the tool must say (`expect.php`)

`$request->query('id')` is a plain value the tool cannot classify: an id, or (`?id[]=1&id[]=2`) an array, in which case `find` would return many rows. The tool assumes one row and records `FIND_ARGUMENT_ASSUMED_SCALAR`. The statement count is one either way, so the query axis stays `high`; the number of rows loaded differs, so the memory axis is `medium`, and so is the route. No gap: this is an assumption, not "could not follow".
