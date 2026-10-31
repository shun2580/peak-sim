# q-observer-gap

**Purpose.** A model that has an observer may run hidden queries when it is saved: gap.

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
        $order = Order::first();
        $order->status = 'paid';
        $order->save(); // PLANT: the exported model has an observer

        return response()->json(['id' => $order->id]);
    }
}
```

## What the tool must say (`expect.php`)

`model:show` (see `export.php`) lists an observer for `Order`. What it does on `saved` is not analysed, so the write is a gap: both axes `unknown`.

## Notes for the writer

- The model `Order` is the standard one. The *export* is what says it has an observer and a dispatched event (in the form `model:show` prints: `events` = `[{event, class}]`, `observers` = `[{event, observer: [...]}]`); the app needs no observer or event class.
