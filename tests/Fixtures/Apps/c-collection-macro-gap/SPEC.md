# c-collection-macro-gap

**Purpose.** A Collection method that does not exist may be a macro: unknown.

Read `../_kit/SPEC.md` first (shared layout, standard models, rules). Only the differences are listed here.

**Routes** (as exported in `export.php`):

- `GET /t` → `App\Http\Controllers\TestController@index`

## Files to write

### `app/Http/Controllers/TestController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Order::get()->customMacro()); // PLANT: not a Collection method
    }
}
```

## What the tool must say (`expect.php`)

The tool does not know `customMacro` and will not guess that it is harmless: gap `UNKNOWN_COLLECTION_METHOD`, both axes `unknown`. `Order::get()` was seen first, so memory has the lower bound `O(N)`.
