# blade-email-not-directive

**Purpose.** An e-mail address in the template is not a directive.

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
    <a href="mailto:support@example.com">{{ $order->customer->name }}</a>{{-- PLANT --}}
@endforeach
```

## What the tool must say (`expect.php`)

`support@example.com` has a letter before the `@`: it is text, not the directive `@example`. No `BLADE_UNSUPPORTED` gap.
