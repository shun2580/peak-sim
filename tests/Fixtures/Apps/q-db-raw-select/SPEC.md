# q-db-raw-select

**Purpose.** Raw SQL is not parsed: the table is unknown and the size is assumed unbounded.

Read `../_kit/SPEC.md` first (shared layout, standard models, rules). Only the differences are listed here.

**Routes** (as exported in `export.php`):

- `GET /t` → `App\Http\Controllers\TestController@index`

## Files to write

### `app/Http/Controllers/TestController.php`

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(DB::select('select * from orders')); // PLANT: raw SQL
    }
}
```

## What the tool must say (`expect.php`)

The tool does not read SQL strings. It cannot name the table (`n_table` is null) and, to stay on the safe side, assumes the rows are not bounded: memory `O(N)`, with the assumption `SQL_NOT_PARSED` and confidence `medium`.
