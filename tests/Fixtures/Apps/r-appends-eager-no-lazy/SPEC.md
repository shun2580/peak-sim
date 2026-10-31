# r-appends-eager-no-lazy

**Purpose.** The same accessor over models that were loaded with their customer reads nothing more.

Read `../_kit/SPEC.md` first (shared layout, standard models, rules). Only the differences are listed here.

**Routes** (as exported in `export.php`):

- `GET /t` → `App\Http\Controllers\TestController@index`

## Files to write

### `app/Models/AppendedOrder.php`

```php
<?php

namespace App\Models;

class AppendedOrder extends Order
{
    protected $table = 'orders';

    protected $appends = ['customer_name'];

    public function getCustomerNameAttribute(): string
    {
        return $this->customer->name; // PLANT: lazy load when serialised
    }
}
```

### `app/Http/Controllers/TestController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Models\AppendedOrder;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(AppendedOrder::with('customer')->get());
    }
}
```

## What the tool must say (`expect.php`)

`with('customer')` puts the customer in memory before serialisation, so the accessor's `$this->customer` is not a query: queries O(1).
