# q-model-with-1

**Purpose.** A model with $with = [one relation] adds one extra query per request, not per row.

Read `../_kit/SPEC.md` first (shared layout, standard models, rules). Only the differences are listed here.

**Routes** (as exported in `export.php`):

- `GET /t` → `App\Http\Controllers\TestController@index`

## Files to write

### `app/Models/WithOrder.php`

```php
<?php

namespace App\Models;

class WithOrder extends Order
{
    protected $table = 'orders';

    protected $with = ['customer']; // PLANT: always eager-loads customer
}
```

### `app/Http/Controllers/TestController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Models\WithOrder;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(WithOrder::get());
    }
}
```

## What the tool must say (`expect.php`)

`WithOrder::get()` = 1 query + 1 eager query for `customer` (constant). Memory O(N). Nothing is read per row.
