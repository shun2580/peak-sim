# q-scope-method

**Purpose.** A local scope (scopeRecent) is followed through its body.

Read `../_kit/SPEC.md` first (shared layout, standard models, rules). Only the differences are listed here.

**Routes** (as exported in `export.php`):

- `GET /t` → `App\Http\Controllers\TestController@index`

## Files to write

### `app/Http/Controllers/TestController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Order::recent()->get()); // PLANT: scope call
    }
}
```

### `app/Models/Order.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $table = 'orders';

    public function scopeRecent($query)
    {
        return $query->where('status', 'new')->latest(); // PLANT: a query scope
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function items()
    {
        return $this->hasMany(Item::class);
    }
}
```

## What the tool must say (`expect.php`)

`recent()` is not a method of the builder: the tool looks for `scopeRecent` in the model and walks it (it only adds conditions). An unbounded `get()`: memory O(N), queries O(1). No gap.
