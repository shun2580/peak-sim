# bind-contextual

**Purpose.** A contextual binding (when/needs/give) is chosen by the class that asks for the interface.

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

### `app/Readers/EagerOrderReader.php`

```php
<?php

namespace App\Readers;

use App\Contracts\OrderReader;
use App\Models\Order;

class EagerOrderReader implements OrderReader
{
    public function names(): array
    {
        return Order::with('customer')->get()
            ->map(fn (Order $order) => $order->customer->name)
            ->all();
    }
}
```

### `app/Readers/LazyOrderReader.php`

```php
<?php

namespace App\Readers;

use App\Contracts\OrderReader;
use App\Models\Order;

class LazyOrderReader implements OrderReader
{
    public function names(): array
    {
        return Order::get() // PLANT: customer is read per order
            ->map(fn (Order $order) => $order->customer->name)
            ->all();
    }
}
```

### `app/Http/Controllers/OtherController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Contracts\OrderReader;
use Illuminate\Http\JsonResponse;

class OtherController extends Controller
{
    public function __construct(private readonly OrderReader $reader) {}

    public function index(): JsonResponse
    {
        return response()->json($this->reader->names());
    }
}
```

### `app/Providers/AppServiceProvider.php`

```php
<?php

namespace App\Providers;

use App\Contracts\OrderReader;
use App\Http\Controllers\OtherController;
use App\Http\Controllers\TestController;
use App\Readers\EagerOrderReader;
use App\Readers\LazyOrderReader;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->when(TestController::class)->needs(OrderReader::class)->give(LazyOrderReader::class); // PLANT
        $this->app->when(OtherController::class)->needs(OrderReader::class)->give(EagerOrderReader::class);
    }
}
```

## What the tool must say (`expect.php`)

Only `TestController` is routed, and for it the lazy implementation is given. The eager one is for `OtherController`, which no route reaches. So one candidate: `O(N)` with confidence `high` and no assumption.

## Notes for the writer

- `OtherController` exists but is **not** in `routes/web.php` and not in `export.php`. `EloquentOrderReader` (standard for the binding cases) is not needed here: do not write it.
