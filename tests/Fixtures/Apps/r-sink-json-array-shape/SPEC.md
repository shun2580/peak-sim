# r-sink-json-array-shape

**Purpose.** Models inside a literal array given to response()->json() are serialised too.

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
        return response()->json(['orders' => AppendedOrder::get(), 'meta' => ['source' => 'db']]); // PLANT: models inside an array
    }
}
```

## What the tool must say (`expect.php`)

The tool looks inside the array literal for models: `['orders' => <models>]` is serialised like the models themselves.
