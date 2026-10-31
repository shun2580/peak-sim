# bind-chain

**Purpose.** A binding that points at another abstraction (A -> B -> concrete class) is followed to the end.

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

### `app/Contracts/OrderSource.php`

```php
<?php

namespace App\Contracts;

interface OrderSource extends OrderReader
{
    //
}
```

### `app/Readers/EloquentOrderSource.php`

```php
<?php

namespace App\Readers;

use App\Contracts\OrderSource;
use App\Models\Order;

class EloquentOrderSource implements OrderSource
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
use App\Contracts\OrderSource;
use App\Readers\EloquentOrderSource;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OrderReader::class, OrderSource::class); // PLANT: an interface bound to another interface
        $this->app->bind(OrderSource::class, EloquentOrderSource::class);
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

The controller asks for `OrderReader`; the container gives `OrderSource`, which is itself bound to `EloquentOrderSource`. The tool follows the chain to the concrete class and finds its N+1: `O(N)`, no gap, no assumption (there is one candidate at every step).

## Notes for the writer

- `OrderSource` extends `OrderReader`, so `EloquentOrderSource implements OrderSource` is also an `OrderReader`. The class `EloquentOrderSource` is the standard reader class under another name (it implements `OrderSource`).
- There is no `EloquentOrderReader` in this case.
