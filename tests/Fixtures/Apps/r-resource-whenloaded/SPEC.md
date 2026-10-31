# r-resource-whenloaded

**Purpose.** whenLoaded() never triggers a lazy load: with no eager load the resource is safe.

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
            'customer' => $this->whenLoaded('customer'), // PLANT: reads the relation only if already loaded
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

`Order::get()` has no `with()`, and `whenLoaded` does not load: no query per row. Memory O(N).
