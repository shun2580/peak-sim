# e-dynamic-class-gap

**Purpose.** new $class with a class name from the request is a gap.

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
        $class = $request->query('driver');
        $driver = new $class(); // PLANT: the class name is a variable
        $driver->run();

        return response()->json(Order::paginate(15));
    }
}
```

## What the tool must say (`expect.php`)

Which class is built is decided at run time, so what `run()` does is unknown: both axes `unknown`.
