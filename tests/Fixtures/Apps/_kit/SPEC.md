# Shared notes for every mini-app (read this first)

A **mini-app** is a tiny Laravel-shaped source tree that plants exactly one analysis situation. Each case
directory `tests/Fixtures/Apps/<case>/` is its own app root. The static analyser reads it as text: Laravel is
**not installed**, nothing is ever executed, `composer install` is never run.

Per case, three files already exist and are not yours: `expect.php` (what the tool must say), `export.php` (what
`artisan route:list` / `model:show` would have printed), `SPEC.md` (the case). **You write only**
`app/**`, `bootstrap/**`, `routes/**`, `resources/**`, `composer.json` of the case. If the SPEC and `expect.php`
seem to disagree, do not fix either: report it.

## Rules

1. Write what the SPEC shows, nothing more. No extra helpers, no extra queries, no "improvements". A stray
   `->with()`, `limit()` or method call changes the expected verdict.
2. PHP 8.2 syntax. Plain PHP, no `declare(strict_types=1)` needed. One class per file, PSR-4 (`App\` → `app/`).
3. The code only has to be **parseable and faithful to Laravel idioms**; it is never run. Use real Laravel
   class names (`Illuminate\Database\Eloquent\Model`, `Illuminate\Http\JsonResponse`, `Illuminate\Support\Facades\Cache`, …).
4. Mark the planted line with a comment `// PLANT: <what>` so a reader can find it. Do not mark anything else.
5. Business names are forbidden. Use the standard domain: orders, customers, items, products.
6. Files that the SPEC does not mention are the **standard files** below, copied verbatim.
7. Do not touch `expect.php`, `export.php`, `SPEC.md`, another case, or anything outside your case directory.

## Standard files (every case has these unless its SPEC says otherwise)

`composer.json`

```json
{
    "name": "peak-sim/mini-app",
    "autoload": {
        "psr-4": {
            "App\\": "app/"
        }
    }
}
```

`bootstrap/app.php`

```php
<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php')
    ->withMiddleware(function (Middleware $middleware) {
        //
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
```

`bootstrap/providers.php`

```php
<?php

return [
    App\Providers\AppServiceProvider::class,
];
```

`app/Providers/AppServiceProvider.php`

```php
<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }
}
```

`app/Http/Controllers/Controller.php`

```php
<?php

namespace App\Http\Controllers;

abstract class Controller
{
    //
}
```

`routes/web.php` — one line per route listed in the SPEC, in this form (the default route is `GET /t`):

```php
<?php

use App\Http\Controllers\TestController;
use Illuminate\Support\Facades\Route;

Route::get('/t', [TestController::class, 'index']);
```

### Standard models (`app/Models/`)

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $table = 'orders';

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function items()
    {
        return $this->hasMany(Item::class);
    }
}
```

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $table = 'customers';

    public function orders()
    {
        return $this->hasMany(Order::class);
    }
}
```

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Item extends Model
{
    protected $table = 'items';

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
```

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $table = 'products';

    public function items()
    {
        return $this->hasMany(Item::class);
    }
}
```

Tables: `orders` (status, total, customer_id), `customers` (name, email), `items` (order_id, product_id, quantity),
`products` (name, price). There is no database; the export (`export.php`) says the same.

## How a SPEC is laid out

- **Routes**: which routes the case exports.
- **Files to write**: the case-specific files with their full content. Files named in the SPEC replace the
  standard file of the same path.
- **What the tool must say**: the expectation, with the reason. It is there so you understand the plant; it is not
  yours to implement or change.
