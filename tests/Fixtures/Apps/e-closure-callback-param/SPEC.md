# e-closure-callback-param

**Purpose.** A closure kept in a variable and handed to map() runs once per element.

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
        $name = fn (Order $order) => $order->customer->name; // PLANT: the callback lives in a variable
        $names = Order::get()->map($name);

        return response()->json($names);
    }
}
```

## What the tool must say (`expect.php`)

The closure is evaluated for each element when `map` applies it: queries O(N).
