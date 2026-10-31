# r-accessor-on-plain-read

**Purpose.** An accessor also runs when its attribute is simply read, with no serialisation and no $appends.

Read `../_kit/SPEC.md` first (shared layout, standard models, rules). Only the differences are listed here.

**Routes** (as exported in `export.php`):

- `GET /t` → `App\Http\Controllers\TestController@index`

## Files to write

### `app/Models/AccessorOrder.php`

```php
<?php

namespace App\Models;

class AccessorOrder extends Order
{
    protected $table = 'orders';

    public function getCustomerNameAttribute(): string
    {
        return $this->customer->name; // PLANT: runs on every read of ->customer_name
    }
}
```

### `app/Http/Controllers/TestController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Models\AccessorOrder;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        $names = [];
        foreach (AccessorOrder::get() as $order) {
            $names[] = $order->customer_name; // PLANT: attribute read
        }

        return response()->json($names);
    }
}
```

## What the tool must say (`expect.php`)

`$order->customer_name` calls `getCustomerNameAttribute`, which lazy-loads the customer: one query per order.
