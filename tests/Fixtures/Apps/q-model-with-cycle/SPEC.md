# q-model-with-cycle

**Purpose.** Two models whose $with point at each other would load forever: the tool stops with a gap.

Read `../_kit/SPEC.md` first (shared layout, standard models, rules). Only the differences are listed here.

**Routes** (as exported in `export.php`):

- `GET /t` → `App\Http\Controllers\TestController@index`

## Files to write

### `app/Models/LoopOrder.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoopOrder extends Model
{
    protected $table = 'orders';

    protected $with = ['loopCustomer']; // PLANT: Order -> Customer ...

    public function loopCustomer()
    {
        return $this->belongsTo(LoopCustomer::class, 'customer_id');
    }
}
```

### `app/Models/LoopCustomer.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoopCustomer extends Model
{
    protected $table = 'customers';

    protected $with = ['loopOrders']; // PLANT: ... Customer -> Order

    public function loopOrders()
    {
        return $this->hasMany(LoopOrder::class, 'customer_id');
    }
}
```

### `app/Http/Controllers/TestController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Models\LoopOrder;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(LoopOrder::get());
    }
}
```

## What the tool must say (`expect.php`)

Loading a `LoopOrder` loads its `LoopCustomer`, whose `$with` loads `LoopOrder` again, and so on. The tool cuts the cycle and says so (`WITH_CYCLE`): both axes `unknown`.
