# e-depth-21

**Purpose.** A chain of 21 calls from the route action to the N+1.

Read `../_kit/SPEC.md` first (shared layout, standard models, rules). Only the differences are listed here.

**Routes** (as exported in `export.php`):

- `GET /t` → `App\Http\Controllers\TestController@index`

## Files to write

### `app/Http/Controllers/TestController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Chain\Level02;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function __construct(private readonly Level02 $next) {}

    public function index(): JsonResponse
    {
        return response()->json($this->next->run());
    }
}
```

### `app/Chain/Level02.php`

```php
<?php

namespace App\Chain;

class Level02
{
    public function __construct(private readonly Level03 $next)
    {
    }

    public function run(): array
    {
        return $this->next->run();
    }
}
```

### `app/Chain/Level03.php`

```php
<?php

namespace App\Chain;

class Level03
{
    public function __construct(private readonly Level04 $next)
    {
    }

    public function run(): array
    {
        return $this->next->run();
    }
}
```

### `app/Chain/Level04.php`

```php
<?php

namespace App\Chain;

class Level04
{
    public function __construct(private readonly Level05 $next)
    {
    }

    public function run(): array
    {
        return $this->next->run();
    }
}
```

### `app/Chain/Level05.php`

```php
<?php

namespace App\Chain;

class Level05
{
    public function __construct(private readonly Level06 $next)
    {
    }

    public function run(): array
    {
        return $this->next->run();
    }
}
```

### `app/Chain/Level06.php`

```php
<?php

namespace App\Chain;

class Level06
{
    public function __construct(private readonly Level07 $next)
    {
    }

    public function run(): array
    {
        return $this->next->run();
    }
}
```

### `app/Chain/Level07.php`

```php
<?php

namespace App\Chain;

class Level07
{
    public function __construct(private readonly Level08 $next)
    {
    }

    public function run(): array
    {
        return $this->next->run();
    }
}
```

### `app/Chain/Level08.php`

```php
<?php

namespace App\Chain;

class Level08
{
    public function __construct(private readonly Level09 $next)
    {
    }

    public function run(): array
    {
        return $this->next->run();
    }
}
```

### `app/Chain/Level09.php`

```php
<?php

namespace App\Chain;

class Level09
{
    public function __construct(private readonly Level10 $next)
    {
    }

    public function run(): array
    {
        return $this->next->run();
    }
}
```

### `app/Chain/Level10.php`

```php
<?php

namespace App\Chain;

class Level10
{
    public function __construct(private readonly Level11 $next)
    {
    }

    public function run(): array
    {
        return $this->next->run();
    }
}
```

### `app/Chain/Level11.php`

```php
<?php

namespace App\Chain;

class Level11
{
    public function __construct(private readonly Level12 $next)
    {
    }

    public function run(): array
    {
        return $this->next->run();
    }
}
```

### `app/Chain/Level12.php`

```php
<?php

namespace App\Chain;

class Level12
{
    public function __construct(private readonly Level13 $next)
    {
    }

    public function run(): array
    {
        return $this->next->run();
    }
}
```

### `app/Chain/Level13.php`

```php
<?php

namespace App\Chain;

class Level13
{
    public function __construct(private readonly Level14 $next)
    {
    }

    public function run(): array
    {
        return $this->next->run();
    }
}
```

### `app/Chain/Level14.php`

```php
<?php

namespace App\Chain;

class Level14
{
    public function __construct(private readonly Level15 $next)
    {
    }

    public function run(): array
    {
        return $this->next->run();
    }
}
```

### `app/Chain/Level15.php`

```php
<?php

namespace App\Chain;

class Level15
{
    public function __construct(private readonly Level16 $next)
    {
    }

    public function run(): array
    {
        return $this->next->run();
    }
}
```

### `app/Chain/Level16.php`

```php
<?php

namespace App\Chain;

class Level16
{
    public function __construct(private readonly Level17 $next)
    {
    }

    public function run(): array
    {
        return $this->next->run();
    }
}
```

### `app/Chain/Level17.php`

```php
<?php

namespace App\Chain;

class Level17
{
    public function __construct(private readonly Level18 $next)
    {
    }

    public function run(): array
    {
        return $this->next->run();
    }
}
```

### `app/Chain/Level18.php`

```php
<?php

namespace App\Chain;

class Level18
{
    public function __construct(private readonly Level19 $next)
    {
    }

    public function run(): array
    {
        return $this->next->run();
    }
}
```

### `app/Chain/Level19.php`

```php
<?php

namespace App\Chain;

class Level19
{
    public function __construct(private readonly Level20 $next)
    {
    }

    public function run(): array
    {
        return $this->next->run();
    }
}
```

### `app/Chain/Level20.php`

```php
<?php

namespace App\Chain;

class Level20
{
    public function __construct(private readonly Level21 $next)
    {
    }

    public function run(): array
    {
        return $this->next->run();
    }
}
```

### `app/Chain/Level21.php`

```php
<?php

namespace App\Chain;

use App\Models\Order;

class Level21
{
    public function run(): array
    {
        $names = [];
        foreach (Order::get() as $order) {
            $names[] = $order->customer->name; // PLANT: the only query, at call depth 21
        }

        return $names;
    }
}
```

## What the tool must say (`expect.php`)

The call into level 21 is past the limit: it is a `DEPTH_LIMIT` gap, `truncated` is true and both axes are `unknown` (the query at the bottom was never seen, so there is no lower bound).

## Notes for the writer

- Files `app/Chain/Level02.php` … `Level21.php`: 20 classes in all, one per call level below the controller. Levels 2 to 20 have the same shape (a promoted constructor property for the next level, `run()` returns `$this->next->run()`); the last level holds the loop.
- The controller is call level 1. Counting: controller::index (1) → Level02::run (2) → … → Level21::run (21).
