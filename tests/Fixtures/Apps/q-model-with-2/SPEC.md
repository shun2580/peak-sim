# q-model-with-2

**Purpose.** A model with $with = [two relations] adds two extra queries; the to-many one multiplies memory.

Read `../_kit/SPEC.md` first (shared layout, standard models, rules). Only the differences are listed here.

**Routes** (as exported in `export.php`):

- `GET /t` → `App\Http\Controllers\TestController@index`

## Files to write

### `app/Http/Controllers/TestController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Models\WithMoreOrder;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(WithMoreOrder::get()); // PLANT: $with has two entries
    }
}
```

### `app/Models/WithMoreOrder.php`

```php
<?php

namespace App\Models;

class WithMoreOrder extends Order
{
    protected $table = 'orders';

    protected $with = ['customer', 'items']; // PLANT
}
```

## What the tool must say (`expect.php`)

One query for the orders plus one per `$with` entry (constant). `items` is a to-many relation, so every order carries its items in memory: `O(N*M)`.
