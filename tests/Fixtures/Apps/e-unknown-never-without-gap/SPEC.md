# e-unknown-never-without-gap

**Purpose.** A value from a function nobody defines is unknown, and unknown always comes with a gap.

Read `../_kit/SPEC.md` first (shared layout, standard models, rules). Only the differences are listed here.

**Routes** (as exported in `export.php`):

- `GET /t` → `App\Http\Controllers\TestController@index`

## Files to write

### `app/Http/Controllers/TestController.php`

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = undefined_helper(); // PLANT: no such function anywhere

        return response()->json(['count' => count($rows)]);
    }
}
```

## What the tool must say (`expect.php`)

The tool must not turn a missing function into `O(1)`. Both axes `unknown`, at least one gap (the harness also checks, for every case, that an `unknown` axis has a gap that affects it).
