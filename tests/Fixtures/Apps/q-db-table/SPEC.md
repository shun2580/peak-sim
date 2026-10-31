# q-db-table

**Purpose.** DB::table() reads a table like a model does.

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
        return response()->json(DB::table('orders')->get()); // PLANT: query builder, no model
    }
}
```

## What the tool must say (`expect.php`)

One unbounded query on `orders`.
