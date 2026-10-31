# r-appends-new-style

**Purpose.** A model with $appends and an Attribute accessor that reads a relation costs a query per serialised model.

Read `../_kit/SPEC.md` first (shared layout, standard models, rules). Only the differences are listed here.

**Routes** (as exported in `export.php`):

- `GET /t` → `App\Http\Controllers\TestController@index`

## Files to write

### `app/Models/AppendedOrder.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;

class AppendedOrder extends Order
{
    protected $table = 'orders';

    protected $appends = ['customer_name'];

    protected function customerName(): Attribute
    {
        return Attribute::get(fn () => $this->customer->name); // PLANT: lazy load when serialised
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

`response()->json(...)` serialises each model; `$appends` makes `customerName` run for each; its `$this->customer` is a lazy load.
