# Lab backtest (B), second round: does the repaired simulator predict the lab at row counts it had not seen?

Status: PARTLY WRITTEN. Sections 1 and 2 and the settled items of section 7 are written, from records that were closed before this text was made. Sections 3 to 6 are still an outline: each says only what will go into it, and no figure is written in them. The recalibration (end of section 1) is pending. Every figure below is copied from a record or from the output of a tool, with the commit or the directory it comes from.

This file is the record of the second round of test B and of the mark `app_limit_risk` (`docs/simulator-spec.md`, version 0.2, sections 6.4, 7.2, 7.4 and 7.4.1). It follows `docs/research/lab-backtest.md` (the first round, which did not pass) and `docs/research/lab-backtest-causes.md` (where the first round's miss came from). It says what was repaired, what was done in which order, what was measured, and what the numbers can and cannot support. The pass lines, the tolerances and the rule for "undecidable" are those of the spec and are not moved by this file.

What is checked is a **check against a busy period reproduced in the lab**: a prediction compared with a measurement made in the lab. Nothing here was observed on a real deployment, and no value measured in production appears.

## 1. What was repaired

Each repair made after the first round, in the order of the causes in `lab-backtest-causes.md` (R1a with the mark, R2, R3 and the look at (c)). Every entry gives the decision (in `docs/decisions.md`), the commits, and the reason. Where a rule was chosen after records had been read, the entry says which records, so that the reader can tell what the later check could and could not test. Nothing is repaired in this file.

### 1.1 The row estimate (R2)

**Books page and ZIP export (D174, D179).** At 100,000 rows the estimate of rows read was 2.02 times the measured rows for `GET /books/{slug}` and 1.78 times for the ZIP export.

- Cause found from the committed records alone (the lab was not used): two statements that join `entities` and `entity_container_data` (`s7ff3e12408`, and `s24834a57a6` in the book page only). The capture read them at 100, 1,000 and 10,000 rows, and the plan changes between those sizes (an index lookup at the two small sizes, an index merge at 10,000). The fit `a + b·N^k` took the jump for a growth rate (`k` = 1.67 and 1.64) and kept accelerating to 100,000 rows.
- The repair has two parts. (1) Above the capture's largest size, a fit with `k` above 1 is carried on along its tangent at that size; the curve itself is kept as the upper side (`f029e9b`, record `94969fa`, estimates `483a200`). (2) The capture is made again on databases that were analyzed after they were built (`bin/capture --analyze-after-build`, `32359c1`), with 30,000 rows added to its sizes (now 100, 1,000, 10,000 and 30,000), and the tangent rule is kept (D179, `3ffeecb`; the result, `b3997f1` and `b09d35e`). The capture databases are disposable and keep automatic statistics recalculation off, so that the plan does not change in the middle of a capture; the lab's databases do not (section 1.5).
- Estimate ÷ measured rows at 100,000 rows, point: book page 2.02 (old capture, no rule), 1.02 (new capture, no rule), 1.01 (new capture, tangent rule); ZIP export 1.78, 1.01, 1.01.
- **What this cannot show.** The tangent rule was chosen after the 30,000- and 100,000-row records had been read, and 100,000 rows was already seen; with the new capture the tangent and the curve differ by 0.4 to 0.8% at 100,000 rows, so the rule hardly acts. The 10,000- and 30,000-row counts are inside the capture. None of the three is a test of the estimate. The test is a count the repair had not seen: 70,000 rows (section 2). One more thing seen on the way: against the first 10,000-row baseline, which was measured on a database that was not analyzed, the new capture's point is 1.59 for the book page and 1.55 for the ZIP export (1.00 with the old capture); the measured rows themselves rose by 61% and 56% once the database was analyzed, and against the baseline made again the points are 0.984 and 0.992 (section 2.3). The cause of the first jump was not tested.

**Shelf route (D175, `d55d1f4`).** The estimate rose as N + 236 while the measured rows stayed at 236, 236 and 237 (estimate 43, 128 and 425 times the measurement at 10,000, 30,000 and 100,000 rows).

- Decision: the estimate is wrong; the lab's data is not. 100,002 of the 100,235.7 estimated rows come from one statement on `activities` that does not touch the books of the shelf, so adding books to the shelf would not move them. The same statement ran with a different plan when the capture took it (`type=index` on `created_at`, rows = N) and when it was explained (`type=ref`, 4 rows, the same in six statistics states). The generator, which keeps one book on the target shelf, was not changed.
- Repair: the capture is made on analyzed databases (D179), so that the statement runs in the capture with the plan it has when explained. Estimate ÷ measured at 100,000 rows: 425 (old capture), 1.27 (new capture, no rule), 1.15 (new capture, tangent rule).

### 1.2 The weight of a row, and the formula (R3, the look at (c))

D176 (`4bf33f5`, `577c965`; script `b67fa19`). At 100,000 rows the book page's database CPU time converted from counts was 2.47 s against 1.13 s measured. The two heavy routes at the three counts of `docs/research/lab-data/diagnosis/per-request/` give six points. The question was whether a row read by a full scan weighs more than a row read through an index.

- Two coefficients (statements, rows) fitted to the six points: error −15.1 to +6.3%, leave-one-out −18.7 to +10.5%. Three coefficients (statements, scan rows, index rows): error −1.1 to +1.1%, but leave-one-out −22.2 to +2.3% (−27.7% for a split by key reads). The records hold no per-statement kind of read, scan rows are 0.006 to 11% of the rows, and the scan coefficient rests almost on one point; so the records cannot say that the two kinds weigh differently.
- **The formula stays at two coefficients.** The rule for comparing candidate formulas had to be fixed before any 70,000-row or new-calibration result was seen; no candidate stood on the records, so no comparison rule was placed, and the formula was not changed afterwards. (c2), the difference between a cold single request and a request under load, is not repaired.

### 1.3 The mark `app_limit_risk`, and what the limit rps says (R1a)

D178 (`f932a89`, implementation `c9f08de`; the factor is reproduced by `poc/lab/diagnosis/app_limit_factor.py` from committed records).

- What it watches: PHP's `max_execution_time` counts the process's CPU time. At 100,000 rows the ZIP export was cut (HTTP 500) at 53.1 s elapsed and 30.4 s of PHP CPU time (median); with the limit off it finished at 40.9 s of PHP CPU time.
- Rule: a route is marked when `factor × (the model's database time for one request, the larger of the point and the safe side) ≥ the limit`. The limit is an input (`resources.app_cpu_time_limit`, seconds) with no default; without it no mark is output and the output has no mark key. The mark changes neither the utilization nor the limit rps. R1a: beside the limit rps, the output says that the limit rps counts each marked route as running to its end.
- **Factor 9.11**: the largest php CPU time ÷ measured database CPU time over the seven records whose database reading was not withheld (book page 9.107 at 10,000 rows, 7.653 and 7.702 at 30,000 and 100,000; ZIP 1.236, 1.217, 1.222; shelf 3.364), rounded up. The denominator is the measured database time, not the converted one, so that the factor keeps its meaning when the formula or the calibration changes. It was fixed from the 10,000-, 30,000- and 100,000-row records only, before any 50,000-, 70,000- or 200,000-row result and before the new calibration.
- Applied to the 24 route-and-count pairs already seen (limit 30 s; estimates as committed before the repairs): 1 hit (ZIP, 100,000 rows), 0 misses, 3 false alarms (ZIP at 10,000 and 30,000 rows; book page at 100,000 rows), 20 correctly unmarked. **This count is not a forecast of the false alarms at a new count**: the book page's 100,000-row estimate it used was the 2.02-times one. The ZIP export has a ratio near 1.2 and is marked with a factor of 9.11, so it will give false alarms; the false alarms are counted and shown, and the line for passing is no misses (spec 7.4.1). The records behind the factor were made on databases of which it is not recorded whether they were analyzed, and the factor is not changed.
- Every prediction output carries one more note, `estimate_basis_note`, saying that the estimates assume a database whose statistics are in order (section 1.4); apart from that note the mark adds nothing to an output made without a limit (`df8a69e`, `6ff4237`).

### 1.4 The premise of the estimate: databases with statistics in order

From the ruling of 2026-11-11 (D180, `11ed9d7`; spec `d44a09c`): every lab database, whatever its row count, is analyzed with `ANALYZE TABLE` after it is built and before it is used (calibration, capture, the 70,000-row check, the new counts), and `innodb_table_stats` and `innodb_index_stats` `last_update` are recorded before use. The estimate says in its output and in this file that it assumes such a database. A database whose statistics are stale (as the capture's most likely were, which is the probable but untested cause of the shelf route's miss) is outside what the estimate claims.

### 1.5 Statistics during a measurement (D181, `fb07ff9`)

The lab databases keep `STATS_AUTO_RECALC` at its default after the `ANALYZE`; before and after each step the `last_update` of the tables the eight routes read (including `views`) is recorded; if a recalculation runs in the middle it is recorded and reported, not stopped; and no `ANALYZE` is run again between scenarios measured one after the other on the same database (50,000 rows: r-1 then r-2; 200,000 rows: r-3, r-4, r-5). `GET /books/{slug}` and `GET /shelves/{slug}` write to `views`, so a recalculation can run, and the statements on `views` are marked `plan_unstable`. This was fixed before the 70,000-row check, the new calibration and the new counts were seen.

### 1.6 Recalibration

Pending. The calibration measured again with the application's time limit lifted, its coefficients, its residuals and its width, and how it is handled, are written once how it is handled has been decided.

## 2. The check of the repairs (70,000 rows)

The estimate set beside one request at a time at 70,000 rows, a row count used for this check only and never for the test (spec 7.4.1), without any other load. 70,000 rows is not one of the capture's sizes (100, 1,000, 10,000, 30,000) and was not used to choose any rule. Records: `docs/research/lab-data/recheck-70k/` (measurement, `b5aa97a`) and `docs/research/lab-data/recheck-70k/estimate-70k/` (estimate and check, `b4c27a2`).

### 2.1 How it was done, and in what order

Eight routes, three cold requests each, one at a time, the same tool and items as in `lab-backtest-causes.md` (`request_probe.py run --part=both --reps=3`). Every run was made with `--require-limits=unlimited`: it does not start unless PHP's `max_execution_time` is 0, `request_terminate_timeout` is 0 and the web server's read limit is at least the request timeout (600 s). The lab's databases had been analyzed beforehand. Nothing else used the lab (`other_requests_seen` is 0 in all 72 cold requests of the three counts). The 50,000- and 200,000-row databases were not touched. The limits were put back afterwards and read back from the server side (`max_execution_time` 30, web server read limit 120 s, the two files byte-equal to before).

| time (2026-11-11) | event |
| --- | --- |
| 14:34 | the criteria of the check were committed (D182, `96fa0b4`) |
| 14:58 | the measurement was committed (`b5aa97a`) |
| 15:01 to 15:03 | EXPLAIN at 70,000 rows, on a disposable analyzed database; then `bin/estimate`, run twice, byte-identical |
| 15:05 | the estimate and its check against the criteria were committed (`b4c27a2`) |

The estimate was made after the measurement existed. What guards against fitting it: the criteria were committed before the measurement; the estimate code was taken from the measurement's own commit (`git diff b5aa97a -- poc/runtime` empty; the last commit that touched the estimate or EXPLAIN code is `32359c1`, 14:15, before the measurement); the capture, its SHA-256 (`69f08fca…`), and the committed calibration are unchanged; and no rule, capture, formula, calibration or coefficient was changed after the reading. The EXPLAIN was taken on a database built for it and dropped, not on the lab's, so the lab's statistics were not touched. Statistics state during the three measurements (10,000, 30,000 and 70,000 rows): automatic recalculation at its default, and 0 of 41 tables changed `last_update` in any of them.

### 2.2 The three repaired routes and the five others

Measured values are medians of three cold requests. Estimate ÷ measured, rows read:

| route | rows measured | estimate (point) | point ÷ measured | upper side ÷ measured | statements, estimate ÷ measured |
| --- | --- | --- | --- | --- | --- |
| `GET /books/{slug}` | 882,454 | 891,734 | 1.011 | 4.32 | 1.042 (46.9 against 45) |
| `GET /books/{bookSlug}/export/zip` | 584,696 | 588,007 | 1.006 | 3.79 | 1.000 |
| `GET /shelves/{slug}` | 236 | 267.3 | 1.133 | 1.166 | 1.000 |
| `GET /books/{bookSlug}/chapter/{chapterSlug}/references` | 222 | 223.25 | 1.006 | 1.006 | 1.000 |
| `GET /books/{bookSlug}/page/{pageSlug}/references` | 229 | 233.0 | 1.018 | 1.018 | 1.000 |
| `GET /books/{slug}/references` | 217 | 218 | 1.005 | 1.005 | 1.000 |
| `GET /images/edit/{id}` | 199 | 199 | 1.000 | 1.000 | 1.000 |
| `GET /oidc/callback` | 0 | 0 | none (0 and 0) | none | none (0 and 0) |

The criteria, as committed in D182:

| # | criterion | reading | met |
| --- | --- | --- | --- |
| 1 | the three repaired routes: point within ±20% of the measured rows | 1.011, 1.006, 1.133 | yes |
| 1b | within ±10% as well (then the target level is also reached) | 1.011 and 1.006 yes; 1.133 no | for 2 of 3 |
| 2 | the three repaired routes: the upper side is at least the measured | 4.32, 3.79, 1.166 | yes |
| 3 | the other five routes: statements within ±5% and rows within ±20% | statements 1.000 on each of the four that have statements; rows 1.000 to 1.018 | yes |
| 4 | any repaired route with the point below 0.80 | none (smallest 1.006) | not occurring |

By the criteria as written the repair is effective at 70,000 rows. The ±10% level is reached for the book page and the ZIP export and not for the shelf route, whose point is 13.3% above the measured 236 rows (31 rows). The upper sides of the book page and the ZIP export are 4.3 and 3.8 times the measured rows: the upper side is a bound, not a point estimate, and it is far above the measurement.

### 2.3 What improved and what did not

- **Improved**: the row estimate of the book page and the ZIP export. At 100,000 rows, where the miss was seen, they were 2.02 and 1.78 times; at 70,000 rows they are 1.011 and 1.006. The shelf route went from 425 times at 100,000 rows to 1.133 at 70,000 rows. The two columns are at different counts: the old estimate was never made at 70,000 rows, so there is no before-and-after at one count. Only the 70,000-row figure is a check, because 100,000 rows was already seen when a rule was chosen. The five other routes did not change.
- **Not improved, and not meant to be**: the conversion of counts to database CPU time. The book page's database CPU time from the committed calibration's two coefficients, applied to the estimate's statements and rows, is 2.24 times the measured 0.784 s (9.51 times on the upper side); the ZIP export's is 1.03 times the measured 24.08 s (1.16 on the upper side). Applied to the measured counts instead, the conversion is 1.011 and 1.000, so the book page's miss in time is in what a row or a statement costs (the calibration), not in the counts. The other six routes read below the measurement floor and no ratio is made. The formula is not changed (D176, section 1.2).
- **PHP CPU time ÷ database CPU time** (recorded only; the mark's factor is 9.11 and is not changed): book page 7.89, ZIP export 1.25, both below 9.11. The ZIP export's PHP CPU time at 70,000 rows is 29.98 s, against PHP's 30-second limit; it took 51.7 s (51.8, 51.6, 51.7) with the limit off, all with HTTP 200.
- **The baselines made again on analyzed databases** (10,000 and 30,000 rows, `baseline-10000/`, `baseline-30000/`; inside the capture's fit, so a record and not a test): at 10,000 rows the rows read by the book page went from 78,460 to 126,464 (+61%) and by the ZIP export from 53,698 to 83,696 (+56%) once the database was analyzed; at 30,000 rows nothing of note changed; statement counts are the same everywhere. These are the two routes that hold `s400220f42f` and `s58efd897dc`.

### 2.4 What this does not say

- It is one count that is not in the capture. It says nothing about 50,000 or 200,000 rows, which were not touched.
- Per-statement rows were not obtainable: the measurement keeps the server's `Handler_read_*` of one request, not of each statement. For the four main statements (`s7ff3e12408`, `s24834a57a6`, `s400220f42f`, `s58efd897dc`) the estimate gives 183,172, 169,098, 255,282 and 283,664 rows per request; their sum, 891,216, is almost all of the book page's 891,734. That the route totals agree does not show that the four agree one by one (section 7).
- The EXPLAIN is from a freshly built, analyzed database of the same shape as the lab's, not from the lab's own; the sample of an `ANALYZE TABLE` is of random pages, so the lab's own statistics can differ slightly.
- One request at a time with the limits off: it says nothing about load, and nothing here is a prediction.

## 3. Prediction

What goes in: how the prediction was made and committed before any measurement of the new scenarios, and what it contains.

- the five new scenarios (their row counts and mixes are those of section 7.4.1 of the spec), the ladder, the window of a step and the stopping rule (unchanged from the first round);
- the calibration used (its coefficients, residuals and width), the estimate used, and the commits that fixed the formula and the mark's threshold;
- for each scenario and each step: the utilization at the point and on the safe side, the limit rps (point and safe side), the naive estimate, and the marks;
- the hash and the time of the commit of the prediction file, and the time of the first step measured, side by side.

## 4. Measurement (B and the mark)

What goes in: the two measurements, kept apart.

**B** (the application's time limit lifted, so that the database's limit is what is tested):

- the order of work, and what was built for each row count;
- for every step: the intended and the achieved rps, the database utilization, the application's CPU, the dropped requests and the HTTP statuses, and the three settings in force (the limit of the application's run time, the load tool's time limit for one request, the web server's read limit);
- how each setting was changed and how it was put back, with the values read from the side of the application server and not from the command line;
- the steps that were invalid, and why.

**The mark** (the application's time limit at its default):

- at each of the two row counts, one cold request to each route, without load: the HTTP status and the CPU time of the application's process, route by route;
- how the setting was put back, shown in the same way.

## 5. Verdict

What goes in: the verdict of B by items 1 to 4 of section 7.4 of the spec, at the lowest standard and at the goal, and the verdict of the mark by hits, misses and false alarms (the line for passing is no misses; the false alarms are counted and shown). The table of the marks the prediction gave against the routes that were cut. The verdict is made from the raw records alone, and the order of the prediction's commit and the first step is checked in the history and in the records. If the result is a fail or undecidable, the items that missed are recorded and nothing is repaired in this file.

## 6. Comparison with the naive estimate

What goes in: the naive estimate (spec section 7.3) set beside the simulator on items 1 and 4, step by step and scenario by scenario, as in the first round. Whether the simulator beats it is shown; it is not used for the verdict of item 4.

## 7. What the lab cannot say

What goes in: the limits of the lab, as in section 6 of `lab-backtest.md` (cache behaviour, data that does not fit in memory, contention with other work, write load), and what is specific to this round:

- B was measured with the application's time limit lifted, so B says nothing about the application stopping early;
- the mark is checked one request at a time and says nothing about the application under load;
- the lab's data (for example how many books a shelf holds) is the generator's and may differ from a deployment's.

Already settled, from the records written before the measurements of this round:

- **The estimate assumes a database whose statistics are in order** (analyzed with `ANALYZE TABLE` after the build; D180). It is not claimed for a database with stale or missing statistics. The prediction's output carries the same note.
- **The offsetting of the four main statements cannot be checked.** `s7ff3e12408`, `s24834a57a6`, `s400220f42f` and `s58efd897dc` carry almost all of the book page's rows (their estimated sum is 891,216 of 891,734 at 70,000 rows). The route totals agree with the measurement (1.011), but the measurement holds rows per request and not per statement, so an over-estimate of one statement and an under-estimate of another could cancel without being seen. Showing it would take a per-statement reading, which this round does not have.
- **How far the estimate is carried beyond what was captured.** The capture's largest size is 30,000 rows; the targets are 50,000 rows (about 1.7 times that size) and 200,000 rows (about 6.7 times). The 70,000-row check (about 2.3 times) is the only check of the estimate beyond the capture, and it is one count; the estimate at 200,000 rows is carried further than anything checked.
- **Which routes are marked as changing plan with the row count depends on the smaller size they are compared with.** The comparison is made against a database of one tenth of the target (5,000 rows for 50,000; 20,000 for 200,000). At 50,000 rows the four main statements are marked and the book page's upper side is 4.51 times its point (the ZIP export's 2.85 times); at 200,000 rows none of the four is marked and the upper side is 1.018 times the point for the book page and 1.009 times for the ZIP export (`8f0984c`). So the safe side at 200,000 rows is practically the same as the point. One possible reason, not tested: a plan that has already changed between 5,000 and 30,000 rows shows as a change at 50,000 rows and not at 200,000. The records cannot say which of the two is nearer to the plan of a database of that size. The marks were not adjusted.
- **The upper side is wide.** At 70,000 rows it is about four times the point (book page 4.32, ZIP export 3.79 times the measured rows), so the safe side of B will be much stricter than the point; point and safe side are read apart.

This file does not claim any precision on a real deployment.
