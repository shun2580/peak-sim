# q-cursor-mysql

**Purpose.** cursor() on MySQL: one query, but the driver buffers every row, so memory grows with N.

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
        foreach (Order::cursor() as $order) { // PLANT: cursor()
            $count++;
        }

        return response()->json(['count' => $count]);
    }
}
```

## What the tool must say (`expect.php`)

The exported models say `database: mysql` (the standard export). The PDO driver buffers the whole result: memory O(N), queries O(1).
