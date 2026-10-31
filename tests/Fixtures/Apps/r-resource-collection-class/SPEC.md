# r-resource-collection-class

**Purpose.** A ResourceCollection class that names its element resource.

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

### `app/Http/Resources/OrderCollection.php`

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;

class OrderCollection extends ResourceCollection
{
    public $collects = OrderResource::class;
}
```

### `app/Http/Controllers/TestController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Http\Resources\OrderCollection;
use App\Models\Order;

class TestController extends Controller
{
    public function index(): OrderCollection
    {
        return new OrderCollection(Order::get());
    }
}
```

## What the tool must say (`expect.php`)

The collection class says (`$collects`) which resource shows each element: same verdict as `r-resource-collection`.
