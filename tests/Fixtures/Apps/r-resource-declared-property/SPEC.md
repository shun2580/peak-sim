# r-resource-declared-property

**Purpose.** A JsonResource that keeps the model in its own property (promoted or declared) and reads a relation from it
lazy-loads once per element, exactly like `$this->customer` or `$this->resource->customer`. The tool used to report
`O(1)`, confidence high, no gap and no lazy-load event for every `$this->order->customer` form (found by cmd_248 INV2).

Read `../_kit/SPEC.md` first (shared layout, standard models, rules). Only the differences are listed here.

**Routes** (as exported in `export.php`): `GET /<name>` → `TestController@<camelName>` for each name in `routes/web.php`.

## Files to write

The standard files, except `app/Models/Order.php` (it adds the `owner()` accessor that lazy-loads `customer`), one resource
per route (see `app/Http/Resources/`) and `app/Http/Controllers/TestController.php` (one method per route, each returning
`XxxResource::collection(Order::get())`, `XxxResource::make(Order::first())`, `new XxxResource(Order::first())` or
`Order::get()->map(fn (Order $o) => new XxxResource($o))` as the route name says). Every planted line carries a `// PLANT:` comment.

## What the tool must say (`expect.php`)

- Every route that serialises many resources (`collection`, `map(new)`): query and memory `O(N)` on `orders`, confidence high, no gap,
  and the lazy load of `customers` is found as an event (events_found 2: `select orders` and `lazy_load customers`).
- `make` and `new` wrap a single order: `O(1)` on both axes, but the lazy-load event is still found (events_found 2).
- The four controls (`plain-collection`, `promoted-via`, `unless-flag-plain`, `accessor-direct`) never lost the event and must not change.
