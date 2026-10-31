# q-model-with-unresolved

**Purpose.** A model whose $with cannot be read (a constant the tool cannot evaluate) must not be treated as having none.

Read `../_kit/SPEC.md` first (shared layout, standard models, rules). Only the differences are listed here.

**Routes** (as exported in `export.php`):

- `GET /t` → `App\Http\Controllers\TestController@index`

## Files to write

### `bootstrap/constants.php`

```php
<?php

define('ORDER_RELATION', 'customer');
```

### `composer.json`

```
{
    "name": "peak-sim/mini-app",
    "autoload": {
        "psr-4": {
            "App\\": "app/"
        },
        "files": [
            "bootstrap/constants.php"
        ]
    }
}
```

### `app/Models/UnresolvedOrder.php`

```php
<?php

namespace App\Models;

class UnresolvedOrder extends Order
{
    protected $table = 'orders';

    protected $with = [ORDER_RELATION]; // PLANT: a global constant defined with define(), not a literal
}
```

### `app/Http/Controllers/TestController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Models\UnresolvedOrder;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(UnresolvedOrder::get());
    }
}
```

## What the tool must say (`expect.php`)

Whatever `ORDER_RELATION` holds, a `$with` adds queries to every `get()`. The tool cannot evaluate a constant made by `define()`, so it must not drop the property and count as if there were no `$with` (that would under-count). The model is marked unresolved and the use of it is a gap `MODEL_PROPERTY_UNRESOLVED` on both axes: both `unknown`.

## Notes for the writer

- `composer.json` of this case replaces the standard one (it adds `autoload.files` so that the constant is loaded, as it would be in a real app). Write it exactly as shown.
- `bootstrap/constants.php` is a new file; the standard `bootstrap/app.php` does not mention it.
