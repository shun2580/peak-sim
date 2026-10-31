# blade-unsupported-directive-gap

**Purpose.** @can calls the authorisation gate, which the tool does not model: the view becomes a gap.

Read `../_kit/SPEC.md` first (shared layout, standard models, rules). Only the differences are listed here.

**Routes** (as exported in `export.php`):

- `GET /t` → `App\Http\Controllers\TestController@index`

## Files to write

### `app/Http/Controllers/TestController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Contracts\View\View;

class TestController extends Controller
{
    public function index(): View
    {
        return view('t', ['orders' => Order::get()]);
    }
}
```

### `resources/views/t.blade.php`

```blade
@foreach ($orders as $order)
    @can('view', $order)
        <p>{{ $order->id }}</p>
    @endcan
@endforeach
```

## What the tool must say (`expect.php`)

`@can` is outside the supported directive set, so the view cannot be followed. Both axes are `unknown`. The only event found is `Order::get()`: queries have no lower bound (one constant query), memory has the lower bound `O(N)`.
