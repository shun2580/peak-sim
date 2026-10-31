# r-resource-collection

**Purpose.** A relation read inside JsonResource::toArray() runs once per order.

Read `../_kit/SPEC.md` first (shared layout, standard models, rules). Only the differences are listed here.

**Routes** (as exported in `export.php`):

- `GET /t` → `App\Http\Controllers\TestController@index`

## Files to write

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
        return [
            'id' => $this->id,
            'customer' => $this->customer->name, // PLANT: lazy load per resource
        ];
    }
}
```

### `app/Http/Controllers/TestController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Http\Resources\OrderResource;
use App\Models\Order;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TestController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return OrderResource::collection(Order::get());
    }
}
```

## What the tool must say (`expect.php`)

`OrderResource::collection(...)` is serialised when returned; `toArray` runs once per order and `$this->customer` is not loaded.
