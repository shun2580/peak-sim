# q-cursor-sqlite

**Purpose.** cursor() on SQLite: nothing is buffered, so memory is flat.

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

Here `export.php` says `database: sqlite` for every model (`E::standardModels('sqlite')`). The driver does not buffer, so memory is O(1) (the design takes this from D20; it is **not** measured).
