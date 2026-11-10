# What one request costs, route by route, at 10,000, 30,000 and 100,000 rows

A diagnosis of the lab backtest's miss (not a prediction, not a score; the backtest's own records, `../../ladder/`, `../../score/` and `poc/lab/predictions/`, were not touched and their pass/fail is unchanged). For each of the eight routes and each of three row counts it sets one request's measured weight beside the estimate the backtest predicted from (`../../estimate/` for 100,000 rows, `../../estimate-30k/`, `../../estimate-10k/`; the point of a route at a count is one formula, the safe side comes from the estimate made at that count).

The numbers in the tables below are copied from `<rows>/table.json` (made by `poc/lab/diagnosis/request_probe.py report`); the readings behind them are in `<rows>/http.jsonl` and `<rows>/inproc.jsonl`.

## How it was measured

- **One request at a time, no load.** The probe (`poc/lab/diagnosis/`) sends a GET through the web server and php-fpm, the way the load reaches the app. Nothing else used the lab during a record (no other tool, no `docker exec` besides the probe's own readings).
- **Cold.** Two meanings are kept apart. In the *in-process* part, `measure-one` (the measurement's own process, `poc/runtime/bin/measure-one`) sends a warm-up request, flushes the app's cache, then sends the *cold* request that the estimate is compared with; its statements and rows (the session's `Handler_read_*`, the way D96, D101 and D102 fix) are the "measured statements" and "measured rows" below. Three runs per route, all three equal in every record. In the *HTTP* part, one warm-up request (rep 0, kept in the record and not used) and then three requests; each request through php-fpm boots the app from nothing (the cache is an array store made at boot), which is one to three statements more than the cold in-process request. HTTP status, elapsed time, the database's CPU time and the server's counters come from this part.
- **The database's CPU time** of one request is its container's `cpu.stat` `usage_usec` over a window around the request, less (a) the same window with no request between the two readings (the median of five; reading the file with `docker exec` costs about 20 ms itself) and (b) the idle drift (the database's CPU rate over ten quiet seconds, times the window's extra length). Before a window the server's counters are read first and `cpu.stat` last, after it `cpu.stat` first, so the client's own work falls outside.
- **That nothing else was running** is shown by the web server's request counter between the two readings, less the probe's own two requests: `0` in all 72 cold rows (3 row counts x 8 routes x 3 requests), column `other_requests_seen` of `table.json`. A request that did not come back quickly and in full also gets one `SHOW FULL PROCESSLIST` after the window (as the user the probe already uses; no grant was added).
- **The window closes when php-fpm has finished**, not when the client got its answer: for any answer that was not a quick success the probe waits until the app container's CPU time stops growing.
- **The measurement floor.** A reading no larger than three times the spread of the five empty windows, or than the idle drift taken off it, is written as below the measurement floor and no CPU ratio is made on it. A second rule, added after the 100,000-row reading was seen, also withholds the ratio when a route's three readings scatter by more than their median. **A withheld ratio is not hidden:** the table that follows the per-count tables lists every route and count where either rule held, with the reading, the three readings and the ratio that would have stood.
- **The estimated database CPU time** has no value of its own in the estimate; it is `c_q x statements + c_row x rows` (the calibration's two coefficients as committed in the prediction, `c_q` = 2.817e-04 s and `c_row` = 1.951e-06 s, unchanged), once with the point rows and once with the safe-side rows.
- **Row counts of the N tables** were read at the start of each run (`<rows>/table-counts.json`): `entities`, `images` and `references` each had exactly the scale's count. Preflight (the closed-network check) passed each time the lab was pointed at a count (`<rows>/preflight.txt`, `preflight-exit.txt`).

## Per count

Rows, statements and CPU of one request, measured and estimated. "est ÷ meas" is the estimate divided by the measurement. Where a CPU ratio is withheld it is marked so; the value that would have stood is in the next section.

### 10,000 rows

| route | HTTP | statements measured | statements estimated | rows measured | rows estimated (point / safe side) | rows est ÷ meas (point / safe) | DB CPU s measured (median of 3; readings in `table.json`) | DB CPU s estimated (point / safe side) | CPU est ÷ meas (point / safe) |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `/books/{bookSlug}/chapter/{chapterSlug}/references` | 200 | 8 | 8 | 222 | 223.6667 / 223.6667 | 1.01 / 1.01 | below the measurement floor (0.0044 s); ratio withheld, would be 0.609 / 0.609 | 0.00269 / 0.00269 | withheld (see above) |
| `/books/{bookSlug}/export/zip` | 200 | 12004 | 12003.9 | 53698 | 53732.72 / 173251.4 | 1 / 3.23 | 3.3 | 3.49 / 3.72 | 1.06 / 1.13 |
| `/books/{bookSlug}/page/{pageSlug}/references` | 200 | 8 | 8 | 225 | 224.6667 / 385.6742 | 0.999 / 1.71 | below the measurement floor (0.0027 s); ratio withheld, would be 1.01 / 1.13 | 0.00269 / 0.00301 | withheld (see above) |
| `/books/{slug}` | 200 | 45 | 46.4947 | 78460 | 78468.48 / 561746.6 | 1 / 7.16 | 0.103 | 0.166 / 1.11 | 1.62 / 10.8 |
| `/books/{slug}/references` | 200 | 7 | 7 | 217 | 218.3333 / 218.3333 | 1.01 / 1.01 | below the measurement floor (0.0025 s); ratio withheld, would be 0.971 / 0.971 | 0.0024 / 0.0024 | withheld (see above) |
| `/images/edit/{id}` | 200 | 6 | 6 | 199 | 199 / 199 | 1 / 1 | below the measurement floor (0.00098 s); ratio withheld, would be 2.12 / 2.12 | 0.00208 / 0.00208 | withheld (see above) |
| `/oidc/callback` | 302 | 0 | 0 | 0 | 0 / 0 | n/a / n/a | below the measurement floor (-0.0014 s); ratio withheld, would be -0 / -0 | 0 / 0 | withheld (see above) |
| `/shelves/{slug}` | 200 | 18 | 18 | 237 | 10235.67 / 10235.67 | 43.2 / 43.2 | below the measurement floor (0.008 s); ratio withheld, would be 3.15 / 3.15 | 0.025 / 0.025 | withheld (see above) |

### 30,000 rows

| route | HTTP | statements measured | statements estimated | rows measured | rows estimated (point / safe side) | rows est ÷ meas (point / safe) | DB CPU s measured (median of 3; readings in `table.json`) | DB CPU s estimated (point / safe side) | CPU est ÷ meas (point / safe) |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `/books/{bookSlug}/chapter/{chapterSlug}/references` | 200 | 8 | 8 | 222 | 223.6667 / 223.6667 | 1.01 / 1.01 | below the measurement floor (0.0033 s); ratio withheld, would be 0.803 / 0.803 | 0.00269 / 0.00269 | withheld (see above) |
| `/books/{bookSlug}/export/zip` | 200 | 36004 | 36001.7 | 250696 | 245391.7 / 207848.9 | 0.979 / 0.829 | 9.94 | 10.6 / 10.5 | 1.07 / 1.06 |
| `/books/{bookSlug}/page/{pageSlug}/references` | 200 | 8 | 8 | 229 | 224.6667 / 389.3481 | 0.981 / 1.7 | below the measurement floor (0.0026 s); ratio withheld, would be 1.02 / 1.14 | 0.00269 / 0.00301 | withheld (see above) |
| `/books/{slug}` | 200 | 45 | 47.8713 | 378454 | 392266.6 / 617897.4 | 1.04 / 1.63 | 0.342 | 0.779 / 1.22 | 2.28 / 3.56 |
| `/books/{slug}/references` | 200 | 7 | 7 | 217 | 218.3333 / 218.3333 | 1.01 / 1.01 | below the measurement floor (0.0037 s); ratio withheld, would be 0.648 / 0.648 | 0.0024 / 0.0024 | withheld (see above) |
| `/images/edit/{id}` | 200 | 6 | 6 | 199 | 199 / 199 | 1 / 1 | below the measurement floor (0.00028 s); ratio withheld, would be 7.41 / 7.41 | 0.00208 / 0.00208 | withheld (see above) |
| `/oidc/callback` | 302 | 0 | 0 | 0 | 0 / 0 | n/a / n/a | below the measurement floor (-0.0015 s); ratio withheld, would be -0 / -0 | 0 / 0 | withheld (see above) |
| `/shelves/{slug}` | 200 | 18 | 18 | 236 | 30235.67 / 30235.67 | 128 / 128 | below the measurement floor (0.0088 s); ratio withheld, would be 7.29 / 7.29 | 0.064 / 0.064 | withheld (see above) |

### 100,000 rows

| route | HTTP | statements measured | statements estimated | rows measured | rows estimated (point / safe side) | rows est ÷ meas (point / safe) | DB CPU s measured (median of 3; readings in `table.json`) | DB CPU s estimated (point / safe side) | CPU est ÷ meas (point / safe) |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `/books/{bookSlug}/chapter/{chapterSlug}/references` | 200 | 8 | 8 | 222 | 223.6667 / 223.6667 | 1.01 / 1.01 | below the measurement floor (0.0029 s); ratio withheld, would be 0.92 / 0.92 | 0.00269 / 0.00269 | withheld (see above) |
| `/books/{bookSlug}/export/zip` | 500 | 120004 | 119994 | 835196 | 1489446 / 1491966 | 1.78 / 1.79 | 25.2 | 36.7 / 36.7 | 1.46 / 1.46 |
| `/books/{bookSlug}/page/{pageSlug}/references` | 200 | 8 | 8 | 229 | 224.6667 / 224.6667 | 0.981 / 0.981 | below the measurement floor (0.0028 s); ratio withheld, would be 0.978 / 0.978 | 0.00269 / 0.00269 | withheld (see above) |
| `/books/{slug}` | 200 | 45 | 49.4878 | 1260454 | 2539961 / 2539961 | 2.02 / 2.02 | 1.13 | 4.97 / 4.97 | 4.4 / 4.4 |
| `/books/{slug}/references` | 200 | 7 | 7 | 217 | 218.3333 / 218.3333 | 1.01 / 1.01 | below the measurement floor (0.0035 s); ratio withheld, would be 0.693 / 0.693 | 0.0024 / 0.0024 | withheld (see above) |
| `/images/edit/{id}` | 200 | 6 | 6 | 199 | 199 / 199 | 1 / 1 | below the measurement floor (0.00043 s); ratio withheld, would be 4.81 / 4.81 | 0.00208 / 0.00208 | withheld (see above) |
| `/oidc/callback` | 302 | 0 | 0 | 0 | 0 / 0 | n/a / n/a | below the measurement floor (-0.001 s); ratio withheld, would be -0 / -0 | 0 / 0 | withheld (see above) |
| `/shelves/{slug}` | 200 | 18 | 18 | 236 | 100235.7 / 100235.7 | 425 / 425 | 0.00811 | 0.201 / 0.201 | 24.7 / 24.7 |


The ZIP route at 100,000 rows is a special case: its HTTP request is cut by php's 30-second execution limit (status 500), while the statements and rows above are those of the in-process cold request, which is not subject to the limit. The database CPU time in that row is that of the cut request. The cut and the uncut request, side by side, are in `../zip-limit/`. At 30,000 rows the ZIP route's safe-side rows (207,849) are fewer than its point (245,392); the committed prediction used the pair as it stands, and so does this table.

## Ratios withheld, and why

| rows | route | null-window rule | scatter rule (added after the 100,000-row reading) | reading, median of 3 (s) | the three readings (s) | ratio that would have stood (point / safe side) |
| --- | --- | --- | --- | --- | --- | --- |
| 100,000 | `/books/{bookSlug}/chapter/{chapterSlug}/references` | yes | no | 0.00292 | 0.0033, 0.0026, 0.0029 | 0.92 / 0.92 |
| 100,000 | `/books/{bookSlug}/page/{pageSlug}/references` | yes | yes | 0.00275 | 0.0401, 0.0028, 0.0025 | 0.978 / 0.978 |
| 100,000 | `/books/{slug}/references` | no | yes | 0.00346 | 0.0014, 0.0035, 0.0089 | 0.693 / 0.693 |
| 100,000 | `/images/edit/{id}` | yes | yes | 0.000432 | 0.0004, 0.0004, 0.0396 | 4.81 / 4.81 |
| 100,000 | `/oidc/callback` | yes | yes | -0.00101 | -0.0016, -0.0010, 0.0326 | the estimate is 0 s: no ratio exists |
| 30,000 | `/books/{bookSlug}/chapter/{chapterSlug}/references` | yes | no | 0.00335 | 0.0033, 0.0029, 0.0036 | 0.803 / 0.803 |
| 30,000 | `/books/{bookSlug}/page/{pageSlug}/references` | yes | no | 0.00263 | 0.0029, 0.0026, 0.0023 | 1.02 / 1.14 |
| 30,000 | `/books/{slug}/references` | yes | yes | 0.0037 | 0.0027, 0.0037, 0.0377 | 0.648 / 0.648 |
| 30,000 | `/images/edit/{id}` | yes | no | 0.00028 | 0.0002, 0.0003, 0.0003 | 7.41 / 7.41 |
| 30,000 | `/oidc/callback` | yes | no | -0.00146 | -0.0019, -0.0015, -0.0012 | the estimate is 0 s: no ratio exists |
| 30,000 | `/shelves/{slug}` | yes | yes | 0.00879 | 0.0088, 0.0362, 0.0084 | 7.29 / 7.29 |
| 10,000 | `/books/{bookSlug}/chapter/{chapterSlug}/references` | yes | yes | 0.00442 | 0.0029, 0.0073, 0.0044 | 0.609 / 0.609 |
| 10,000 | `/books/{bookSlug}/page/{pageSlug}/references` | yes | yes | 0.00266 | 0.0132, 0.0015, 0.0027 | 1.01 / 1.13 |
| 10,000 | `/books/{slug}/references` | yes | yes | 0.00247 | 0.0021, 0.0025, 0.0350 | 0.971 / 0.971 |
| 10,000 | `/images/edit/{id}` | yes | yes | 0.000981 | 0.0023, 0.0010, 0.0003 | 2.12 / 2.12 |
| 10,000 | `/oidc/callback` | yes | no | -0.00142 | -0.0010, -0.0017, -0.0014 | the estimate is 0 s: no ratio exists |
| 10,000 | `/shelves/{slug}` | yes | yes | 0.00795 | 0.0080, 0.0237, 0.0065 | 3.15 / 3.15 |


The null-window rule's floor depends on how far the five empty windows scatter in that run, and one of the five was an outlier at 30,000 rows (40,385 usec against about 21,000 for the others) and at 10,000 rows (31,075 usec), which made the floor 0.059 s and 0.032 s there (0.0022 s at 100,000 rows). So `/shelves/{slug}`, which reads about 8 ms at all three counts, is withheld at 30,000 and 10,000 rows and not at 100,000. The rule was not changed to account for it. Only one reading was withheld by the second rule alone: `/books/{slug}/references` at 100,000 rows (would be 0.693).

## What the server's own counters say

The same windows, from the database's side: the server's `Questions` and `Handler_read_*` counters over the window, beside what `measure-one` counted. The difference is small and the same at every row count for a route (the statements and reads php-fpm's path adds, and what the probe's own readings leave after the empty window is taken off), so the counts the estimate is compared with are the counts the database saw. The one exception is the ZIP route at 100,000 rows, where the request was cut and did less.

| rows | route | server `Questions` over the window (median of 3) | statements counted by the cold in-process request | difference | counted by the first in-process request (not cold) | server `Handler_read_*` over the window | rows read, cold in-process request |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 100,000 | `/books/{bookSlug}/chapter/{chapterSlug}/references` | 18 | 8 | +10 | 11 | 234 | 222 |
| 100,000 | `/books/{bookSlug}/export/zip` | 96024 | 120004 | -23980 | 120006 | 799197 | 835196 |
| 100,000 | `/books/{bookSlug}/page/{pageSlug}/references` | 18 | 8 | +10 | 11 | 241 | 229 |
| 100,000 | `/books/{slug}` | 59 | 45 | +14 | 48 | 1260466 | 1260454 |
| 100,000 | `/books/{slug}/references` | 17 | 7 | +10 | 10 | 229 | 217 |
| 100,000 | `/images/edit/{id}` | 14 | 6 | +8 | 7 | 201 | 199 |
| 100,000 | `/oidc/callback` | 7 | 0 | +7 | 1 | 1 | 0 |
| 100,000 | `/shelves/{slug}` | 28 | 18 | +10 | 21 | 248 | 236 |
| 30,000 | `/books/{bookSlug}/chapter/{chapterSlug}/references` | 18 | 8 | +10 | 11 | 234 | 222 |
| 30,000 | `/books/{bookSlug}/export/zip` | 36021 | 36004 | +17 | 36006 | 250707 | 250696 |
| 30,000 | `/books/{bookSlug}/page/{pageSlug}/references` | 18 | 8 | +10 | 11 | 241 | 229 |
| 30,000 | `/books/{slug}` | 56 | 45 | +11 | 48 | 378466 | 378454 |
| 30,000 | `/books/{slug}/references` | 17 | 7 | +10 | 10 | 229 | 217 |
| 30,000 | `/images/edit/{id}` | 14 | 6 | +8 | 7 | 201 | 199 |
| 30,000 | `/oidc/callback` | 7 | 0 | +7 | 1 | 1 | 0 |
| 30,000 | `/shelves/{slug}` | 28 | 18 | +10 | 21 | 248 | 236 |
| 10,000 | `/books/{bookSlug}/chapter/{chapterSlug}/references` | 18 | 8 | +10 | 11 | 234 | 222 |
| 10,000 | `/books/{bookSlug}/export/zip` | 12016 | 12004 | +12 | 12006 | 53709 | 53698 |
| 10,000 | `/books/{bookSlug}/page/{pageSlug}/references` | 18 | 8 | +10 | 11 | 237 | 225 |
| 10,000 | `/books/{slug}` | 55 | 45 | +10 | 48 | 78472 | 78460 |
| 10,000 | `/books/{slug}/references` | 17 | 7 | +10 | 10 | 229 | 217 |
| 10,000 | `/images/edit/{id}` | 14 | 6 | +8 | 7 | 201 | 199 |
| 10,000 | `/oidc/callback` | 7 | 0 | +7 | 1 | 1 | 0 |
| 10,000 | `/shelves/{slug}` | 28 | 18 | +10 | 21 | 249 | 237 |

## What the tables show

Only what the numbers say; the allocation of the backtest's miss to causes is not made here.

- **Five of the eight routes cost almost nothing, and the estimate counts them right.** `/books/{bookSlug}/chapter/{chapterSlug}/references`, `/books/{bookSlug}/page/{pageSlug}/references`, `/books/{slug}/references`, `/images/edit/{id}` and `/oidc/callback` use 0 to 8 statements and read 0 to 229 rows at every count. The estimate's statements equal the measured ones and its rows are within 2% of them (the safe side of `page/{pageSlug}/references` is 1.7 times the measured rows at 10,000 and 30,000 rows, at about 0.003 s of estimated CPU). Their database CPU time (0.0003 to 0.0044 s) is below the measurement floor, so no CPU ratio is made on them.
- **The ZIP route's point estimate is close at 10,000 and 30,000 rows and high at 100,000.** Estimated rows over measured: 1.00 (10,000), 0.98 (30,000), 1.78 (100,000); converted CPU over measured CPU: 1.06, 1.07, and 1.46 against the cut request or 1.10 against the uncut one (`../zip-limit/`). Statements are estimated almost exactly at all three counts. Its measured CPU is 3.3 s, 9.9 s and 25.2 s (cut; 33.4 s uncut).
- **`/books/{slug}`: the point rows match at 10,000 and 30,000 rows and are 2.02 times the measured at 100,000.** Estimated rows over measured: 1.00, 1.04, 2.02 (point); the safe side is 7.16, 1.63 and 2.02 times. Converted CPU over measured CPU: 1.62, 2.28, 4.4 (point). Measured CPU is 0.10 s, 0.34 s and 1.13 s. At 100,000 rows php-fpm used about 8.7 s of CPU for this request against 1.13 s of database CPU (`php_cpu_s` in `table.json`).
- **`/shelves/{slug}`: the estimate grows with the row count, the measurement does not.** The estimated rows are about N + 236 at every count (10,236, 30,236, 100,236); the measured rows are 236, 236, 237. The target shelf of the lab data holds one book at every count (`bookshelves_books` has 5 rows in all three databases and shelf 1 has one of them), and the measured database CPU stays about 8 ms. Estimated rows over measured: 43, 128, 425; CPU over measured: 3.15 and 7.29 (both withheld, see above) and 24.7 at 100,000 rows. The estimate marks this route's statement as `plan_unstable` and takes its heaviest plan state (`../../estimate-30k/README.md`); that this is what the numbers come from is not tested here.
- **The ZIP route is almost all of the measured cost at 100,000 rows.** Its measured CPU (25.2 s, the cut request) is 96% of the sum of the eight routes' measured CPU (26.3 s); in the estimate its 36.7 s is 88% of the sum (41.9 s). `/books/{slug}` is the next heaviest (1.13 s measured, 4.97 s estimated).

## Files

| File | |
| --- | --- |
| `100000/`, `30000/`, `10000/` | one directory per count: `context.json` (what was run), `http.jsonl` (the HTTP part), `inproc.jsonl` (the in-process part), `table-counts.json`, `preflight.txt`, `preflight-exit.txt`, `run.log` (and, at 100,000 rows, `run-inproc.log`: the in-process part of the first run stopped on a byte that is not UTF-8 in the ZIP route's output, was fixed, and was run again for the routes after the first; the first route's three runs are from the first run), `table.json`, `table.md` |
| `100000/context-http.json` | the context of the first run (`context.json` there is that of the second) |
