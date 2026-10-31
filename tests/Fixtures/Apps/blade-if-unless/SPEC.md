# blade-if-unless

**Purpose.** Conditionals around the planted read do not hide it: @if, @unless and @isset are walked.

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
@if ($orders->isNotEmpty())
    @foreach ($orders as $order)
        @unless ($order->total > 100)
            <p>{{ $order->customer->name }}</p>{{-- PLANT --}}
        @endunless
    @endforeach
@endif
```

## What the tool must say (`expect.php`)

A branch that may not run is still counted (superset, design H8). No gap: `@if`, `@unless`, `@foreach` are all in the supported set.
