# k-validator-gap

**Purpose.** validate() can run an exists/unique rule, which is a query: not modelled, so a gap.

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
use Illuminate\Http\Request;

class TestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['customer_id' => 'exists:customers,id']); // PLANT: an exists rule queries the database

        return response()->json(Order::paginate(15));
    }
}
```

## What the tool must say (`expect.php`)

Same reasoning as `k-gate-gap`.
