# Fixture measurements: /ok group (6 routes) and /unknown/dynamic

Measured on Laravel 13, PHP 8.3, MySQL 8.4.7 (Docker). Session and cache drivers are `array` (the `phpunit.xml` defaults), so no session or cache queries are mixed in.
Row counts: 100 / 1000 / 10000 in the N table of each route (other tables fixed: customers 20, products 20, 3 items per order). Full output: `ok.log`. Tests: `fixtures/laravel-app/tests/Feature/Ok/`.

How it was measured:

- Queries: the verdict comes from `ralder/phpunit-query-scaling` (`assertQueriesScaleConstantly` / `assertQueryScaling` with `atMostSlope`). The numbers below come from a plain `DB::listen` counter run on the same requests, because the package only prints numbers when an assertion fails.
- Memory: peak bytes above the starting usage (`memory_reset_peak_usage`), for one HTTP request made inside the test. Reference values only (D18). The rule was fixed before measuring: growth ratio = peak(10000) / peak(100); O(1) needs ratio <= 2, O(N) needs ratio >= 10 (linear would be about 100).

## Result

All six routes match `expected.json`. No red tests. `/unknown/dynamic` is not measured; the test only checks that it answers 200.

| Route | Expected query | Measured queries (100 / 1000 / 10000) | Expected memory | Measured peak bytes (100 / 1000 / 10000) | Growth ratio | Verdict |
| --- | --- | --- | --- | --- | --- | --- |
| `/ok/eager` | O(1) | 2 / 2 / 2 | O(N) | 259,184 / 2,158,864 / 21,188,744 | 81.8 | match |
| `/ok/paginate` | O(1) | 2 / 2 / 2 | O(1) | 72,960 / 72,960 / 72,960 | 1.0 | match |
| `/ok/chunk` | O(N), coefficient 1/chunk_size | 2 / 11 / 101 | O(1) | 159,912 / 164,304 / 174,064 | 1.09 | match |
| `/ok/lazy` | O(N), coefficient 1/chunk_size | 2 / 11 / 101 | O(1) | 160,688 / 166,192 / 175,952 | 1.09 | match |
| `/ok/with-count` | O(1) | 1 / 1 / 1 | O(N) | 200,520 / 1,739,656 / 17,340,800 | 86.5 | match |
| `/ok/bulk` (N = customers) | O(1) | 2 / 2 / 2 | O(N) | 261,960 / 2,325,832 / 22,389,064 | 85.5 | match |
| `/unknown/dynamic` | unknown | not measured | unknown | not measured | n/a | not applicable (answers 200) |

Notes per route:

- `/ok/eager`: 2 queries = orders + the eager-loaded customers. The loop reads `$order->customer`, which is the pattern an N+1 detector must not flag.
- `/ok/paginate`: 2 queries = count + one page (15 rows). Memory is the same to the byte at every scale because the page size is fixed.
- `/ok/chunk` and `/ok/lazy`: `CHUNK_SIZE = 100` (constant `OrderController::CHUNK_SIZE`, used by the routes and by the test). Queries = N/100 + 1: the last full chunk costs one more query that returns nothing, so the slope between scales is exactly 1/100 (9 queries per 900 rows, 90 per 9000). The test asserts `atMostSlope(1/100)` and also that the measured slope is within 10% of 1/100, so a route that does not grow would fail.
- `/ok/with-count`: one SELECT with sub-selects for `withCount('items')` and `withSum('items', 'quantity')`.
- `/ok/bulk`: see the decision below. Queries = 1 SELECT of the customer ids + 1 INSERT.

## Decision: what `/ok/bulk` writes

The table says "all customers' records created in bulk with `insert()` / `upsert()`", N = customers, and a new table is not allowed (it would touch the shared `database/`).
Chosen: one `pending` order per customer (`customer_id`, `status = 'pending'`, `total = 0`, timestamps), written with a single `Order::insert($rows)`.

- Why: it creates a new record for every customer, uses only existing tables, and needs a plain `insert()`. `upsert()` was not chosen because `orders` has no natural unique key, so an upsert there would be an insert in disguise. Upserting the customers' own rows (by id or email) is also possible but rewrites existing data, which is a less natural "create".
- The route is `GET` like the other routes (the spec gives paths only). A route that writes on `GET` is unusual; if a method matters for the analysis, that is a spec question (listed under questions in the report).

## Observation (not a mismatch with the table)

`/ok/bulk` is O(1) in queries only while the single INSERT fits MySQL's limit of 65,535 placeholders per prepared statement. This route binds 5 columns per row, so it works up to 13,107 customers and fails at 13,108 (`SQLSTATE[HY000]: General error: 1390 Prepared statement contains too many placeholders`, checked with a throwaway test, not kept). Above that, real code has to split the insert into batches, which makes the query count O(N / batch). The expected value O(1) is correct for the sizes in this fixture; whether the tool should model this limit is a question for the owner, not something this fixture decides.

## Mismatches

None. Nothing was loosened: no skip, no changed expectation, no relaxed threshold.

Things that could make the memory column look different elsewhere (so a reader does not over-trust it): the numbers are one request in one process, peak memory is noisy, and `chunk`/`lazy` grow by about 14 KB over 100x more rows (a little, not linear: 1.09x).
