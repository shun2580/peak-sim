# q-paginate

**Purpose.** paginate() is bounded: O(1) queries and O(1) memory.

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
        return response()->json(Order::paginate(15)); // PLANT: bounded read
    }
}
```

## What the tool must say (`expect.php`)

No loop, no unbounded read. The growing table is still `orders` because the event reads it.
