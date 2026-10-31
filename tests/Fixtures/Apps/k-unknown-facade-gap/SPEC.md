# k-unknown-facade-gap

**Purpose.** A facade call that is not on the allow-list is a gap, even if the rest of the route is harmless.

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
use Illuminate\Support\Facades\Storage;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        Storage::disk('s3')->put('report.txt', 'x'); // PLANT: a vendor call the tool does not model

        return response()->json(Order::paginate(15));
    }
}
```

## What the tool must say (`expect.php`)

Without the `Storage` line this is `q-paginate` (O(1)/O(1)). With it, "not known" must not become "safe": both axes are `unknown`. Further gaps caused by calling `put` on the unknown value are allowed (`gaps_include`).
