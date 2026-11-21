# r-resource-eager-declared

**Purpose.** A JsonResource that keeps the model it wraps in its own property (promoted, or declared and assigned from the
constructor argument it also hands to `parent::__construct()`) reads that very model. When the model came from a query that
loaded the relation (`Order::with('customer')`), `$this->order->customer` runs no query. The tool used to read the property by
its declared type alone, which made a fresh model without the loaded relations, and reported a lazy load per element that does
not exist (found by the real-app trial, REM2, on a real app). The lazy loads that do exist must stay found: this case holds both sides.

Read `../_kit/SPEC.md` first (shared layout, standard models, rules). Only the differences are listed here.
The standard `app/Models/Order.php` is used as is (no `owner()` accessor).

**Routes** (as exported in `export.php`): `GET /<name>` → `TestController@<camelName>` for each name in `routes/web.php`.

## Files to write

The standard files, one resource per class in `app/Http/Resources/` and `app/Http/Controllers/TestController.php` (one method per
route). Every planted line carries a `// PLANT:` comment.

## What the tool must say (`expect.php`)

- The `*-eager*` routes: the order is loaded with the relation the resource reads, the property holds the wrapped model:
  query `O(1)`, no lazy-load event. `/nested-eager` loads `items.product`.
- `/promoted-lazy`, `/prop-lazy`: the same resources over `Order::get()`: the lazy load of `customers` is found (`O(N)`).
- `/promoted-other-relation`: `with('items')` is not the relation the resource reads: still a lazy load of `customers`.
- `/nested-shallow`: `items` is loaded, `product` is not: one lazy load per item (`O(N*M)`).
- `/other-order`: the resource has a property of the same class that is **not** the model it wraps (a second constructor argument):
  nothing is known of that model, its relation is a lazy load as before. (`Order::find($o->id)` is the second
  argument: a model without loaded relations; the tool assumes its argument is a scalar, so the route is medium.)
- `/wrapped-second`: the property holds the model the resource wraps, but that is the **second** constructor argument while the
  call site loaded the first one: the loaded relations belong to another model, the lazy load is found.
- `/buyer`: the property is a model of **another class** than the one wrapped: read as before.
