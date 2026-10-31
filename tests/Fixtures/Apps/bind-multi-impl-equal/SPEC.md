# bind-multi-impl-equal

**Purpose.** Two bindings for one interface whose implementations behave the same: no worst case to speak of.

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

### `app/Readers/LegacyOrderReader.php`

```php
<?php

namespace App\Readers;

use App\Contracts\OrderReader;
use App\Models\Order;

class LegacyOrderReader implements OrderReader
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
use App\Readers\EloquentOrderReader;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OrderReader::class, EloquentOrderReader::class);
    }
}
```

### `app/Providers/ReaderServiceProvider.php`

```php
<?php

namespace App\Providers;

use App\Contracts\OrderReader;
use App\Readers\LegacyOrderReader;
use Illuminate\Support\ServiceProvider;

class ReaderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OrderReader::class, LegacyOrderReader::class);
    }
}
```

## What the tool must say (`expect.php`)

Both candidates are walked, but they agree on both axes, so no `WORST_CASE_OF_CANDIDATES` is recorded: confidence stays `high` and `worst_case_adopted` is false (design 1.8: no needless `medium`).
