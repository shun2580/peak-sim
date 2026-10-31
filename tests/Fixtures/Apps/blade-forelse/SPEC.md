# blade-forelse

**Purpose.** @forelse walks the loop body like @foreach (the @empty branch is a plain block).

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
@forelse ($orders as $order)
    <p>{{ $order->customer->name }}</p>{{-- PLANT --}}
@empty
    <p>No orders.</p>
@endforelse
```

## What the tool must say (`expect.php`)

Same verdict as `blade-foreach`.
