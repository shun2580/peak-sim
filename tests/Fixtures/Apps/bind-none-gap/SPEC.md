# bind-none-gap

**Purpose.** An interface with no binding at all cannot be followed: both axes are unknown, never O(1).

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

## What the tool must say (`expect.php`)

Nothing is bound to `OrderReader`, so the call `$this->reader->names()` cannot be resolved. `events_found` is 0 **and** there is a gap: "could not follow", not "nothing there".

## Notes for the writer

- No implementation class of `OrderReader` may exist anywhere in `app/`. The standard `AppServiceProvider` stays empty.
