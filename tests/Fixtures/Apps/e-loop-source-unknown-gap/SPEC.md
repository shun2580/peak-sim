# e-loop-source-unknown-gap

**Purpose.** A foreach over an array that comes from the request has no known size: gap.

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
        $total = 0;
        foreach ($request->query('ids', []) as $id) { // PLANT: the size comes from the caller
            $total += Order::find($id)->total;
        }

        return response()->json(['total' => $total]);
    }
}
```

## What the tool must say (`expect.php`)

The array is neither a literal nor rows of a table, so the loop's size is unknown while its body queries: gap `LOOP_SOURCE_UNKNOWN`, both axes `unknown`.
