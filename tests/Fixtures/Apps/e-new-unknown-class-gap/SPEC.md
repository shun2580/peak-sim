# e-new-unknown-class-gap

**Purpose.** new of a class that is neither in the app nor known is a gap.

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
        $client = new \Vendor\Sdk\Client(); // PLANT: a class from nowhere
        $client->send('x');

        return response()->json(Order::paginate(15));
    }
}
```

## What the tool must say (`expect.php`)

The rest of the route is `q-paginate` (`O(1)`/`O(1)`), but not knowing what `new \Vendor\Sdk\Client()` does must not look safe: both axes `unknown`.
