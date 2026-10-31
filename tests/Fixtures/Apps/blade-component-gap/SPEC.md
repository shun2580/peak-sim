# blade-component-gap

**Purpose.** A Blade component (<x-...>) is outside the supported set.

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
    <x-order-row :order="$order" />{{-- PLANT: a component --}}
@endforeach
```

## What the tool must say (`expect.php`)

The component's class and view are not followed: gap `BLADE_UNSUPPORTED`, both axes `unknown`; memory keeps the lower bound `O(N)` from `Order::get()`.

## Notes for the writer

- Do **not** create the component (no `app/View/Components`, no `resources/views/components`).
