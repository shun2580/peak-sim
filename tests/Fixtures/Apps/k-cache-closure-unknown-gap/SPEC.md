# k-cache-closure-unknown-gap

**Purpose.** If what is cached cannot be followed, neither side of the cache can be judged.

Read `../_kit/SPEC.md` first (shared layout, standard models, rules). Only the differences are listed here.

**Routes** (as exported in `export.php`):

- `GET /t` → `App\Http\Controllers\TestController@index`

## Files to write

### `app/Http/Controllers/TestController.php`

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = Cache::remember('rows', 300, fn () => undefined_helper()); // PLANT: the closure cannot be followed

        return response()->json($rows);
    }
}
```

## What the tool must say (`expect.php`)

The closure calls a function nobody defines (a gap on the miss side), and what a hit would restore is of unknown size (`CACHE_VALUE_SIZE_UNKNOWN`). Both axes `unknown`.
