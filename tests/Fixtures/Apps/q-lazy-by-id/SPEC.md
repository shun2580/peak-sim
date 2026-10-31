# q-lazy-by-id

**Purpose.** lazyById() streams like lazy().

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
        $count = 0;
        foreach (Order::lazyById(100) as $order) { // PLANT: streamed read
            $count++;
        }

        return response()->json(['count' => $count]);
    }
}
```

## What the tool must say (`expect.php`)

Same verdict as `q-lazy`.
