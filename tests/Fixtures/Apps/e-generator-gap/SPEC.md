# e-generator-gap

**Purpose.** A method that yields is a generator: its body runs lazily, and the tool does not analyse it.

Read `../_kit/SPEC.md` first (shared layout, standard models, rules). Only the differences are listed here.

**Routes** (as exported in `export.php`):

- `GET /t` → `App\Http\Controllers\TestController@index`

## Files to write

### `app/Http/Controllers/TestController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        $count = 0;
        foreach ($this->rows() as $id) { // PLANT: a generator
            $count++;
        }

        return response()->json(['count' => $count]);
    }

    private function rows(): \Generator
    {
        foreach (Order::get() as $order) {
            yield $order->id;
        }
    }
}
```

## What the tool must say (`expect.php`)

The query is inside `rows()`, which only runs when the loop pulls from it. The tool does not model that: gap `GENERATOR_NOT_ANALYZED`, both axes `unknown`, never `O(1)`.
