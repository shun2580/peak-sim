# bind-decorated-gap

**Purpose.** A binding that is decorated with extend() cannot be followed to one implementation.

Read `../_kit/SPEC.md` first (shared layout, standard models, rules). Only the differences are listed here.

**Routes** (as exported in `export.php`):

- `GET /t` → `App\Http\Controllers\TestController@index`

**`bootstrap/providers.php`** returns exactly:

```php
<?php

return [
    App\Providers\AppServiceProvider::class,
];
```

## Files to write

### `app/Contracts/OrderReader.php`

```php
<?php

namespace App\Contracts;

interface OrderReader
{
    public function names(): array;
}
```

### `app/Readers/EloquentOrderReader.php`

```php
<?php

namespace App\Readers;

use App\Contracts\OrderReader;
use App\Models\Order;

class EloquentOrderReader implements OrderReader
{
    public function names(): array
    {
        return Order::get() // PLANT: customer is read per order
            ->map(fn (Order $order) => $order->customer->name)
            ->all();
    }
}
```

### `app/Http/Controllers/TestController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Contracts\OrderReader;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function __construct(private readonly OrderReader $reader) {}

    public function index(): JsonResponse
    {
        return response()->json($this->reader->names());
    }
}
```

### `app/Readers/CachingOrderReader.php`

```php
<?php

namespace App\Readers;

use App\Contracts\OrderReader;

class CachingOrderReader implements OrderReader
{
    public function __construct(private readonly OrderReader $inner)
    {
    }

    public function names(): array
    {
        return $this->inner->names();
    }
}
```

### `app/Providers/AppServiceProvider.php`

```php
<?php

namespace App\Providers;

use App\Contracts\OrderReader;
use App\Readers\EloquentOrderReader;
use App\Readers\CachingOrderReader;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OrderReader::class, EloquentOrderReader::class);
        $this->app->extend(OrderReader::class, fn (OrderReader $reader) => new CachingOrderReader($reader)); // PLANT: decorated
    }
}
```

## What the tool must say (`expect.php`)

`extend()` wraps the bound object in something else, so the implementation behind the interface is no longer known: gap `BINDING_DECORATED`, both axes `unknown`.
