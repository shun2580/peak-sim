# bind-vendor-interface-gap

**Purpose.** An interface that belongs to the framework (not the app) has no binding the tool can read.

Read `../_kit/SPEC.md` first (shared layout, standard models, rules). Only the differences are listed here.

**Routes** (as exported in `export.php`):

- `GET /t` → `App\Http\Controllers\TestController@index`

## Files to write

### `app/Http/Controllers/TestController.php`

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function index(Filesystem $files): JsonResponse
    {
        $content = $files->get('report.txt'); // PLANT: vendor interface, no app binding

        return response()->json(['size' => strlen($content)]);
    }
}
```

## What the tool must say (`expect.php`)

The container would give some filesystem; the app does not say which, and `get()` is not on the allow-list. Whichever way the tool reports it (`NO_BINDING` or `VENDOR_CALL_NOT_MODELLED`), the answer is `unknown` on both axes.
