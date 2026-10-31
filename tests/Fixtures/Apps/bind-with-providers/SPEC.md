# bind-with-providers

**Purpose.** A provider added with withProviders() in bootstrap/app.php is read too.

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

### `app/Providers/AppServiceProvider.php`

```php
<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }
}
```

### `app/Providers/ReaderServiceProvider.php`

```php
<?php

namespace App\Providers;

use App\Contracts\OrderReader;
use App\Readers\EloquentOrderReader;
use Illuminate\Support\ServiceProvider;

class ReaderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OrderReader::class, EloquentOrderReader::class);
    }
}
```

### `bootstrap/app.php`

```php
<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withProviders([App\Providers\ReaderServiceProvider::class]) // PLANT: provider added here
    ->withRouting(web: __DIR__.'/../routes/web.php')
    ->withMiddleware(function (Middleware $middleware) {
        //
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
```

## What the tool must say (`expect.php`)

Same verdict as `bind-bind`: the binding is found through `withProviders([...])`.

## Notes for the writer

- `bootstrap/providers.php` lists only `AppServiceProvider`, whose `register()` is empty.
