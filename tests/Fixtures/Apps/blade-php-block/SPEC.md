# blade-php-block

**Purpose.** @php ... @endphp blocks are evaluated like PHP.

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
@php
    $total = 0;
@endphp
@foreach ($orders as $order)
    @php
        $total += $order->customer->id; // PLANT
    @endphp
@endforeach
<p>{{ $total }}</p>
```

## What the tool must say (`expect.php`)

The relation read sits in a `@php` block inside `@foreach`: queries O(N).
