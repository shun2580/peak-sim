# blade-nested-foreach-relation

**Purpose.** A foreach inside a foreach over a to-many relation.

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
    @foreach ($order->items as $item)
        <p>{{ $item->product->name }}</p>{{-- PLANT --}}
    @endforeach
@endforeach
```

## What the tool must say (`expect.php`)

As `/ng/nested`: `items` is read per order and `product` per item.
