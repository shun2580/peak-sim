# k-dispatch-gap

**Purpose.** event() runs listeners the tool cannot see: a gap.

Read `../_kit/SPEC.md` first (shared layout, standard models, rules). Only the differences are listed here.

**Routes** (as exported in `export.php`):

- `GET /t` → `App\Http\Controllers\TestController@index`

## Files to write

### `app/Events/OrderViewed.php`

```php
<?php

namespace App\Events;

class OrderViewed
{
    //
}
```

### `app/Http/Controllers/TestController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Events\OrderViewed;
use App\Models\Order;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        event(new OrderViewed()); // PLANT: listeners are not followed

        return response()->json(Order::paginate(15));
    }
}
```

## What the tool must say (`expect.php`)

Whatever listeners are registered may query; the tool cannot see them. Both axes `unknown`; the gap code is whichever the tool uses for a helper function that is not on the allow-list.

## Notes for the writer

- No listener, no provider registration: just the event class.
