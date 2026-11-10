# Lab backtest (B): where did the limit rps miss at 100,000 rows, and why?

Status: DONE for the separation of causes; the proposals of section 5 are not carried out and wait for the project owner's judgment. Sections 1 and 2 (what test B found, and how the cause is looked for) were written before the results of the four parts of the investigation and are not changed by them. Section 3 gives the results, section 4 the reading of the cause (what can be stated and what cannot), and section 5 the proposals. Every figure in this file is copied from the output of a tool or from a record; none is worked out by hand.

This file follows `docs/research/lab-backtest.md`, the record of test B of `docs/simulator-spec.md` (version 0.2, sections 7.2 to 7.4), which did not pass. It asks one question: **why did the predicted limit rps come out lower than the measured one at 100,000 rows?** It is a separation of causes, not a second score. Nothing in the records of test B is changed, nothing is repaired, and no estimate, calibration, formula or prediction tool is touched. Where a repair is thought of, it is written in section 5 as a proposal and not carried out.

As in the file it follows, what is checked is a **check against a busy period reproduced in the lab**. Nothing here was observed on a real deployment, and no value measured in production appears. The limits of the lab (cache behaviour, data that does not fit in memory, contention with other work, write load) are those of section 6 of `docs/research/lab-backtest.md` and hold here too.

## 1. What test B found

The prediction (commit `5bdf6e88254439dd6127b39a503cf046384bac91`, made from the estimate at `7c40774` and a four-run calibration) was committed before any step of a prediction scenario was measured. The score of the five prediction scenarios is in `docs/research/lab-data/score/` and is read here as it stands.

**Verdict.** Test B does not pass: the lowest standard is not met (item 4, the limit rps), and the goal is not met (items 2 and 4). Items 1 and 3 are met at both levels; item 2 is met at the lowest standard (12.25 points against the line of 15) and not at the goal (5 points).

| Item | Value (point prediction) |
| --- | --- |
| 1. mean abs. difference smaller than the naive estimate | 12.25 pt vs naive 23.10 pt |
| 2. mean abs. difference (lines 15 / 5 points) | 12.25 pt |
| 3. "exceeded the threshold" agrees, all valid steps | 44/47 = 93.6% |
| 4. limit rps within ±20% / ±10% of the measured one | 2/5 and 1/5 |

**The limit rps.** The five scenarios split by row count. The two smaller ones are within the lines or close to them; the three at 100,000 rows are not.

| Scenario | Rows | Mix | Measured limit rps | Point | Point error |
| --- | --- | --- | --- | --- | --- |
| t-4 | 10,000 | even | 3.7646 | 3.4713 | −7.8% |
| t-1 | 30,000 | even | 1.3128 | 1.1157 | −15.0% |
| t-2 | 100,000 | even | 0.4966 | 0.3056 | −38.5% |
| t-3 | 100,000 | first four routes 80% | 0.2955 | 0.1917 | −35.1% |
| t-5 | 100,000 | last four routes 80% | 1.2100 | 0.7529 | −37.8% |

At 100,000 rows the point prediction is above the measurement on every step of the three scenarios, whatever the mix. For example, at 0.3 rps in `t-2` the point is 78.5% and the measurement is 51.2%; at 0.1 rps in `t-5` the point is 10.6% and the measurement is 7.6%. At 10,000 rows the point is within 2.29 points of the measurement from 0.45 rps up to 3.3 rps, and at 30,000 rows it is within 4.72 points up to 1.0 rps. The naive estimate is the other way: it is below the measurement on every step at 100,000 rows.

**Two things in the records that bear on the question** (stated in `docs/research/lab-backtest.md` section 7.3 and not examined there):

- At 100,000 rows some requests failed with HTTP 500, and at the high steps some requests got no response (status 0). The application's log shows PHP stopping a request at its 30-second execution limit inside the database layer. The route that fails is `GET /books/{bookSlug}/export/zip`: a single request to it at 100,000 rows gave no response within 45 seconds, while the other three of the first four routes answered. The database utilization of a step includes the database time of these requests. The score does not read the HTTP statuses.
- The calibration is not tight on the weight of the rows. In the four fits that leave one run out, `c_q` lies between 1.749e-4 and 2.871e-4 (a factor of 1.64) and `c_row` between 1.379e-6 and 1.129e-5 (a factor of 8.2). The calibrated runs were made at 10,000 rows and at 3,000 rows (`cal-4`); the prediction at 100,000 rows is an extrapolation of the row term of the load formula, `L = Σ_r λ_r · ( c_q · count_r(N) + c_row · rows_r(N) )`, to ten times the larger calibrated row count.

Neither is claimed here as a cause. They are the places where the investigation looks first, because both act only at 100,000 rows.

## 2. How the cause is looked for

The load of one route in the prediction is its calibrated cost per request, `c_q · count_r + c_row · rows_r`, from the estimate's point values. A miss in the limit rps can therefore come from three places: the estimate of `count_r` and `rows_r` for a route (the estimate at `7c40774`), the work the database really did for a request that the estimate counted in full (the route that PHP stops), or the two coefficients. The four parts below measure each of these separately. They share three rules.

- **No load on the database while one request is measured.** The database CPU time of a single request is read as the change of `usage_usec` in the cgroup `cpu.stat` of the database container before and after the request, and only after it has been checked that no other request reaches the database. Nothing else uses the lab's containers while this is done.
- **Every record names the row count of the database it was made on** (10,000, 30,000 or 100,000 rows in each of the eight N tables, grown together as in test B), so that a figure is never read against another row count.
- **Nothing in the records of test B is rewritten, re-scored or re-predicted.** The prediction file, the estimate, the calibration, the ladders and the score are read, never written. The measurements of the four parts are put in their own directory, `docs/research/lab-data/diagnosis/`.

### 2.1 Part 1: the weight of one request, route by route, at three row counts

**Question.** Does the estimate's cost of one request, route by route, agree with what one request costs at 10,000, 30,000 and 100,000 rows, and at which routes and row counts does it not?

**What is measured.** For each of the 8 routes of the route list (`poc/lab/routes/route-list.json`), at each of the three row counts, three cold requests without any other load, one at a time. For each request:

- the number of rows read, counted by the handlers (the count that decisions D96, D101 and D102 take as the right one) and the number of queries;
- the time of the response and the HTTP status;
- the database CPU time, from `usage_usec` as above.

**What it is set against.** The estimate at `7c40774`, at its point and on the safe side (the routes marked `plan_unstable` or `plan_changes_at_scale` at the upper side of their width, from the estimate of the row count concerned, as in the prediction). For each route and row count, "estimate ÷ measured" is given in three quantities: rows read, number of queries, and database CPU time. The database CPU time of the estimate is the calibrated cost of the route, `c_q · count_r + c_row · rows_r`, with the coefficients of the four-run fit of the prediction.

**What it can show.** If the estimate over-states one quantity of one route at 100,000 rows and not at 10,000, that is a miss in the estimate of that route. If rows and queries agree and only the database CPU time differs, the miss is in what a row or a query costs, which is the calibration and not the estimate. Three requests per cell show how much a single request varies; they are not a distribution.

### 2.2 Part 2: what the 30-second limit does to the ZIP route

**Question.** At 100,000 rows the ZIP route is stopped by PHP's execution limit and answers HTTP 500. How much less database work does a stopped request do than the same request allowed to finish?

**What is measured.** `GET /books/{bookSlug}/export/zip` at 100,000 rows, cold, three times as it is (the cut-off applies; these are the cold requests of part 1), and three times with the limit lifted, with the same items as part 1.

**How the limit is lifted.** For the diagnosis only, by changing, for a short time, the setting of the php-fpm pool that receives the request. The record gives the exact change and the exact restoration. After the three requests the setting is put back, and the restoration is shown from the php-fpm side, not by the command-line `php -i` (whose limit is a different setting and says nothing about the pool): the pool configuration as php-fpm reports it, and the value of `max_execution_time` seen by one request that goes through php-fpm, at three times (before the change, with the limit lifted, after the restoration). The other settings that can end a request early (for example the pool's termination timeout and the web server's read timeout of the upstream) are looked at at the same three times. The change is not committed.

**What it can show.** The difference in database CPU time and in rows read between the stopped and the finished request is the part of the estimate's work for this route that the database never did in the measurement. It says nothing about routes that finish.

### 2.3 Part 3: giving the gap to its sources

**Question.** For `t-2`, `t-3` and `t-5`, how much of the difference between the predicted and the measured utilization belongs to (a) the estimate of one request of each route, (b) the stopped ZIP requests, and (c) the rest, which holds the calibrated coefficients and, in particular, the width of `c_row`?

**Fixed before the per-request measurements.** The method below was fixed before any per-request measurement of parts 1 and 2 was taken, and is not changed after the results are seen: neither the formulas, nor the order, nor which of two readings is the primary one. Where a second reading is shown beside the primary one, both are reported whatever they say.

**Definitions.** For a scenario and a usable step `j` (a valid step whose measured utilization is between 10% and 90%), let `λ_rj` be the decided rps of route `r` in that step, taken from the committed prediction, and `M_j` the measured utilization of the step.

- `k_r^est = c_q · count_r^est(N) + c_row · rows_r^est(N)`: the estimate's cost of one request, in CPU seconds of the database, from the input of the prediction committed in `5bdf6e8` and the coefficients of its four-run fit. The utilization it gives, `U0_j = Σ_r λ_rj · k_r^est / 2`, is the committed point prediction.
- `k_r^full = c_q · count_r^meas(N) + c_row · rows_r^meas(N)`: the same coefficients applied to the query count and the rows read measured in part 1 (the median of the three cold requests), except for the ZIP route at 100,000 rows, where the three requests of part 2 without the limit are used.
- `k_zip^trunc`: the same formula for the ZIP route at 100,000 rows as measured in part 1, where PHP stops it.
- `f_j`: the share of the ZIP requests of step `j` that did not return 200 (500 and no response), from the status counts of the step and the per-route breakdown of part 4. Where part 4 gives no breakdown, the number of 500 over the number of ZIP requests is used, capped at 1, and the file says so. `k_zip,j^run = f_j · k_zip^trunc + (1 − f_j) · k_zip^full`.
- `U1_j = Σ_r λ_rj · k_r^full / 2`: the prediction with the estimate replaced by the measured counts. `U2_j` is `U1_j` with the ZIP route's cost replaced by `k_zip,j^run`.

**The division.** The difference `Δ_j = U0_j − M_j` is divided as

```
(a)_j = U0_j − U1_j     the estimate of one request of each route
(b)_j = U1_j − U2_j     the database work that the stopped ZIP requests did not do
(c)_j = U2_j − M_j      the rest
```

Each scenario's value is the sum over its usable steps, in utilization points; the share of a part is its sum over the sum of `Δ`, with its sign (a negative share, or one above 100%, is printed as it is). Where the sum of `Δ` is small, as it can be for `t-4` and `t-1`, a share is unstable, so the points are the main figure and the share is added only beside them. The table by step is given too. At 10,000 and 30,000 rows, where the ZIP route returns 200, `f_j` is 0 and (b) is 0; if part 1 shows the ZIP route stopped at 30,000 rows, the same formulas give (b) there.

**The order.** The primary order is (a) then (b): (a) is the miss of the estimate against the work one request does, and the stop is an event that happens at run time on top of that work. The other order, (b) then (a), is shown beside it: `U1'_j` is `U0_j` with the ZIP route's cost multiplied by `k_zip,j^run / k_zip^full`, `(b')_j = U0_j − U1'_j` and `(a')_j = U1'_j − U2_j`. The sum (a)+(b) and the part (c) do not depend on the order; only the split between (a) and (b) does, and the file says so wherever it quotes them.

**What goes into (a) and what into (c).** Part (a) uses the measured query counts and rows read, each in its own term, with the coefficients as committed. The measured database CPU time of a request is not used to replace `k_r` in (a), because it already holds the error of the coefficients (what one row or one query costs at that row count), and (a) and (c) would overlap. It is used inside (c), which is divided further and the three pieces are put under (c) without changing the three parts above:

```
U3_j  = U2_j with k_r^full and k_zip^trunc replaced by the database CPU times of parts 1 and 2
(c1)_j = U2_j − U3_j    the error of the cost of a row or a query (the effect of the width of c_row shows here)
(c2)_j = U3_j − M_j     what a cold single request does not carry into a loaded run (cache warming,
                         ordering, contention) and differences of measuring; it can be negative
(c3)                    the part due to the difference between the decided rps and the rps that came out
                         (U2 rebuilt with the rps that came out); small, since every step is within 5%
```

**The reading of (a) against the calibration.** The main reading of (a) uses the ratio of the estimate to the measurement as it is at each row count. A second reading is shown beside it, in which, for each of the query-count term and the row term, the ratio at the row count concerned is divided by the ratio at 10,000 rows. The reason is that the calibration fitted the coefficients to the estimate at 10,000 rows (and 3,000), so a bias of the estimate at 10,000 rows has been absorbed by the coefficients; the absolute ratio can count that absorbed part into (a). For a route whose ratio cannot be formed (a zero in the denominator, as for a route with no statement in the estimate), the replacement formula needs no ratio, since the measured value is put in, and the second reading uses the absolute ratio for that route. If the share of (a) differs by more than 10 points between the two readings, the file says that the border between (a) and (c) cannot be fixed, and gives (a) as the range of the two values. It does not choose one of the two after seeing the results.

**The test of (c): the sensitivity to `c_row`.** The primary reading takes the fits that leave one run out as the calibration width defines it (spec section 6.3): `c_q` and `c_row` are moved together to the pair of the fit with the smallest `c_row` (1.37851e-6, with `c_q` = 2.87058e-4) and to the pair of the fit with the largest (1.12854e-5, with `c_q` = 1.74879e-4). Each of these pairs fits three of the four calibration runs, so the pair is one that the calibration itself allows. Beside it, a second reading moves only `c_row` to the same two ends with `c_q` kept at its value of the four-run fit; this answers the question in its literal form, but the pair it makes does not fit the calibration runs. For each of `t-2`, `t-3` and `t-5` the file gives the predicted limit rps at the point and at both ends, as a change from the committed point, read from the committed prediction where it already holds the pair.

**The same division for `t-4` and `t-1`.** It is made for the two scenarios that came out close (10,000 and 30,000 rows), so that it is visible why they came out close: whether the three parts are small, or whether larger parts cancel.

**What it is and is not.** This is a separation of causes, not a scoring. The verdict of test B and `score.json` are not changed by it, and the prediction is not remade in the light of it.

### 2.4 Part 4: the steps without any response

**Question.** At the high steps (for example `t-2` at the steps near 80%), HTTP status 0 (no response) gathered. What is the load tool's time-out, which routes' requests got no response, and does this bear on how the database utilization was measured?

**How.** From the records only; no new load is applied. The time-out of the load tool (k6) is read from the load definition of the lab (`poc/lab/load/`). The routes of the requests with status 0 are taken from the per-step records of `docs/research/lab-data/ladder/` and the HTTP-status table in `docs/research/lab-data/score/http-status.md`. Whether the status counts show which route they belong to depends on what the records keep; where a record does not name the route, the file says so and gives the count per step only.

**The effect on the measurement.** The utilization of a step is the change of the database container's CPU time over the kept window divided by the window's length times 2 CPUs (`docs/research/lab-backtest.md`, section 1). It does not read an HTTP status. What has to be argued from the records and from the procedure is therefore a different thing: whether work done for a request after the load tool has stopped waiting for it (a request in the database when its client has gone) can fall inside the window of the same step, fall into a later step, or fall outside every kept window; and which of these the records can tell apart. The file states what the records support and what they do not.

## 3. Results

The records are in `docs/research/lab-data/diagnosis/` (`per-request/`, `zip-limit/`, `allocation/`, `no-response/`; the guide is its `README.md`). The tables below are copied from them. None of it is a prediction or a score: the records of test B and its verdict are as they were committed.

### 3.1 Part 1: the weight of one request

One request at a time, no load. The web server's request counter between the two readings of the database's CPU time showed no other request in all 72 cold readings (3 row counts × 8 routes × 3 requests). Each count was checked to hold exactly that many rows in each of `entities`, `images` and `references`. A reading no larger than three times the spread of the five empty windows (or the idle drift) is below the measurement floor and no CPU ratio is made on it; a second rule, added after the 100,000-row readings were seen, also withholds the ratio where a route's three readings scatter by more than their median. Every withheld ratio is listed with its value in `per-request/README.md`.

**Five of the eight routes cost almost nothing and the estimate counts them right.** `chapter/references`, `page/references`, `books/{slug}/references`, `images/edit/{id}` and `oidc/callback` use 0 to 8 statements and read 0 to 229 rows at every count; the estimate's statements equal the measured ones and its rows are within 2% of them. Their database CPU time is below the measurement floor, so no CPU ratio is made on it: the medians of the three readings are between 0.00028 s and 0.0044 s for the four routes that read at least a few rows, and for `oidc/callback` (no statement, estimate 0 s) the median is negative once the empty window and the idle drift are taken off (−0.0015 s to −0.0010 s), so no ratio exists. The floor of the first rule held in every one of these cells except one: `books/{slug}/references` at 100,000 rows was withheld by the second rule alone, the one added after the 100,000-row readings were seen (the ratio that would have stood is 0.693).

**The three routes that carry the load.** "Est ÷ meas" is the estimate at its point divided by the measurement; the estimated CPU is `c_q × statements + c_row × rows` with the coefficients of the committed prediction.

| Route | Rows | Rows read, est ÷ meas | Database CPU measured (s) | CPU, est ÷ meas |
| --- | --- | --- | --- | --- |
| `GET /books/{bookSlug}/export/zip` | 10,000 | 1 | 3.3 | 1.06 |
| | 30,000 | 0.979 | 9.94 | 1.07 |
| | 100,000 | 1.78 | 25.2 (request cut by PHP's limit, HTTP 500) | 1.46 |
| `GET /books/{slug}` | 10,000 | 1 | 0.103 | 1.62 |
| | 30,000 | 1.04 | 0.342 | 2.28 |
| | 100,000 | 2.02 | 1.13 | 4.4 |
| `GET /shelves/{slug}` | 10,000 | 43.2 | below the floor, ratio withheld | withheld |
| | 30,000 | 128 | below the floor, ratio withheld | withheld |
| | 100,000 | 425 | 0.00811 | 24.7 |

- The ZIP route's statements are estimated almost exactly at all three counts (119,994 estimated against 120,004 measured at 100,000 rows). Its estimated rows are close at 10,000 and 30,000 rows and 1.78 times the measured at 100,000.
- For `GET /shelves/{slug}` the estimated rows are about N + 236 at every count (10,236, 30,236, 100,236) and the measured rows are 236, 236 and 237: the target shelf of the lab's data holds one book at every count. The estimate marks this route's statement `plan_unstable` and takes its heaviest plan state; that the numbers come from this was not tested.
- At 100,000 rows the ZIP route's measured CPU (25.2 s, the cut request) is 96% of the sum of the eight routes' measured CPU (26.3 s); in the estimate its 36.7 s is 88% of the sum (41.9 s). `GET /books/{slug}` is the next heaviest (1.13 s measured, 4.97 s estimated). For that request php-fpm used about 8.7 s of CPU against 1.13 s of database CPU.
- The server's own counters over the same windows agree with the counted statements and rows to within a small difference that is the same at every count for a route, except for the cut ZIP request (96,024 statements counted by the server against 120,004 counted for the uncut cold request).

### 3.2 Part 2: the ZIP route with and without PHP's limit

Three cold requests each, at 100,000 rows, on the same database, read the same way. The window closes when php-fpm has finished (the application container's CPU stops growing), not when the client got its answer. No other request was seen by the web server in either record.

| Measure (median of 3) | limit on (cut) | limit off | off ÷ on |
| --- | --- | --- | --- |
| HTTP status | 500, 500, 500 | 200, 200, 200 | |
| elapsed until the answer (s) | 53.1 | 71.1 | 1.34 |
| php-fpm CPU time (s) | 30.4 | 40.9 | 1.34 |
| database CPU time (s) | 25.2 | 33.4 | 1.33 |
| rows read by the server | 799,197 | 835,207 | 1.05 |
| statements counted by the server | 96,024 | 120,036 | 1.25 |

Against the estimate (119,994 statements, 1,489,446 rows, 36.71 s converted): statements 1.25 (cut) and 1.00 (uncut); rows 1.86 and 1.78; database CPU time 1.46 and 1.10.

**The limit counts the process's CPU time, not its waiting.** Through php-fpm with the limit set to 2 seconds inside the request: a request that waits 4 seconds and uses no CPU was not stopped (`wall_s` 4, `cpu_s` 0); a request that uses CPU for 6 seconds was stopped at 2 seconds of CPU (`cpu_s` 2). The cut ZIP request answered after 52.9 to 53.8 s while php-fpm had used 30.2 to 30.7 s of CPU.

**How the setting was changed and put back.** `max_execution_time=0` was appended to the lab's PHP ini file (a copy of the file, with its SHA-256, was kept outside the repository first), and the application and web containers were recreated with the lab's own `use-db` script (no `kill`-type command). After the three requests the copy was written back and the containers were recreated again. The evidence is read from the php-fpm side, at three times, and not by the command-line `php -i`, whose value is a different setting:

| | before | unlimited | after |
| --- | --- | --- | --- |
| `max_execution_time` seen by a request through php-fpm | 30 | 0 | 30 |
| ini file SHA-256 (first 8 characters) | f495eab5 | 28396641 | f495eab5 |
| `request_terminate_timeout` / `request_slowlog_timeout` (pool, `php-fpm -tt`) | 0s / 0s | 0s / 0s | 0s / 0s |
| nginx `fastcgi_read_timeout` | 120s | 120s | 120s |

After the restoration `git status` and `git diff` of the lab's compose directory were empty. The change was never committed.

### 3.3 Part 3: giving the gap to its sources

The method of section 2.3 was applied as fixed. Sums over the usable steps (31 of the 47), in utilization points; shares are of the sum of the difference `Δ = U0 − M`.

| Scenario | Rows | Usable steps | Sum of Δ | (a) | (b) | (c) | Share (a) | Share (b) | Share (c) |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| t-2 | 100,000 | 6 | +115.56 | +31.40 | +54.25 | +29.90 | 27% | 47% | 26% |
| t-3 | 100,000 | 6 | +124.25 | +33.15 | +59.46 | +31.64 | 27% | 48% | 25% |
| t-5 | 100,000 | 6 | +105.37 | +31.79 | +47.84 | +25.74 | 30% | 45% | 24% |
| t-1 | 30,000 | 7 | +30.05 | +2.02 | 0 | +28.02 | (points are the reading) | | |
| t-4 | 10,000 | 6 | +4.05 | +1.14 | 0 | +2.91 | (points are the reading) | | |

- **Stability.** Within each of the three 100,000-row scenarios the split is similar at every usable step (each of (a), (b), (c) moves within about 12 points). The other order, (b) then (a), moves only the split between (a) and (b), by 2 points of `Δ` or less; (c) does not depend on the order. The (a) aligned to 10,000 rows is +31.24, +33.09 and +31.24 (shares 27%, 27%, 30%), within 0.5 point of the main shares, so the border between (a) and (c) is fixed for these three. For `t-4` the aligned (a) is 0 by construction and the main (a) share is 28%, so the border is not fixed there: (a) is 0% to 28% of a `Δ` sum of 4.05 points.
- **What is in (a) at 100,000 rows.** The estimate's rows are too large for two routes: `GET /books/{slug}` (2,539,961 rows estimated against 1,260,454 measured; +17.5 to +21.7 points in the sums) and the ZIP route (+8.9 to +11.0); `GET /shelves/{slug}` adds +0.4 to +5.5.
- **What is in (b).** Every ZIP request counted in the usable steps of these three scenarios did not return 200 (the share `f` is 1.00), so each was put at the cost of a cut request (25.2 s of database CPU, 96,024 statements, 799,197 rows) instead of an uncut one (33.4 s, 120,036 statements, 835,207 rows). The ZIP requests that returned no response (status 0) are counted as cut; whether php-fpm kept running them after the load tool gave up was not checked.
- **What is in (c).** Its two parts, (c1) +32% to +33% of `Δ` and (c2) −7% to −8%, compare the converted counts with the measured CPU time of one cold request and the measured utilization with the sum of those times. For example `GET /books/{slug}` at 100,000 rows is 1.13 s measured against 2.47 s from its counts, and the cut ZIP request 25.2 s against 28.6 s. A third piece, (c3), is the difference between the decided rps and the rps that came out; it is a difference of counting inside (c2), not a difference of load (every step is within 5%, the load tool dropped no request in any step), and it is not read as a cause. Its values are in `allocation/allocation-tables.md`.
- **Why `t-4` and `t-1` came out close.** The estimate is close at those counts: (a) is +1.14 and +2.02 points (the ZIP route's statements and rows equal the measured ones within −0.00 and −0.30 points, `GET /books/{slug}` +0.03 and +0.75, `GET /shelves/{slug}` +1.12 and +1.57), and the ZIP route is not cut there (its PHP CPU time is 4.1 s at 10,000 rows and 12.1 s at 30,000 rows against the 30-second limit), so (b) is 0. What is left, (c), is small: over the six usable steps of `t-4` it lies between −0.29 and +1.88 points, and over the six steps of `t-1` below 1.5 rps between +0.07 and +4.25 points (the 1.5 rps step is the one above). Its two parts cancel (`t-4`: (c1) +14.35 and (c2) −11.44; `t-1`: +29.64 and −1.62). In `t-1`, one step carries most of the sum: 1.5 rps (measured 87.8%, predicted 107.6%, where the requests with no response begin) has `Δ` +19.77 of which (c) is +19.07; without it `Δ` is +10.28 and (c) +8.95 over six steps.

**The sensitivity of the prediction to `c_row`.** `c_row` and `c_q` were put at the ends of the calibration's own width: the primary reading (i) takes each leave-one-out fit as it is (smallest `c_row` 1.37851e-6 with `c_q` 2.87058e-4, largest `c_row` 1.12854e-5 with `c_q` 1.74879e-4; each fits three of the four calibration runs); the reading (ii) keeps `c_q` at 2.81705e-4 and moves `c_row` alone (this pair does not fit the calibration runs). Change of the predicted limit rps against the committed point:

| Scenario | (i) smallest `c_row` | (i) largest `c_row` | (ii) smallest `c_row` | (ii) largest `c_row` |
| --- | --- | --- | --- | --- |
| t-2 (100,000) | +4.3% | −38.1% | +6.0% | −47.9% |
| t-3 (100,000) | +4.2% | −37.5% | +5.9% | −47.6% |
| t-5 (100,000) | +4.7% | −40.2% | +6.3% | −49.3% |
| t-1 (30,000) | +1.7% | −17.2% | +3.4% | −35.2% |
| t-4 (10,000) | +0.5% | −1.2% | +2.3% | −26.6% |

To reach the measured limit rps the predicted limit would have to rise by 63% (`t-2`), 54% (`t-3`) and 61% (`t-5`).

### 3.4 Part 4: the steps without any response

- **The time-out.** The load tool's limit for one request is 60 seconds. It is written nowhere in the repository: the load script passes no `timeout`, the measuring script gives the tool only the three file paths, and the compose file sets no `K6_` variable, so it is k6's default (k6 2.3.0). In the 10 steps with status 0 the longest request lasted 59.998 or 59.999 s; in the 37 steps without it, 57.719 s. Other limits on the same requests: k6's wait of 30 s at the end of a step (its default), nginx's `fastcgi_read_timeout` of 120 s (never reached), and PHP's 30-second execution limit.
- **Which routes.** The records of a step keep the status counts as one total and the requests per route only as a count, so they do not say. The web server's log (kept outside the repository) does: counting its status-499 lines of 59 s or more in the kept window gives the same number as k6's status 0 in 8 of the 10 steps, and one more in the other two. At 100,000 rows, at the first step where status 0 appears, every one of the requests with no response is the ZIP export (`t-2` step 7: 18 of 18; `t-3` step 6: 22 of 22; `t-5` step 9: 8 of 8 and step 10: 22 of 22). The exception is `t-2` step 8 (database at 99.0%): 27 of the 62 are ZIP and the other 35 are spread over the seven other routes. At 10,000 and 30,000 rows they are spread over all routes and ZIP is a minority (4 of 29 and 14 of 148).
- **From HTTP 500 to no response.** The ZIP route's 500 comes 52 to 60 seconds after the request began (steps' means 53.2 to 59.4 s) and later as the load rises; at the step where the load takes it past 60 seconds the load tool gives up first and the request is recorded as status 0 (`t-5` step 9: 7 ZIP requests came back with 500 and 8 got no response; in the next step none got a response). It is the same request ended by whichever of the two timers comes first.
- **How it touches the utilization.** The utilization is the change of the database container's CPU time over the kept window, and no rule reads a request's status or whether its client is still there. The database keeps working after the load tool has given up: in `t-2` step 8 the database stayed at 0.97 to 0.98 for the 30 s after the load tool had exited (114.1 CPU seconds after the window against 679.1 inside it). The records cannot say how much of that work belongs to abandoned requests alone. Below saturation, the utilization per achieved rps at the first step with status 0 differs from the step before it by −0.9% (`t-2` step 7), −0.9% (`t-3` step 6) and −1.1% (`t-5` step 9), the same size as the changes between steps without status 0 (−1.9% to +0.8%): about 1 to 2% at most, on the condition that the utilization per rps would otherwise stay flat.
- **Saturated steps are capped.** A step cannot show more than 100%. The steps at 87.8% to 99.0% have a utilization per achieved rps below the step before by 13.0% (`t-2` step 8), 13.0% (`t-4` step 13), 11.7% (`t-1` step 10) and 4.2% (`t-5` step 10). Drawing the measured limit with the upper step kept on the straight line of the step before it would lower the measured limit rps by 0.8% to 8.5% in all five scenarios, the ones that matched as much as the ones that did not (an illustration of the size, not a score).
- **The next scenario's window.** `t-3` step 1 began 14 s after `t-2` step 8 was recorded as ended, while the database still worked at 0.93 to 0.98 for about 30 s; at most 9.2 of the 1,600 core-seconds of that window, 0.6 points of its 14.6%, can be carry-over (an upper bound).
- **The edge of the rps check.** `t-2` step 8 had 229 requests counted in its window (240 planned; 11 were cut by the load tool at the end of its wait and are not counted), which is the least number that keeps the step within 5% of the decided rps (its achieved rps is 0.6676 against 0.7, −4.6%): it had no request to spare, and one more request cut at the end of the step would have made it invalid. Every other step of the 47 has at least 2 requests above its minimum. Had that step been invalid, the limit would have been read as extended from the two top valid steps and would have been 0.4767 instead of 0.4966 (−4.0%). The verdict does not change; the edge was close.

## 4. Reading of the cause

A cause is called established below only where a measurement of this file shows it directly. The file set no threshold in advance for the size of a share.

### 4.1 What can be stated

1. **The miss at 100,000 rows is not one thing.** For `t-2`, `t-3` and `t-5` the difference between the predicted and the measured utilization splits into the estimate of one request of each route (27% to 30%), the ZIP requests that were cut (45% to 48%) and the rest (24% to 26%). The split is stable across steps, across the order of (a) and (b), and across the aligned reading of (a).
2. **The ZIP route carries almost all of the cost at 100,000 rows, and PHP stops it.** Its measured CPU is 96% of the sum of the eight routes'. PHP's execution limit counts CPU time, not waiting (shown through php-fpm), and a cut request does less database work than an uncut one: 25.2 s against 33.4 s of database CPU, 96,024 against 120,036 statements, 799,197 against 835,207 rows. The estimate counts the full request (its statements, 119,994, against 120,036 counted for the uncut request: a ratio of 1.00), so the part (b) is not an error in the estimate of the full request: it is the work that the lab's run never asked of the database because PHP stopped the request first.
3. **Two routes of the estimate are off at 100,000 rows and not at 10,000 and 30,000.** `GET /books/{slug}` has 2.02 times the measured rows at 100,000 rows (1.00 and 1.04 at 10,000 and 30,000), and `GET /shelves/{slug}` has estimated rows that grow with the row count while the measured stay at 236 or 237. The ZIP route's rows are 1.78 times the measured at 100,000 rows (statements are right). The five small routes are right at every count.
4. **The width of `c_row` alone does not explain the miss.** Putting `c_row` at the end of the calibration's width that most favours the prediction raises the predicted limit rps of the three scenarios by +6.3% at most (reading (ii)), against the 54% to 63% that would be needed; the other end widens the miss.
5. **`t-4` and `t-1` came out close because the estimate is close at those counts and the ZIP route is not cut there.** Not because errors cancel in (a); (c1) and (c2) do cancel in part, and `t-1` rests mostly on one step.
6. **The requests with no response do not explain the miss.** No rule of the score reads a status; below saturation they leave no change in the utilization per rps larger than about 1 to 2% (on the condition of flatness); the cap of the saturated steps lowers the measured limit by 0.8% to 8.5% in all five scenarios, the matched ones included, against a miss of 35% to 38% in three.
7. **One step was at the edge of the rps check.** `t-2` step 8 had no request to spare (229 against a minimum of 229). The verdict does not change.

### 4.2 What cannot be stated

1. **Why a cold request's database CPU time is below the conversion of its counts** ((c1), +32% to +33% of `Δ`; for the two heavy routes it is 1.13 s against 2.47 s, and 25.2 s against 28.6 s). Whether another pair of coefficients would remove it was not tested; the sensitivity says only that `c_row` moved inside the calibration's own width does not.
2. **What (c2) is** (−7% to −8% at 100,000 rows; about −1 point at the lowest steps). The database's idle CPU rate read in the per-request records (0.63 to 0.65 points of utilization) is of the same size; it was not tested as the cause.
3. **Whether a request cut under load does the same database work as a cut request alone.** (b) puts every ZIP request that did not return 200 at the cost of the cold single request that was cut. Under load the interleaving, the database's speed and the time PHP spends waiting differ, and requests with no response were counted as cut without a check that php-fpm kept running. The size of (b) rests on this substitution; its existence (a cut request does less work than an uncut one) does not.
4. **Whether the estimate of `GET /shelves/{slug}` is wrong or the lab's data is unlike a deployment's.** The lab's target shelf holds one book at every count, so the measured rows stay at 236; a shelf of a real deployment may hold more books as the data grows. This file cannot separate the two.
5. **The border between (a) and (c) for `t-4`** (0% to 28% of a `Δ` sum of 4.05 points).
6. **What the limit rps would be if each part were corrected one at a time.** That is a prediction; this file makes none.
7. **Whether the cut of the ZIP route would show at counts between 30,000 and 100,000 rows.** At 30,000 rows PHP used 12.1 s of CPU for it, at 100,000 rows the limit was reached; the counts in between were not looked at.

## 5. Proposals

These are proposals only. Nothing in them is carried out, no estimate, calibration, formula or prediction tool was changed to write them, and the project owner judges them. Two rules hold for all of them. Data already seen (the three row counts of part 1 and the five scenarios of test B) are not used to confirm a repair made from them: a repair is checked on new prediction scenarios at 5 × N_0 and 20 × N_0 (50,000 and 200,000 rows in each of the eight N tables), as `docs/simulator-spec.md` section 7.4 requires. And what is proposed to fix is fixed before those scenarios are predicted, in the order of test B (the prediction is committed before any step of them is measured). Whether the lab can build and measure at 200,000 rows has not been examined.

### 5.1 Ways to repair, cause by cause

**R1. The ZIP cut ((b), the largest part).** The cut is not an error in the estimate of a full request, so shrinking the estimate would be wrong for a route that is not cut. Two options:

- *R1a (smaller change).* Keep the estimate of the full request, and make the prediction say, beside the limit rps, that the limit rps counts the route as completing. This does not close the gap in the number; it stops the number from being read as more than it is (see the mark of section 5.2).
- *R1b (larger change).* Predict the database work of a route that is stopped by the limit, as a share of the full work. The only measurement of that share is one pair of requests at one route and one row count (database CPU 25.2 s cut against 33.4 s uncut, statements 96,024 against 120,036), so a share taken from it would be one data point. It would need measurements of the cut share at more than one route and count, under load as well as alone (section 4.2, item 3), before it is a rule. R1a is the part that can be done on the evidence in hand.

**R2. The estimate of a route's rows ((a)).** Where: the estimate of rows per statement at the target row count (the EXPLAIN-based estimate and the row fit of `bin/estimate`, section 4 of the spec). For `GET /books/{slug}` (2.02 times at 100,000 rows) and the ZIP route (1.78 times) find which statements carry the excess by comparing the estimate with the per-statement `Handler_read_*` counts of the cold request, and repair the rule that produced it, not the two numbers. For `GET /shelves/{slug}` first settle whether the estimate or the lab's data is unlike a deployment's (section 4.2, item 4): if the data, the lab's data generator (a shelf with more books) is what to change, not the estimate. Check: the route-level counts of the estimate against one cold request per route at 50,000 and 200,000 rows, then the new scenarios.

**R3. The conversion of counts to database time ((c1)).** The calibration was made at 10,000 rows and at 3,000 rows only, and `c_row` is the less certain coefficient (a factor of 8.2 between the ends of its width). Proposal: add one calibration run at a row count where no route is cut and the count is not one of the new check counts (30,000 rows: the ZIP route used 12.1 s of PHP CPU there against the 30-second limit), and look at whether the width of `c_row` narrows; a calibration at 100,000 rows would put the cut route into the fit. This is a proposal to look at the width, not a claim that a better pair exists (section 4.2, item 1). Check: the new scenarios. The unexplained (c2) is not repaired; it is first measured (the same step measured once with and once without the single requests of part 1, or the database's idle rate subtracted) so that it is known whether it belongs to the prediction at all.

**R4. Order.** R1a and the mark (section 5.2) first, because they change what a limit rps says without changing any number and can be tested on the new scenarios without a re-fit; R2 and R3 after, each checked alone on the new scenarios so that its effect on the limit rps is seen.

### 5.2 The mark: "this route is likely to stop at the application's limit before the database"

**What it tells.** At the time of the prediction, for the given row count, that a route is likely to be stopped by a limit of the application (PHP's execution time first) before the database is the limit, so the limit rps that the prediction gives counts that route as completing and the application would fail first for it.

**What it rests on.** The converted database time of one request of the route, `c_q × statements + c_row × rows` at the target row count, set against the application's execution limit. For the ZIP route at 100,000 rows it is 36.71 s (point and safe side) against a limit of 30 s; the measured values were 25.2 s of database CPU and 30.4 s of PHP CPU for the cut request, and 33.4 s and 40.9 s for the uncut one. The limit counts PHP's CPU time and the estimate has no PHP time, so database time is a proxy: for `GET /books/{slug}` at 100,000 rows php-fpm used about 8.7 s of CPU against 1.13 s of database CPU, and for the ZIP route 40.9 s against 33.4 s. A mark on database time alone can miss a route whose PHP work is heavy, so it is proposed as a warning, not as a guarantee, and the file proposes to measure the PHP CPU time of the routes it names (or of the heavy routes) at the new counts to see how far the proxy is from it.

**Where the limit comes from.** From an input of the prediction (the application's `max_execution_time`, 30 s by default in the lab), not from a constant in the tool, because a deployment's value differs. The threshold at which a route is marked (the share of the limit that the converted time must reach) is fixed before the new scenarios are predicted, and not from the values of the scenarios already seen.

**Where it is shown.** In the output of the prediction, in the line that gives the limit rps, so that a limit rps is never printed without the routes marked for it; and, per route, in the list of marks beside the existing marks (`plan_unstable`, `plan_changes_at_scale`). It does not change the utilization or the limit rps themselves.

**How it is tested.**

1. *Unit tests, no lab.* On a made-up estimate, a route whose converted time is above the limit is marked, one below is not, and the mark follows the input limit; the output without a mark is byte-identical to the output now.
2. *Before the new scenarios are measured.* Fix and commit the threshold, then list which routes the mark names at 50,000 and 200,000 rows; at 10,000 and 30,000 rows the ZIP route is not cut, so a mark there is a false alarm to be counted.
3. *In the lab, on the new scenarios.* Once at each count, one cold request per route (one at a time, no load, as in part 1) and the HTTP status and PHP CPU time of each; then in the steps of the ladder, the status counts per route (kept per route from the start, not recovered afterwards from the web server's log as in part 4). The mark is scored for each route and count: marked and cut, marked and not cut, not marked and cut. A route cut without a mark is the case that matters most, since it is a limit rps that the application could not reach and the prediction said nothing about.
4. *What counts as a hit* is also fixed before the measurement.
