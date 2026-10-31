# q-simple-paginate

**Purpose.** simplePaginate() is bounded like paginate().

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
        return response()->json(Order::simplePaginate(15)); // PLANT: bounded read
    }
}
```

## What the tool must say (`expect.php`)

Same verdict as `q-paginate`.
