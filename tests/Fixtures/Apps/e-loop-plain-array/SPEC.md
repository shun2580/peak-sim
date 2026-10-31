# e-loop-plain-array

**Purpose.** A foreach over a literal list is bounded: no growth, no gap.

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
        $total = 0;
        foreach ([1, 2, 3] as $id) { // PLANT: a literal list is a bounded loop
            $total += Order::find($id)->total;
        }

        return response()->json(['total' => $total]);
    }
}
```

## What the tool must say (`expect.php`)

Three iterations at most, whatever the data: the loop adds no growth. The queries inside are constant in number.
