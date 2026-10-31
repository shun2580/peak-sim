# r-resource-nested

**Purpose.** A resource that nests another resource over a to-many relation.

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
            'items' => ItemResource::collection($this->items), // PLANT: items read once per order
        ];
    }
}
```

### `app/Http/Resources/ItemResource.php`

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product' => $this->product->name, // PLANT: one lazy load per item
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

Per order, `items` is a query (N); per item, `product` is a query (N*M). Memory holds every order's items.
