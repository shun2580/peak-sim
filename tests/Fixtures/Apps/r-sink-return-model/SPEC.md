# r-sink-return-model

**Purpose.** Returning models straight from the action serialises them: the accessors run.

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
use Illuminate\Database\Eloquent\Collection;

class TestController extends Controller
{
    public function index(): Collection
    {
        return AppendedOrder::get(); // PLANT: serialised by the framework
    }
}
```

## What the tool must say (`expect.php`)

No `response()->json()` here: the action's return value is itself the point where the models are turned into JSON.
