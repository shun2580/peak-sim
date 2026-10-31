# q-chunk

**Purpose.** chunk() reads in windows: queries grow with N, memory does not.

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
        Order::chunk(100, function ($orders) use (&$count): void { // PLANT: windowed read
            foreach ($orders as $order) {
                $count++;
            }
        });

        return response()->json(['count' => $count]);
    }
}
```

## What the tool must say (`expect.php`)

One query per window means queries O(N) (coefficient 1/100, shown only). The `foreach` over the window adds no extra loop level.
