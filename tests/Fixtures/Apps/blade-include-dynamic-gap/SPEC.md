# blade-include-dynamic-gap

**Purpose.** An @include whose name is a variable cannot be followed.

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
        return view('t', ['orders' => Order::get(), 'partial' => 'partials.row']);
    }
}
```

### `resources/views/t.blade.php`

```blade
@foreach ($orders as $order)
    @include($partial, ['order' => $order]){{-- PLANT: the name is a variable --}}
@endforeach
```

### `resources/views/partials/row.blade.php`

```blade
<p>{{ $order->customer->name }}</p>
```

## What the tool must say (`expect.php`)

Which template is included is not known statically, so the tool does not guess that it is `partials.row`: gap `VIEW_NOT_FOUND`, both axes `unknown`.

## Notes for the writer

- The partial exists, but nothing names it as a literal: the tool must not read it.
