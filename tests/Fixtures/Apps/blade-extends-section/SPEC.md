# blade-extends-section

**Purpose.** @extends with @section: the child's section is walked in the layout.

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

### `resources/views/layouts/app.blade.php`

```blade
<html>
<body>
    @yield('content')
</body>
</html>
```

### `resources/views/t.blade.php`

```blade
@extends('layouts.app')

@section('content')
    @foreach ($orders as $order)
        <p>{{ $order->customer->name }}</p>{{-- PLANT --}}
    @endforeach
@endsection
```

## What the tool must say (`expect.php`)

The loop is in the child's section, which the layout yields: queries O(N).
