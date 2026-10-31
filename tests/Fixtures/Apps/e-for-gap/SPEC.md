# e-for-gap

**Purpose.** A for loop is not followed: a query inside it makes both axes unknown.

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
        $sum = 0;
        for ($i = 1; $i <= 10; $i++) { // PLANT: for loops are not followed
            $sum += Order::find($i)->total;
        }

        return response()->json(['sum' => $sum]);
    }
}
```

## What the tool must say (`expect.php`)

`for` has no known source of N, and its body contains a query, so the loop is a gap (`LOOP_BOUND_UNKNOWN`) and both axes are `unknown`.
