# Lab backtest (B): where did the limit rps miss at 100,000 rows, and why?

Status: IN PROGRESS. Sections 1 and 2 (what test B found, and how the cause is looked for) are written before the results of the four parts of the investigation and are not changed by them. Section 3 (results) and sections 4 and 5 (the reading of the cause, and the proposals) are filled in after the four parts are done. Every figure in this file is copied from the output of a tool or from a record; none is worked out by hand.

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

**How.** From the usable steps of the three scenarios in the records of test B, the difference between the predicted and the measured utilization is divided into the three parts. The division uses only the measurements of parts 1 and 2 and the input of the prediction committed in `5bdf6e8`; no new load is applied, and no prediction is made again. (a) is what the difference becomes when the estimate's cost of each route is replaced by the measured cost of part 1; (b) is the part that remains for the ZIP route when the stopped request's work of part 2 is put in place of the finished one; (c) is what is left. The way the replacements are ordered is given with the result. Where a share depends on the order, the file shows another order beside it so that the dependence is visible.

**The test of (c).** The `c_row` of the four-run fit is replaced by the two ends of its range in the fits that leave one run out (1.379e-6 and 1.129e-5), the matching `c_q` of the same fit is taken with it, and the predictions of the three scenarios at 100,000 rows are recomputed from the committed input. The result is how far the predictions move when only the coefficients move inside the width the calibration itself reported.

**The same division for `t-4` and `t-1`.** It is made for the two scenarios that came out close (10,000 and 30,000 rows), so that it is visible why they came out close: whether the three parts are small, or whether larger parts cancel.

**What it is and is not.** This is a separation of causes, not a scoring. The verdict of test B and `score.json` are not changed by it, and the prediction is not remade in the light of it.

### 2.4 Part 4: the steps without any response

**Question.** At the high steps (for example `t-2` at the steps near 80%), HTTP status 0 (no response) gathered. What is the load tool's time-out, which routes' requests got no response, and does this bear on how the database utilization was measured?

**How.** From the records only; no new load is applied. The time-out of the load tool (k6) is read from the load definition of the lab (`poc/lab/load/`). The routes of the requests with status 0 are taken from the per-step records of `docs/research/lab-data/ladder/` and the HTTP-status table in `docs/research/lab-data/score/http-status.md`. Whether the status counts show which route they belong to depends on what the records keep; where a record does not name the route, the file says so and gives the count per step only.

**The effect on the measurement.** The utilization of a step is the change of the database container's CPU time over the kept window divided by the window's length times 2 CPUs (`docs/research/lab-backtest.md`, section 1). It does not read an HTTP status. What has to be argued from the records and from the procedure is therefore a different thing: whether work done for a request after the load tool has stopped waiting for it (a request in the database when its client has gone) can fall inside the window of the same step, fall into a later step, or fall outside every kept window; and which of these the records can tell apart. The file states what the records support and what they do not.

## 3. Results

To be filled after parts 1 to 4 are done: the table of part 1 (per route and row count: measured, estimate, "estimate ÷ measured" in rows, queries and database CPU time), part 2 (the cut-off and the finished request side by side, and the proof that the pool's setting was restored), part 3 (the division of the gap for the five scenarios, and the movement of the three predictions at the two ends of the range of `c_row`), and part 4 (the time-out, the routes of the requests without a response, and the effect on the measurement).

## 4. Reading of the cause

To be filled after section 3: what can be stated as established, and what cannot, kept apart. A cause is called established only where a measurement of this file shows it directly. The file sets no threshold in advance for the size of a share; it reports the shares and says, for each measurement, what it shows and what it does not.

## 5. Proposals

To be filled after section 4. Proposals only: nothing in them is carried out, and the project owner judges them. Two groups: ways to repair the estimate or the calibration cause by cause, with the check of any repair to be made on new prediction scenarios at 5 × N_0 and 20 × N_0 (the row counts that test B has not seen, as `docs/simulator-spec.md` section 7.4 requires); and a mark that tells, at the time of the prediction, that for a given row count a route is likely to stop at a limit of the application (for example PHP's execution time) before the database, with what it would rest on, where it would be shown, and how it would be tested.
