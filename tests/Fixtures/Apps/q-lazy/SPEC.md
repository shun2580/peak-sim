# q-lazy

**Purpose.** lazy() streams: queries grow with N, memory does not.

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
        foreach (Order::lazy(100) as $order) { // PLANT: streamed read
            $count++;
        }

        return response()->json(['count' => $count]);
    }
}
```

## What the tool must say (`expect.php`)

One query per window (coefficient 1/100, shown only); the stream keeps one window in memory.
