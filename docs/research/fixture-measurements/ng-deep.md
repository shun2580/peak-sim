# Fixture measurements: /ng deep routes (ng-deep)

Four routes of the `/ng` group, measured against `fixtures/expected.json`. Log of the run: `ng-deep.log` (same directory).

- Environment: PHP 8.3.36, Laravel 13.39.0, MySQL 8.4.7 (Docker), `ralder/phpunit-query-scaling` v0.2.0, PHPUnit 12.5.
- Drivers: `SESSION_DRIVER=array`, `CACHE_STORE=array` (the `phpunit.xml` defaults), so session and cache add no queries.
- Query counts: `assertQueryScaling` at 2, 5, 10, 20 rows. Each scale runs inside a transaction on clean data; the route is called through the HTTP test client, so the count covers the whole request.
- Memory: `MeasuresMemory` around the HTTP call at 100, 1000, 10000 rows (one off-meter warm-up call first). Reference values only; the peak includes the response body and the test client.
- Result: **all four routes match the expected values. No mismatch.**

## Summary

| Route | Expected (query / memory) | Measured queries | Measured memory | Verdict |
| --- | --- | --- | --- | --- |
| `/ng/save-in-loop` | O(N) / O(N), N = customers | linear, +1 per customer (1 SELECT + N UPDATE) | grows ~10x per 10x rows | match |
| `/ng/first-or-create-in-loop` | O(N), 2 per row / O(N), N = products | linear, exactly +2 per product (1 SELECT + N x (SELECT + INSERT)) | grows ~10x per 10x rows | match |
| `/ng/first-or-create-in-loop?via=update` | same row as above (the spec lists both functions) | linear, exactly +2 per product (1 SELECT + N x (SELECT + UPDATE)) | grows ~10x per 10x rows | match |
| `/ng/service` | O(N) / O(N), N = orders | linear, +1 per order (1 SELECT + N customer lookups) | grows ~10x per 10x rows | match |
| `/ng/layered` | O(N) / O(N), N = orders | linear, +1 per order (1 SELECT + N customer lookups) | grows ~10x per 10x rows | match |

## Query counts

| Route | N=2 | N=5 | N=10 | N=20 | Slope |
| --- | --- | --- | --- | --- | --- |
| `/ng/save-in-loop` | 3 | 6 | 11 | 21 | 1 |
| `/ng/first-or-create-in-loop` (empty `product_stats`) | 5 | 11 | 21 | 41 | 2 |
| `/ng/first-or-create-in-loop?via=update` (filled `product_stats`) | 5 | 11 | 21 | 41 | 2 |
| `/ng/service` | 3 | 6 | 11 | 21 | 1 |
| `/ng/layered` | 3 | 6 | 11 | 21 | 1 |

The constant 1 is the `SELECT` that loads all rows (`Customer::all()`, `Product::all()`, `Order::all()`).

## Peak memory (bytes above the start of the call)

| Route | N=100 | N=1000 | N=10000 | Bytes per row at 10000 | 1000 -> 10000 |
| --- | --- | --- | --- | --- | --- |
| `/ng/save-in-loop` | 313,128 | 2,044,792 | 20,146,560 | ~2,015 | x9.9 |
| `/ng/first-or-create-in-loop` | 293,880 | 1,395,624 | 14,122,704 | ~1,412 | x10.1 |
| `/ng/first-or-create-in-loop?via=update` | 257,752 | 1,214,000 | 12,001,520 | ~1,200 | x9.9 |
| `/ng/service` | 485,392 | 3,626,016 | 35,797,536 | ~3,580 | x9.9 |
| `/ng/layered` | 484,600 | 3,618,024 | 35,718,936 | ~3,572 | x9.9 |

The tests assert growth in a plain way: the peak rises at every step, and the last 10x step (1000 -> 10000) at least triples it (an O(1) route would stay near 1x). Both are my own operational definition of "O(N) versus O(1)", not a value from the spec. The runs are deterministic: two runs gave identical byte counts.

## Per route

### /ng/save-in-loop

- Implementation: `Customer::all()`, then per customer `name` is changed and `save()` is called. The name must change; an unchanged `save()` issues no query (S-31(b)).
- Expected vs measured: O(N) / O(N). Match. Exactly one UPDATE per customer.
- Memory is O(N) because `Customer::all()` holds every model during the loop, not because of the saves.

### /ng/first-or-create-in-loop

- Default: `firstOrCreate` for every product on an empty `product_stats` (SELECT + INSERT = 2 per row). `?via=update`: `updateOrCreate` on a `product_stats` filled beforehand (`units_sold = 0`), with the route setting `units_sold = 1` (SELECT + UPDATE = 2 per row). The two are never run together in one request.
- Expected vs measured: O(N) with 2 per row / O(N). Match in both modes, and the tests assert exactly 2 per row.
- Notes for the expected value, from the base worker's checks and kept here as reading aids (they were not re-measured by this worker):
  - Run on the same product one after the other, the two calls cost 4 per row.
  - `firstOrCreate` on an existing row costs 1 per row, and `updateOrCreate` with an unchanged value costs 1 (no UPDATE for a clean model). "2 per row" therefore holds only for the setups above. If the spec's "2 queries per row" is meant to hold for any data state, the table is too strong; this is a possible point for the maintainer to confirm, not a mismatch.
- Memory O(N) comes from `Product::all()`; the created `ProductStat` models are not kept.

### /ng/service

- Controller -> `OrderReportService` -> `OrderRowBuilder` -> `CustomerNameResolver`. The N+1 (`$order->customer`) is in the last class, three service classes below the controller.
- Expected vs measured: O(N) / O(N). Match. 1 query per order.

### /ng/layered

- Controller -> `ListOrderSummaries` (use case) -> `OrderSummaryService` (domain service) -> `OrderRepository` (interface) -> `EloquentOrderRepository`. The N+1 is in the Eloquent implementation. The interface is bound in `App\Ng\Providers\LayeredServiceProvider` with `$this->app->bind(OrderRepository::class, EloquentOrderRepository::class)`; a test checks that the container resolves it.
- Expected vs measured: O(N) / O(N). Match. 1 query per order.
- `/ng/service` and `/ng/layered` give almost the same numbers, as they should: the same N+1 behind a different structure. That is the point of the pair for the static analyzer.

## Mismatches

None. Nothing was skipped, loosened or rewritten: 16 tests, 132 assertions, 0 failed, 0 skipped.

## Points to keep in mind (not mismatches)

- "O(N)" is checked as: the count rises on every segment, at the same rate (linear, not quadratic), and no faster than the stated per-row figure (1, or 2 for first-or-create). Four scales (2, 5, 10, 20) show linearity at those points only, as the package itself says.
- The memory figures include the response body, so a route that returns more per row looks heavier (`/ng/service` returns an order total and a name; `/ng/save-in-loop` returns only a count).
- Customers have no timestamps, so `save-in-loop` writes one column per row; with timestamps it would still be one UPDATE per row.
