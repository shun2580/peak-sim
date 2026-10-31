# blade-foreach

**Purpose.** A relation read inside @foreach in a Blade view is an N+1, and the evidence points at the template line.

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
    <p>{{ $order->customer->name }}</p>{{-- PLANT: relation read in the loop --}}
@endforeach
```

## What the tool must say (`expect.php`)

The view is part of the request. `@foreach` is a loop over the orders; `$order->customer` is lazy. Line numbers of the template are kept.

## Notes for the writer

- `resources/views/t.blade.php` is exactly the 3 lines shown (no trailing blank lines, no HTML around it).
