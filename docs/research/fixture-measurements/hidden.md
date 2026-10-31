# Fixture measurements: /hidden group (7 routes)

Measured on Laravel 13.39, PHP 8.3.36, MySQL 8.4.7 (Docker). Session and cache drivers are `array` (the `phpunit.xml` defaults), so no session or cache queries are mixed in. `/hidden/cache` uses `CACHE_STORE=array`.
Row counts: orders = 10 / 100 / 1000 for queries and 100 / 1000 / 10000 for memory (other tables fixed: customers 20, products 5, 2 items per order). Full output: `hidden.log` (lines starting with `[measure]` are the raw numbers). Tests: `fixtures/laravel-app/tests/Feature/Hidden/`.

How it was measured:

- Queries: the verdict comes from `ralder/phpunit-query-scaling` (`assertQueriesScaleConstantly` / `assertQueryScaling` with `atMostSlope(1, 0)`). The numbers below come from a plain `DB::listen` counter on the same requests, because the package only prints numbers when an assertion fails. Class rule: O(1) = identical count at every scale; O(N) = the count rises by at least 0.5 query per added row between the smallest and the largest scale.
- Memory: peak bytes above the starting usage (`MeasuresMemory`, which resets the peak and collects garbage first), for one HTTP request made inside the test, after one unmeasured warm-up request. Reference values only (D18). Rule fixed before measuring: growth ratio = peak(10000) / peak(1000), i.e. for 10x the rows; O(1) needs ratio < 2, O(N) needs ratio >= 5, anything between is "unclear" (and fails).
- Each scale runs in a transaction on freshly seeded data (`DatabaseSeeder::sized`), rolled back afterwards.

## Result

All seven routes match `expected.json`. 24 tests, 180 assertions, 0 failed, 0 skipped. Nothing was loosened: no skip, no changed expectation, no relaxed threshold.

| Route | Expected query | Measured queries (100 / 1000 / 10000 orders) | Expected memory | Measured peak bytes (100 / 1000 / 10000) | Growth ratio | Verdict |
| --- | --- | --- | --- | --- | --- | --- |
| `/hidden/blade` | O(N) | 101 / 1,001 / 10,001 | O(N) | 472,344 / 3,480,392 / 34,583,488 | 9.94 | match |
| `/hidden/resource` | O(N) | 101 / 1,001 / 10,001 | O(N) | 499,472 / 3,764,152 / 37,786,416 | 10.04 | match |
| `/hidden/cache` miss | O(N) | 101 / 1,001 / 10,001 | O(N) | 488,536 / 3,639,192 / 36,208,208 | 9.95 | match |
| `/hidden/cache` hit | O(1) | 0 / 0 / 0 | O(N) (all rows restored) | 38,600 / 104,136 / 755,400 | 7.25 | match (see note) |
| `/hidden/appends` | O(N) | 101 / 1,001 / 10,001 | O(N) | 558,648 / 4,518,704 / 45,289,664 | 10.02 | match |
| `/hidden/model-with` | O(1), +1 per request | 2 / 2 / 2 | O(N) | 336,432 / 2,905,456 / 28,740,232 | 9.89 | match |
| `/hidden/cursor` | O(1) | 1 / 1 / 1 | O(N) (PDO buffers all rows) | 80,232 / 143,976 / 782,952 | 5.44 | match (see note) |
| `/hidden/conditional-binding` (route as bound in `testing`) | O(N), worst case | 101 / 1,001 / 10,001 | O(N) | 484,864 / 3,638,272 / 36,207,288 | 9.95 | match |

Candidates measured for `/hidden/conditional-binding` (the test swaps the binding):

| Implementation | Queries (100 / 1000 / 10000) | Peak bytes (100 / 1000 / 10000) | Growth ratio |
| --- | --- | --- | --- |
| eager (`EagerOrderSummaries`, used in `production`) | 2 / 2 / 2 | 251,016 / 2,086,696 / 20,709,736 | 9.92 |
| N+1 (`LazyOrderSummaries`, used in every other environment) | 101 / 1,001 / 10,001 | 423,760 / 3,638,320 / 36,207,336 | 9.95 |

The worst case among the two candidates is O(N) queries, which is what the table says should be adopted. Under the `testing` environment the provider happens to bind the N+1 one, so the route itself measures O(N) as well; a test also checks that `production` binds the eager one.

## Notes per route

- `/hidden/blade`: `BladeController` passes `Order::get()` to the view; the relation is read in `resources/views/hidden/blade.blade.php`. No query appears in the controller.
- `/hidden/resource`: `OrderResource::toArray` reads `$this->customer`.
- `/hidden/cache`: `Cache::remember('hidden.orders', 300, ...)` wraps an N+1 that builds plain arrays. The miss and the hit are measured one after the other in the same test, with `Cache::flush()` before the miss. The hit issues 0 queries, so the package check on the hit uses `allowNoQueries: true` (a query-free scenario is the intended contract there).
- `/hidden/appends`: `AppendedOrder` (extends `Order`, `$table = 'orders'`) lists `customer_name` in `$appends`; the accessor reads `$this->customer`. The JSON also contains the whole `customer` object as a side effect of the lazy load.
- `/hidden/model-with`: `EagerOrder` (extends `Order`) declares `$with = ['customer']`. Queries are 2 at every size = 1 for the orders + 1 eager-load for the customers. Compared with a plain `Order::get()` (1 query) that is exactly one extra query per request, so reading `query_extra_per_request = 1` as "the `$with` eager-load adds 1" is correct. A test asserts exactly 2 at 10 / 100 / 1000.
- `/hidden/cursor`: `Order::cursor()` with only a counter and a sum kept, so the response is tiny and any growth comes from the driver, not from the response.
- `/hidden/conditional-binding`: `ConditionalBindingServiceProvider` binds `OrderSummaries` to `EagerOrderSummaries` when `app()->environment('production')`, else to `LazyOrderSummaries`. The controller takes the interface as a method argument (a constructor argument would be cached on the route object, so a test could not swap the binding between measurements).

## Notes on the two close memory ratios

- `/hidden/cursor` (ratio 5.44, rule needs >= 5). The growth is real but small. A throwaway probe (not kept) ran the same loop with PDO's `MYSQL_ATTR_USE_BUFFERED_QUERY` on and off. Buffered (the Laravel default): the peak grows by 71.0 bytes per added row between 1,000 and 10,000 rows. Unbuffered: 57,808 / 57,808 / 57,432 bytes, flat. So "PDO buffers every row" is confirmed as the cause, and the expected O(N) is right for the default connection. The coefficient is about 70 bytes per row, against about 3,400 bytes per row for the routes that build models (`/hidden/blade`), so a tool that weights memory by size should not treat this as the same danger. The ratio is below 10 only because the fixed overhead of a request (about 78 KB) is not small next to 0.7 MB.
- `/hidden/cache` hit (ratio 7.25). With the `array` store's default (`serialize => false`) the cached array is returned as it is, so the hit does not restore anything; the only growth is the JSON response body, about 72 bytes per row (755,400 vs 104,136 bytes). To check the expected "all rows are restored on a hit", a test sets `cache.stores.array.serialize = true` (what file, redis and database stores effectively do) and measures `Cache::get()` alone, without the response: 71,800 / 590,936 / 6,109,976 bytes (ratio 10.3, about 613 bytes per row). Both are O(N); the second is the restore itself.

## Mismatches

None.

Things that could make the memory column look different elsewhere (so a reader does not over-trust it): the numbers are one request in one process, peak memory is noisy (the same request measured in two tests differed by up to 2%, e.g. `/hidden/appends` at 100 rows: 548,008 vs 558,648 bytes). The tables use the values from the memory tests (the second measurement at 100 and 1000 rows).

## Possible spec questions (not decided here)

- `/hidden/conditional-binding`: the table says "environment decides" but not which environment gets which implementation. Chosen here: `production` = eager, everything else = N+1. The expected value does not depend on this choice (worst case is adopted either way).
- `/hidden/cache`: the table's "hit = O(1)" is about queries. With the `array` store it is also 0 queries at every size, which is the same thing; a serializing store would give the same query numbers.
