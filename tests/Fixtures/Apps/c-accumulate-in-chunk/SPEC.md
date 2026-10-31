# c-accumulate-in-chunk

**Purpose.** Collecting every row of every chunk into one array defeats chunk(): memory is O(N).

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
        $ids = [];
        Order::chunk(100, function ($orders) use (&$ids): void {
            foreach ($orders as $order) {
                $ids[] = $order->id; // PLANT: keeps every id of every window
            }
        });

        return response()->json($ids);
    }
}
```

## What the tool must say (`expect.php`)

Queries O(N) as in `q-chunk`. Memory would be O(1) for a pure stream, but `$ids` is declared outside the chunk and appended inside it, so it keeps growing: O(N). This is a heuristic (design 1.4, "Accumulate"), hence the assumption and `medium` on the memory axis.
