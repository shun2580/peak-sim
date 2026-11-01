# bind-closure-return-type-gap

**Purpose.** A closure bound by its return type whose body picks the class from a config value cannot be followed: both axes are unknown, never O(1). The return type is the abstract, not the implementation.

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

### `app/Providers/AppServiceProvider.php`

```php
<?php

namespace App\Providers;

use App\Contracts\OrderReader;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(static fn (): OrderReader => app(config('readers.orders'))); // PLANT: the class is chosen by a config value
    }
}
```

## What the tool must say (`expect.php`)

The abstract `OrderReader` is known (the return type) but the body does not name a class, so the binding cannot be followed. The declared return type is an interface here and would be only an upper bound for a class: it is never taken as the implementation. Query and memory are `unknown` with `UNRESOLVABLE_BINDING`.
