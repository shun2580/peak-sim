# r-appends-old-style

**Purpose.** An old-style accessor (getXxxAttribute) listed in $appends costs a query per serialised model.

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
        return response()->json(AppendedOrder::get());
    }
}
```

## What the tool must say (`expect.php`)

Same verdict as `r-appends-new-style`; only the way the accessor is written differs.
