# bind-multi-impl-worst

**Purpose.** Two providers bind the same interface to different implementations; the tool must take the worse one and say so.

Read `../_kit/SPEC.md` first (shared layout, standard models, rules). Only the differences are listed here.

**Routes** (as exported in `export.php`):

- `GET /t` → `App\Http\Controllers\TestController@index`

**`bootstrap/providers.php`** returns exactly:

```php
<?php

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\ReaderServiceProvider::class,
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

### `app/Providers/AppServiceProvider.php`

```php
<?php

namespace App\Providers;

use App\Contracts\OrderReader;
use App\Readers\EagerOrderReader;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OrderReader::class, EagerOrderReader::class);
    }
}
```

### `app/Providers/ReaderServiceProvider.php`

```php
<?php

namespace App\Providers;

use App\Contracts\OrderReader;
use App\Readers\LazyOrderReader;
use Illuminate\Support\ServiceProvider;

class ReaderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OrderReader::class, LazyOrderReader::class);
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

## What the tool must say (`expect.php`)

The environment is unknown, so no binding wins: both are walked and the worst is adopted (design 1.8). The two candidates differ in queries (0 vs N) but not in memory (both load every order), so the assumption lowers only the **query** axis to `medium`; the memory axis stays `high`; the route is the lower of the two.

## Notes for the writer

- Both providers are listed in `bootstrap/providers.php`, in this order.
