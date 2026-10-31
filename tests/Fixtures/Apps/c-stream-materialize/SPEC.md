# c-stream-materialize

**Purpose.** all() on a lazy stream loads every row at once.

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
        $orders = Order::lazy(100)->all(); // PLANT: lazy() streams, all() materialises every row

        return response()->json(count($orders));
    }
}
```

## What the tool must say (`expect.php`)

`lazy()` reads in windows (queries O(N)); `all()` turns the stream into an array (memory O(N)).
