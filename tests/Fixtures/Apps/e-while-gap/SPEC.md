# e-while-gap

**Purpose.** A while loop is not followed: a query inside it makes both axes unknown.

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
        $i = 1;
        $sum = 0;
        while ($i <= 10) { // PLANT: while loops are not followed
            $sum += Order::find($i)->total;
            ++$i;
        }

        return response()->json(['sum' => $sum]);
    }
}
```

## What the tool must say (`expect.php`)

Same verdict as `e-for-gap`.
