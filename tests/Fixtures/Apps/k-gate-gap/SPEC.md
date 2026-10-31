# k-gate-gap

**Purpose.** Authorisation goes through the gate, which can run policy code with queries: not modelled, so a gap.

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
use Illuminate\Support\Facades\Gate;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize('list-orders'); // PLANT: the gate may call any policy

        return response()->json(Order::paginate(15));
    }
}
```

## What the tool must say (`expect.php`)

The rest is `q-paginate`; the gate call is not on the allow-list (it calls back into app code that the tool does not follow): both axes `unknown`.
