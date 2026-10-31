# Fixture measurements: /ng group, simple routes (6 routes)

Measured on Laravel 13, PHP 8.3, MySQL 8.4.7 (Docker). Session and cache drivers are `array` (the `phpunit.xml` defaults), so no session or cache queries are mixed in.
Routes: `/ng/n-plus-one`, `/ng/nested`, `/ng/count-in-loop`, `/ng/unbounded`, `/ng/get-then-count`, `/ng/collection-filter`. The other four `/ng` routes are in `ng-deep.md`.
Full output: `ng-basic.log`. Tests: `fixtures/laravel-app/tests/Feature/Ng/Basic*`. Routes: `routes/ng_basic.php`; controllers: `app/Http/Controllers/Ng/Basic*`.

Data set: customers 10, products 20, 3 items per order (M = 3) unless stated; N = number of orders.

How it was measured:

- Queries: both the verdict and the numbers come from `ralder/phpunit-query-scaling` (`assertQueryScaling`) at N = 5 / 10 / 20. The contract is picked from the `query` value in `fixtures/expected.json`: `O(1)` uses `QueryGrowth::constant()`; `O(N)` and `O(N*M)` use a small custom expectation (`BasicGrowsLinearly`) that needs the count to grow on every segment by the same amount per added item (slope spread within 10%, fixed up front). The library's `atMostSlope` is an upper bound only and would also pass a route that never grows, so it was not used alone. The counts are printed by a recording wrapper (`BasicRecordingExpectation`), because the package only prints numbers when an assertion fails.
- Memory: peak bytes above the starting usage (`MeasuresMemory`, `memory_reset_peak_usage`) for one HTTP request made inside the test, after one warm-up request. The peak includes what the test client holds (the response body), so it is a reference value (D18). The rule was fixed before measuring: log-log slope of the last two scales (1000 -> 10000) must be >= 0.5 for O(N) and <= 0.2 for O(1); anything in between counts as inconclusive and fails. No route in this group expects O(1) memory.
- `/ng/nested` (O(N*M)) is also swept along M with N fixed (`SEED_ITEMS_PER_ORDER` equivalent: the seeder's `itemsPerOrder`).

## Result

All six routes match `expected.json`. No red tests, no skips.

| Route | Expected query | Measured queries (N = 5 / 10 / 20) | Expected memory | Measured peak bytes (N = 100 / 1000 / 10000) | Ratio 10000/100 | Verdict |
| --- | --- | --- | --- | --- | --- | --- |
| `/ng/n-plus-one` | O(N) | 6 / 11 / 21 | O(N) | 424,144 / 3,638,704 / 36,039,224 | 85.0 | match |
| `/ng/nested` (M = 3) | O(N*M) | 21 / 41 / 81 | O(N*M) | 1,212,240 / 11,523,456 / 115,105,464 | 95.0 | match |
| `/ng/count-in-loop` | O(N) | 6 / 11 / 21 | O(N) | 234,840 / 1,714,200 / 16,707,480 | 71.1 | match |
| `/ng/unbounded` | O(1) | 1 / 1 / 1 | O(N) | 217,800 / 1,929,608 / 19,180,808 | 88.1 | match |
| `/ng/get-then-count` | O(1) | 1 / 1 / 1 | O(N) | 158,840 / 1,317,880 / 13,041,400 | 82.1 | match |
| `/ng/collection-filter` | O(1) | 1 / 1 / 1 | O(N) | 158,840 / 1,317,880 / 13,041,400 | 82.1 | match |

Last-segment log-log slope of the memory (1000 -> 10000): 0.996, 1.000, 0.989, 0.997, 0.995, 0.995, in the order of the table. A linear route would give 1.0.

### `/ng/nested`: the M axis (O(N*M))

| Sweep | Scales (M = items per order) | Queries | Peak bytes |
| --- | --- | --- | --- |
| N = 10 orders | 1 / 2 / 4 | 21 / 31 / 51 | not measured |
| N = 20 orders | 1 / 2 / 4 | 41 / 61 / 101 | not measured |
| N = 500 orders | 1 / 4 / 16 | not measured | 2,830,008 / 7,282,224 / 25,312,224 |

- Queries: 1 (orders) + N (one `items` lookup per order) + N*M (one `product` lookup per item) = 1 + N + N*M. Check: N = 5, M = 3 gives 21; N = 10, M = 2 gives 31. The extra queries per added item (M) are 10 at N = 10 and 20 at N = 20, a ratio of 2.0, so the count depends on the product N*M and not on N + M (the test asserts the ratio is 2.0 within 0.1).
- Memory: with the orders fixed at 500, the peak grows with M (log-log slope 0.90 between M = 4 and M = 16; the fixed part is the 500 orders themselves). The marginal cost is about 3.0 KB per item (every item holds its own `Product` model, since Eloquent does not share the model across items). The peak at N = 10000, M = 3 is about 11.5 KB per order.

## Notes per route

- `/ng/n-plus-one`: `Order::get()`, then `$order->customer->name` in the loop. 1 + N queries; every order issues its own customer lookup even when two orders share a customer (there is no identity map).
- `/ng/nested`: see above. Both loops lazy-load, so the cost per order is 1 + M queries.
- `/ng/count-in-loop`: `$order->items()->count()` runs a `select count(*)` per order. The item rows are never loaded, yet the peak is still O(N) because the orders themselves are loaded.
- `/ng/unbounded`: `Order::all()` returned as JSON. One query, no `LIMIT`.
- `/ng/get-then-count`: `Order::get()->count()` and only the count is returned. The response is O(1), but the peak is O(N) because every row is hydrated before it is counted. That is the pattern's point.
- `/ng/collection-filter`: `Order::get()->where('status', 'paid')`. The filter runs in PHP after all rows are loaded, so the peak is identical to the previous route at every scale (same bytes). Its response is a third of the rows, which does not change the peak because the peak is reached while loading.

## Mismatches with `expected.json`

None. The expected values were not adjusted, and no thresholds were loosened after measuring.

Points that could still be questioned (not mismatches, and no change is proposed without the owner's decision):

- `/ng/count-in-loop`, `/ng/unbounded`, `/ng/get-then-count`, `/ng/collection-filter` have memory O(N) in the table because of the loaded orders. The measurements agree. If the table meant the response size instead, `/ng/get-then-count` would be O(1); the table's own reason ("every row is loaded") says it does not.
- Memory is measured for the whole request in the test client, including the response body. For the routes that return every order (`/ng/unbounded`, `/ng/n-plus-one`) the body adds to the peak. The classification (a slope near 1.0 against a threshold of 0.5) leaves a wide margin, so this does not affect the verdict.
- The seeder is deterministic and the measured peaks were identical across two full runs (same bytes), so the numbers above are reproducible in this environment. They will differ on another PHP build or MySQL driver version.

## Reproduce

```bash
cd docker
docker compose -p peak-sim-ng-basic up -d --build
docker compose -p peak-sim-ng-basic exec php-dev sh -c 'cd fixtures/laravel-app && vendor/bin/phpunit --colors=never --testdox --filter "Tests.Feature.Ng.Basic"'
docker compose -p peak-sim-ng-basic down -v
```

(`vendor/` and `.env` already exist in `fixtures/laravel-app` after the base setup; the filter keeps the run to this group's 14 tests. About 95 seconds, most of it the 10000-order memory runs.)
