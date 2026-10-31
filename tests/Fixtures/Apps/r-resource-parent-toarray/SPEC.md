# r-resource-parent-toarray

**Purpose.** A resource that delegates to parent::toArray() serialises the model, accessors included.

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

### `app/Http/Resources/OrderResource.php`

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return parent::toArray($request); // PLANT: serialises the model, appended accessors run
    }
}
```

### `app/Http/Controllers/TestController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Http\Resources\OrderResource;
use App\Models\AppendedOrder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TestController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return OrderResource::collection(AppendedOrder::get());
    }
}
```

## What the tool must say (`expect.php`)

`parent::toArray()` is the model's own serialisation: `$appends` runs `getCustomerNameAttribute` for each order, which lazy-loads `customer`.
