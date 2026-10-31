# q-without

**Purpose.** without() removes an eager load that $with would have added, bringing the N+1 back.

Read `../_kit/SPEC.md` first (shared layout, standard models, rules). Only the differences are listed here.

**Routes** (as exported in `export.php`):

- `GET /t` → `App\Http\Controllers\TestController@index`

## Files to write

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
        $names = [];
        foreach (WithOrder::without('customer')->get() as $order) { // PLANT: undoes $with
            $names[] = $order->customer->name;
        }

        return response()->json($names);
    }
}
```

### `app/Models/WithOrder.php`

```php
<?php

namespace App\Models;

class WithOrder extends Order
{
    protected $table = 'orders';

    protected $with = ['customer'];
}
```

## What the tool must say (`expect.php`)

`WithOrder` would eager-load `customer`, but `without('customer')` cancels it for this query, so the read in the loop is lazy.
