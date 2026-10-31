# blade-with-chain

**Purpose.** view()->with() passes a variable.

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
        return view('t')->with('orders', Order::get()); // PLANT: ->with()
    }
}
```

### `resources/views/t.blade.php`

```blade
@foreach ($orders as $order)
    <p>{{ $order->customer->name }}</p>{{-- PLANT --}}
@endforeach
```

## What the tool must say (`expect.php`)

Same verdict as `blade-foreach`.
