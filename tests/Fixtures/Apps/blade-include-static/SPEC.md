# blade-include-static

**Purpose.** @include with a literal name is followed into the partial.

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
    @include('partials.row', ['order' => $order])
@endforeach
```

### `resources/views/partials/row.blade.php`

```blade
<p>{{ $order->customer->name }}</p>{{-- PLANT --}}
```

## What the tool must say (`expect.php`)

The partial is analysed with `$order` of the type passed in the include array. The evidence is in the partial, line 1.

## Notes for the writer

- `resources/views/partials/row.blade.php` is a single line.
