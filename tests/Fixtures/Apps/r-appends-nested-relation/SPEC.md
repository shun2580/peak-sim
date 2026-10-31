# r-appends-nested-relation

**Purpose.** An accessor on a model reached through an eager-loaded relation still runs for every row.

Read `../_kit/SPEC.md` first (shared layout, standard models, rules). Only the differences are listed here.

**Routes** (as exported in `export.php`):

- `GET /t` → `App\Http\Controllers\TestController@index`

## Files to write

### `app/Models/AppendedCustomer.php`

```php
<?php

namespace App\Models;

class AppendedCustomer extends Customer
{
    protected $table = 'customers';

    protected $appends = ['orders_total'];

    public function getOrdersTotalAttribute(): int
    {
        return $this->orders->count(); // PLANT: lazy load per customer
    }
}
```

### `app/Models/NestedOrder.php`

```php
<?php

namespace App\Models;

class NestedOrder extends Order
{
    protected $table = 'orders';

    public function customer()
    {
        return $this->belongsTo(AppendedCustomer::class, 'customer_id');
    }
}
```

### `app/Http/Controllers/TestController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Models\NestedOrder;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(NestedOrder::with('customer')->get());
    }
}
```

## What the tool must say (`expect.php`)

Serialising a `NestedOrder` walks into its eager-loaded `customer` (an `AppendedCustomer`), whose appended accessor reads `orders` lazily: one query per row, so queries O(N). (Memory is not asserted here.)
