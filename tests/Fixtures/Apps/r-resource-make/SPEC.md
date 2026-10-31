# r-resource-make

**Purpose.** A single-model resource reads the relation once.

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
            'customer' => $this->customer->name, // PLANT: one lazy load
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
use Illuminate\Http\Resources\Json\JsonResource;

class TestController extends Controller
{
    public function index(): JsonResource
    {
        return OrderResource::make(Order::first());
    }
}
```

## What the tool must say (`expect.php`)

`first()` reads one row and the resource reads its customer once: nothing grows.
