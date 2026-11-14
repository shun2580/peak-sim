# Lab backtest (B), second round: does the repaired simulator predict the lab at row counts it had not seen?

Status: PARTLY WRITTEN. Sections 1, 2 and 3 and the settled items of section 7 are written, from records that were closed before the first step of the new scenarios was measured. Sections 4 to 6 are still an outline: each says only what will go into it, and no figure is written in them; they are written once the measurements are in. Every figure below is copied from a record or from the output of a tool, with the commit or the directory it comes from.

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

### 1.6 The lab's entry: a request is no longer wrapped in a transaction that is rolled back (D187 to D189)

**What was found.** In the first calibration made with the application's time limit lifted, two requests for the same book page sent at the same time waited for one another. The lab's entry script wrapped every request in a transaction and rolled it back after the response, so that no request left a change in the database. The book page updates a row of the view counter (`views`); that update held a row lock until the rollback, which came after the response was sent, so book pages were served one at a time. In the check made then (30,000 rows, two requests at once) the second request of a pair took 5.8 and 5.9 s against 3.3 s alone, and a lock of mode `X,REC_NOT_GAP` on the `views` row was held by one transaction and waited for by the other in 6 of 17 polls (`4385831`). The first run of `cal-6` of that calibration reached 0.398 rps of the 2.2 rps asked, at 7.93% of the database and 18.0% of the application's CPU, every book page lasting about 39 s (`docs/research/lab-data/calibration-limits-off/cal-6-search/`).

**The rule (D187, ruling of 2026-11-15, `e170a30`).** The lab's safety condition 6 (no request leaves a change in the database) is changed **for the lab's entry only**: a request may write the `views` table and nothing else, and the entry takes GET only. The rollback wrapper is removed from the lab's entry. The tool's own measuring mode (`poc/runtime`) keeps its rollback and its condition 6 as they were; a test (`RuntimeKeepsRollbackTest`, 21 cases) binds it so that the loosening does not leave the lab. This was fixed before the calibration was measured again, before the prediction was made, and before any result at the entry was seen.

**Four guards**, each called from the measuring entry points (D188, D189):

1. *Before measuring*, `bin/check-writes` reads the capture's record of statements (made in the capture's own disposable stack; the lab's stacks log no statements, so no measurement is slowed) and refuses to start (exit 15) unless all eight routes are in it and every statement is a `select` or a write to `views` alone (`def9636`).
2. *At the entry*, `EntryGuard` looks at each statement before it is sent (`b4a569f`, `2972c6d`). A write to any table but `views`, an implicit commit, a write on another connection, `SET GLOBAL`, `INTO OUTFILE` and a statement that holds an executable comment (`/*!`, `/*M!`) are not sent: a latch file is left and every later request answers 503 until the lab is rebuilt. Of the writes, only the two forms the application uses (`insert into views`, `update views set`) pass. After each response a second look checks for statements on another schema, implicit commits, writes on another connection and an open transaction.
3. *Around each run*, `bin/check-tables` takes the count and `CHECKSUM TABLE` of the three N tables (`entities`, `images`, `references`) before and after each calibration run and at the start and the end of each ladder scenario, and compares the server's own write counters (insert, update, delete, per table and schema, read from `performance_schema`) with those taken before. Between the steps of a ladder only the write counters are compared (`b90a2ae`, `57ca6a7`), so that the tables are not read again in the middle of a scenario. Any counter that moves for a table other than `views` stops the run (exit 16). `CHECKSUM TABLE` is not run on the 50,000- and 200,000-row databases until a prediction that holds those row counts is committed and passes the prediction gate (exit 17 otherwise).
4. *GET and HEAD only* (405 for anything else), and the check for another schema stays.

Each call is bound by a test that fails when the call is removed. The removals were made on throw-away copies and the failures were seen: 16 variants of the entry (D188) and each of the call lines of the two measuring tools (D189).

**What it cost.** The statement-by-statement check runs in the entry's PHP. Measured in a disposable container with ten statement shapes of the book page, 20,000 times each, twice: `ViewsOnlyWrites::violation` takes 2.3 to 2.6 microseconds a statement, and the entry's existing `QueryCounter::record` takes 3.1 to 3.2 microseconds on the same statements. The load of a request is the number of statements times that; it does not enter the database's CPU time (no statement is added), and it does enter the application's CPU. By that arithmetic, not measured on the route: the book page (47.8 statements at 200,000 rows, `estimate-50k-200k/`) adds about 0.1 ms of check, and the ZIP export (239,996.8 statements) about 0.55 to 0.62 s of PHP CPU time, about 2% of the 30-second limit; the ten shapes are the book page's, so the figure for the ZIP export is an extrapolation. Taking the transaction away also removes one BEGIN and one ROLLBACK round trip per request; the balance of the two was not measured. The stop conditions of a step (database utilization of 80% and the rps) read the database and the rps and are not changed; the application's CPU is recorded for every step, and it includes this check.

**The pass of the entry** (`docs/research/lab-data/guarded-pass-20261115/`, `e1825de`; not a measurement). At 10,000 rows, all the guards exited 0, seven routes answered 200 and `GET /oidc/callback` answered 302 as before, no request answered 503, and the only counter that moved was `views` (updates 1 to 3). At 30,000 rows, `GET /books/1` twice at once took 3,133 and 3,154 ms and 3,185 and 3,174 ms, against 2,994 ms alone; `data_lock_waits` had no row in any of the 17 polls of either pair, and no lock on `views` was held at any poll. In the calibration that followed (section 1.7), the same first try of `cal-6`, at 2.2 rps, went from 0.398 rps achieved at 7.93% of the database to 2.1812 rps at 34.79% (`calibration-r4/cal-6-search/rps-2.2/`). That is a comparison of two records; nothing else was varied on purpose. What this does not show is in section 7.

### 1.7 Recalibration (R4, D190 and D177)

All seven calibration runs were measured again, on 2026-11-15 from 03:16 to 04:58, at the entry of section 1.6, with the application's time limit lifted (PHP's `max_execution_time` 0, php-fpm's `request_terminate_timeout` 0s, the web server's read limit and the load tool's request timeout 600 s). Each run applies a constant arrival rate for 10 minutes and keeps the last 8 minutes. The calibration gate, the write guards and the limits guard passed before every run, and the rules for choosing the rps (D183, D186) and the conditions for stopping were written into the chain of commands before it started. The limits were put back and read back from the server side afterwards (`max_execution_time` 30, `request_terminate_timeout` 0s, web server read limit 120 s; the two files byte-equal to before; `git diff` of `poc/lab/compose/` empty). The records are in `docs/research/lab-data/calibration-r4/` (`14c2630`, decisions `e21fb30`). No prediction was made and the 50,000- and 200,000-row databases were not touched.

| run | rows | mix | rps (intended, achieved) | database utilization (%) | application CPU (%) |
| --- | --- | --- | --- | --- | --- |
| `cal-1` | 10,000 | even | 1, 1.0 | 22.5539 | 10.81 |
| `cal-2` | 10,000 | first 4 routes 80% | 1, 0.9979 | 36.4231 | 17.43 |
| `cal-3` | 10,000 | odd places 80% | 1, 0.9979 | 9.6079 | 4.51 |
| `cal-4` | 3,000 | even | 4, 3.9979 | 27.6452 | 13.96 |
| `cal-5` | 30,000 | even | 0.3, 0.3 | 20.2543 | 9.46 |
| `cal-6` | 30,000 | book page 80%, ZIP export 0% | 1.5, 1.4979 | 22.4894 | 55.08 |
| `cal-7` | 30,000 | book page 80%, ZIP export 0% | 1.5, 1.5 | 21.8128 | 53.72 |

- No request was dropped, every run's rps was valid, there was no 503, and the only status other than 200 was 302 from `GET /oidc/callback` (the redirect a guest gets). The utilizations of `cal-1`, `cal-4`, `cal-5`, `cal-6` and `cal-7` are in the band of 20% to 30% the rules ask for.
- Two searches were made and are **not used in the fit**: `cal-5` at 0.45 rps gave 30.6278% (above the band, so 0.3 was measured) and `cal-6` at 2.2 rps gave 34.7922% with the application at 85.77% (above the band, so 1.5 was measured). The rule that applied was written beforehand.
- Statistics (D181): no `ANALYZE` was run between runs on the same database. The automatic recalculation ran once, on `views`, in the middle of `cal-4`; it was recorded and not stopped.
- The three N tables had the same count and checksum before and after all nine measured runs, and the only table whose write counters moved was `views`.

**The fit.** The same tool and rule as before: two coefficients (`c_query`, `c_row`) by non-negative least squares on the seven runs; the width is the range of the fits that leave one run out. Neither coefficient was cut to zero, in the fit of all seven or in any of the seven that leave one out.

| | value |
| --- | --- |
| `c_query` | 2.84407e-4 |
| `c_row` | 9.37963e-7 |
| largest residual | 0.46 points of utilization (`cal-3`, observed 9.6079, fitted 9.1440) |
| `c_query` over the eight fits | 2.83591e-4 to 2.84861e-4 (a factor of 1.0045) |
| `c_row` over the eight fits | 9.23054e-7 to 9.52874e-7 (a factor of 1.0323) |

The widest change of `c_row` is when `cal-6` or `cal-7` is left out, which is what the two runs of the book-page mix are for. `cal-6` and `cal-7` are two runs of one definition and one rate and differ by 0.68 points of observed utilization; no threshold for that difference was made (D186). The calibration file the prediction reads is `calibration-r4/fit-7runs/calibration.yaml` (SHA-256 `56adb832…6793`).

**Against the 70,000-row records (D177, `cec7fb0`; not a prediction).** The new coefficients applied to the estimate's statements and rows, set beside the database CPU time measured for one cold request at 70,000 rows (median of three):

| route | measured (database CPU s) | converted, point | error | converted from the measured counts, over measured |
| --- | --- | --- | --- | --- |
| `GET /books/{slug}` | 0.7835 | 0.8498 | +8.5% | 1.073 |
| `GET /books/{bookSlug}/export/zip` | 24.076 | 24.442 | +1.5% | 1.015 |

The other six routes read below the measurement floor and no error is made. Errors of the same conversion with the earlier calibrations: the five-run calibration with the limits off (`c_row` cut to 0) gave 0.0187 for the book page and 1.089 for the ZIP export; the first calibration (four runs) gave 2.237 and 1.031. So the book page's error is +8.5% against +123.7% (first calibration) and −98.1% (five-run calibration), and the ZIP export's is +1.5% against +3.1% and +8.9%. **The formula stays at two coefficients** (D176): the error gave no reason to change it, and nothing was changed after the result was seen. What this says is only that, for the two heavy routes at one count, one request at a time and without load, the conversion agreed with the measurement. It does not say that the coefficients hold at a row count or a mix that was not measured (the calibration has runs at 3,000, 10,000 and 30,000 rows, and both runs of the book-page mix are at 30,000), that a cold single request and a request under load are the same thing (not repaired), that the measurement of 70,000 rows was made at the earlier entry, before the guards of section 1.6 (the limits off, with the rollback; it was not measured again), or that a prediction will agree. A good fit of seven runs (residuals of at most 0.46 points) says nothing about runs it was not made on.

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

The prediction was made by the simulator's own parts (`poc/lab/bin/predict-ladder`, which runs the calibration and the prediction of `bin/simulate`), with no separate calculation, and **committed before any step of the new scenarios was measured**. The file is `poc/lab/predictions/ladder-prediction-2.json` with its input `poc/lab/predictions/ladder-prediction-2-input.yaml`. The prediction of the first round (`ladder-prediction.json`) is untouched.

### 3.1 The scenarios, the ladder, the stopping rule

The five scenarios are those of section 7.4.1 of the spec. N_0 = 10,000 rows; the three N tables (`entities`, `images`, `references`) are grown together. The mixes are the ones of the first round (`even`, the first four routes of the list at 80%, the last four at 80%). The multiple of the capture's largest size (30,000 rows) is the distance the estimate is carried (section 7).

| id | rows | mix of routes | multiple of N_0 | multiple of the capture's largest size |
| --- | --- | --- | --- | --- |
| r-1 | 50,000 | even | 5 | 1.667 |
| r-2 | 50,000 | first four routes 80% | 5 | 1.667 |
| r-3 | 200,000 | even | 20 | 6.667 |
| r-4 | 200,000 | first four routes 80% | 20 | 6.667 |
| r-5 | 200,000 | last four routes 80% | 20 | 6.667 |

The ladder, the window of a step and the stopping rule are those of the first round and are not changed: 13 steps of total rps (0.05, 0.07, 0.1, 0.15, 0.2, 0.3, 0.45, 0.7, 1.0, 1.5, 2.2, 3.3, 5.0); a step discards its first minute and keeps a window of the smallest multiple of 40 ÷ rps seconds that is at least 300 seconds; a scenario stops after the first step whose database utilization is 80% or more, or whose intended rps did not come out (outside ±5%). The order of measuring is by row count: 50,000 rows (r-1, then r-2), then 200,000 rows (r-3, r-4, r-5).

### 3.2 What the prediction was made from

| input | what | identity |
| --- | --- | --- |
| calibration | the seven runs `cal-1` to `cal-7` of section 1.7, two coefficients | `calibration-r4/fit-7runs/calibration.yaml`, SHA-256 `56adb832dba42ee9b24fcf22ca716f963e7c25e6e8b287a61f8333b99ea26793` (`14c2630`) |
| row estimate, 200,000 rows (main) | the point of every step, and the marks of the three scenarios at 200,000 rows | `estimate-50k-200k/200k/estimate.json`, SHA-256 `11e673a489ddb2c2b4bb2e1e3d62b79363132823e73f6015204635a14bcc4fa6` |
| row estimate, 50,000 rows (safe side) | the marks and the upper side of the two scenarios at 50,000 rows | `estimate-50k-200k/50k/estimate.json`, SHA-256 `dea43a75222bd01113132dc5552260e4406093f2f1007744de11c2920507d56c` |
| scenario file | the calibration file with the application's limit and the threshold added, and the five prediction scenarios | `ladder-prediction-2-input.yaml`, SHA-256 `eca797e14efcbf66a43b1ff25a3d57698fd73652117ce80c87f9b088553f31e4` |
| naive estimate | the utilization of `cal-2` at 10,000 rows (36.4231% at 1.0 rps in total), scaled by the total rps | `--naive-count=10000`; `cal-1`, `cal-2`, `cal-3` are the candidates and `cal-2` has the largest utilization |
| the application's limit | `resources.app_cpu_time_limit` 30 s; the threshold of the database 0.8 with 2 CPUs | in the scenario file |

- The estimates are those recorded in `8f0984c` (`estimate-50000.json`, `estimate-200000.json`). The prediction tool reads one `estimate.json` per directory, so the two files were copied byte for byte into one directory per count (`de6b17a`; same SHA-256 as the originals, checked with `cmp` and `sha256sum`; the originals are kept). The estimate commit the prediction file records is therefore `de6b17a` and not `8f0984c`; the values, the formulas and the marks are those of `8f0984c`. The estimates assume a database whose statistics are in order (section 1.4), and the prediction file carries that note.
- The tool takes the main estimate at 200,000 rows and the safe-side estimate for each other count. The marks and the upper side of each scenario come from the estimate of its own row count (`estimate_at_count` in the file, for all five scenarios), not from a neighbouring count. The point is one formula; which estimate is called main does not change it.
- The scenario file was made by a script from the calibration file (observed values untouched, compared line by line with the original), adding two lines under `resources:` and the five blocks of `r-1` to `r-5`, whose route shares are those of the first round's `t-2`, `t-3` and `t-5`. The two header lines that said the file had no target were replaced, because they would no longer be true. The ids in the file are exactly `cal-1` to `cal-7` and `r-1` to `r-5` (checked after the file was made), and the ids in the output are exactly `r-1` to `r-5` (checked after the run): the tool accepts an extra id without complaint, so the check was made by eye and by command.
- The prediction was made in a disposable container with no network, from files mounted read only, with the output outside `poc/lab/predictions/`. No lab stack and no database of the lab was used. A second reader checked the inputs, the ids and the tool's output before the commit; the file committed is the one the tool wrote (SHA-256 `24b9280216d7917e3c9613273e8d317493616aa38e5a9998d98923d66c7453ec`), and it was not edited.
- The commands: `poc/lab/bin/predict-ladder --estimate=docs/research/lab-data/estimate-50k-200k/200k --safe-estimate=docs/research/lab-data/estimate-50k-200k/50k --scenario=<scenario file> --out=<output directory> --naive-count=10000 --expect-estimate-commit=de6b17a`, exit 0.

### 3.3 What was fixed before it, in order

| time (2026-11-11 unless stated) | commit | what |
| --- | --- | --- |
| 14:02 | `4bf33f5` (D176) | the formula stays at two coefficients; the rule for comparing formulas was to be fixed before any 70,000-row or new-calibration result |
| 14:08 | `f932a89` (D178), `c9f08de` | the mark `app_limit_risk`: its rule, its factor 9.11 and its implementation |
| 16:29 | `8f0984c` | the row estimates at 50,000 and 200,000 rows |
| 16:30 | `e37e6d4` | the naive estimate is taken at a given N_0 (`--naive-count`) |
| 2026-11-15 01:53 to 03:13 | `e170a30`, `beb5ea8`, `950036b`, `e1825de` | the lab's entry and its four guards (section 1.6; D187 to D189) |
| 2026-11-15 05:00 | `14c2630`, `e21fb30` | the seven calibration runs and the fit (section 1.7; D190) |
| 2026-11-15 05:05 | `cec7fb0` (D177) | the formula stays at two coefficients after the new calibration, with the check at 70,000 rows |
| 2026-11-15 05:09 | `de6b17a` | the copies of the estimates, one directory per count |
| 2026-11-15 05:09 | `9ec3ca8` (D191) | four operating decisions, below |
| **2026-11-15 05:14:41** | **`089d9ad`** | **the prediction file and its input** |

Four operating decisions were recorded in `9ec3ca8` before the prediction was made; none of them changes the prediction, the estimate, the calibration, the formula or a threshold:

1. The estimates are copied into one directory per count (above).
2. The mark's measurement uses the probe tool, which always makes a warm-up request (repetition 0) before the one measured (repetition 1); the tool is not changed. For each route the warm-up and the measured request are both made, the mark is judged on the HTTP status and the application's CPU time of the measured one, and the warm-up's result is kept in the record. The application's limit is 30 s in that measurement, so the warm-up can also be cut; this is written in the record.
3. No comparison of the book page's arrival rate with the lock's capacity is made by step: the lock wait was 0 after the entry was repaired (section 1.6) and a book-page rate of 1.2 rps passed in `cal-6`. This was checked up to 30,000 rows only (section 7).
4. php-fpm's `pm.max_children` stays at 16, as in the first round. A step that stops climbing because the workers are all busy is looked for in the record of each step (the application's CPU and the waits).

### 3.4 The predicted utilization, step by step

Database utilization in percent, at the point estimate, on the safe side, and for the naive estimate, at each of the 13 steps. `†` marks 80% or more (the threshold). These tables are written out by a script from the committed prediction file; no value was typed by hand.

#### r-1: 50,000 rows, even

Limit rps (the total rps at which the utilization reaches 80%): point **0.7079**, safe side **0.6122**, naive **2.1964**.

| total rps | point | safe side | naive |
| --- | --- | --- | --- |
| 0.05 | 5.65 | 6.53 | 1.82 |
| 0.07 | 7.91 | 9.15 | 2.55 |
| 0.1 | 11.30 | 13.07 | 3.64 |
| 0.15 | 16.95 | 19.60 | 5.46 |
| 0.2 | 22.60 | 26.13 | 7.28 |
| 0.3 | 33.90 | 39.20 | 10.93 |
| 0.45 | 50.86 | 58.80 | 16.39 |
| 0.7 | 79.11 | 91.47† | 25.50 |
| 1.0 | 113.01† | 130.67† | 36.42 |
| 1.5 | 169.52† | 196.00† | 54.63 |
| 2.2 | 248.63† | 287.47† | 80.13† |
| 3.3 | 372.95† | 431.21† | 120.20† |
| 5.0 | 565.07† | 653.35† | 182.12† |

The eight fits of the calibration (all seven runs, and each of the seven with one run left out) give a point limit rps from 0.7068 to 0.7098.

#### r-2: 50,000 rows, first four routes 80%

Limit rps (the total rps at which the utilization reaches 80%): point **0.4426**, safe side **0.3828**, naive **2.1964**.

| total rps | point | safe side | naive |
| --- | --- | --- | --- |
| 0.05 | 9.04 | 10.45 | 1.82 |
| 0.07 | 12.65 | 14.63 | 2.55 |
| 0.1 | 18.08 | 20.90 | 3.64 |
| 0.15 | 27.11 | 31.35 | 5.46 |
| 0.2 | 36.15 | 41.80 | 7.28 |
| 0.3 | 54.23 | 62.70 | 10.93 |
| 0.45 | 81.34† | 94.05† | 16.39 |
| 0.7 | 126.53† | 146.30† | 25.50 |
| 1.0 | 180.75† | 209.00† | 36.42 |
| 1.5 | 271.13† | 313.50† | 54.63 |
| 2.2 | 397.65† | 459.80† | 80.13† |
| 3.3 | 596.48† | 689.70† | 120.20† |
| 5.0 | 903.76† | 1045.00† | 182.12† |

The eight fits of the calibration (all seven runs, and each of the seven with one run left out) give a point limit rps from 0.4419 to 0.4438.

#### r-3: 200,000 rows, even

Limit rps (the total rps at which the utilization reaches 80%): point **0.1771**, safe side **0.1769**, naive **2.1964**.

| total rps | point | safe side | naive |
| --- | --- | --- | --- |
| 0.05 | 22.58 | 22.61 | 1.82 |
| 0.07 | 31.62 | 31.66 | 2.55 |
| 0.1 | 45.16 | 45.23 | 3.64 |
| 0.15 | 67.75 | 67.84 | 5.46 |
| 0.2 | 90.33† | 90.45† | 7.28 |
| 0.3 | 135.49† | 135.68† | 10.93 |
| 0.45 | 203.24† | 203.52† | 16.39 |
| 0.7 | 316.15† | 316.59† | 25.50 |
| 1.0 | 451.65† | 452.27† | 36.42 |
| 1.5 | 677.47† | 678.40† | 54.63 |
| 2.2 | 993.62† | 994.98† | 80.13† |
| 3.3 | 1490.43† | 1492.48† | 120.20† |
| 5.0 | 2258.23† | 2261.33† | 182.12† |

The eight fits of the calibration (all seven runs, and each of the seven with one run left out) give a point limit rps from 0.1769 to 0.1776.

#### r-4: 200,000 rows, first four routes 80%

Limit rps (the total rps at which the utilization reaches 80%): point **0.1107**, safe side **0.1106**, naive **2.1964**.

| total rps | point | safe side | naive |
| --- | --- | --- | --- |
| 0.05 | 36.13 | 36.18 | 1.82 |
| 0.07 | 50.58 | 50.65 | 2.55 |
| 0.1 | 72.26 | 72.35 | 3.64 |
| 0.15 | 108.38† | 108.53† | 5.46 |
| 0.2 | 144.51† | 144.70† | 7.28 |
| 0.3 | 216.77† | 217.06† | 10.93 |
| 0.45 | 325.15† | 325.58† | 16.39 |
| 0.7 | 505.79† | 506.47† | 25.50 |
| 1.0 | 722.56† | 723.52† | 36.42 |
| 1.5 | 1083.84† | 1085.28† | 54.63 |
| 2.2 | 1589.64† | 1591.75† | 80.13† |
| 3.3 | 2384.45† | 2387.62† | 120.20† |
| 5.0 | 3612.81† | 3617.61† | 182.12† |

The eight fits of the calibration (all seven runs, and each of the seven with one run left out) give a point limit rps from 0.1106 to 0.1110.

#### r-5: 200,000 rows, last four routes 80%

Limit rps (the total rps at which the utilization reaches 80%): point **0.4427**, safe side **0.4420**, naive **2.1964**.

| total rps | point | safe side | naive |
| --- | --- | --- | --- |
| 0.05 | 9.04 | 9.05 | 1.82 |
| 0.07 | 12.65 | 12.67 | 2.55 |
| 0.1 | 18.07 | 18.10 | 3.64 |
| 0.15 | 27.11 | 27.15 | 5.46 |
| 0.2 | 36.15 | 36.20 | 7.28 |
| 0.3 | 54.22 | 54.30 | 10.93 |
| 0.45 | 81.33† | 81.45† | 16.39 |
| 0.7 | 126.51† | 126.71† | 25.50 |
| 1.0 | 180.73† | 181.01† | 36.42 |
| 1.5 | 271.09† | 271.51† | 54.63 |
| 2.2 | 397.60† | 398.22† | 80.13† |
| 3.3 | 596.41† | 597.33† | 120.20† |
| 5.0 | 903.65† | 905.04† | 182.12† |

The eight fits of the calibration (all seven runs, and each of the seven with one run left out) give a point limit rps from 0.4420 to 0.4439.

### 3.5 The mark `app_limit_risk`

The mark is the same in the scenarios of one row count, because it depends on the database time the model gives to one request of a route and not on the mix. It was checked that the values of r-1 and r-2 are equal, and that those of r-3, r-4 and r-5 are equal. The limit is 30 s and the factor 9.11 (section 1.3). Estimated database time of one request, point and upper side, in seconds; the bound of the application's CPU time is the factor times the larger of the two; the share is that bound divided by the limit.

**50,000 rows (r-1, r-2)**

| route | mark | database time, point (s) | database time, upper side (s) | bound of the application CPU time (s) | share of the limit |
| --- | --- | --- | --- | --- | --- |
| `GET /books/{bookSlug}/chapter/{chapterSlug}/references` | not marked | 0.002485 | 0.002485 | 0.02264 | 0.0007545 |
| `GET /books/{bookSlug}/export/zip` | marked | 17.46 | 18.19 | 165.7 | 5.523 |
| `GET /books/{bookSlug}/page/{pageSlug}/references` | not marked | 0.002493 | 0.002828 | 0.02576 | 0.0008588 |
| `GET /books/{slug}` | not marked | 0.6092 | 2.705 | 24.64 | 0.8215 |
| `GET /books/{slug}/references` | not marked | 0.002195 | 0.002195 | 0.02 | 0.0006666 |
| `GET /images/edit/{id}` | not marked | 0.001893 | 0.001893 | 0.01725 | 0.0005749 |
| `GET /oidc/callback` | not marked | 0 | 0 | 0 | 0 |
| `GET /shelves/{slug}` | not marked | 0.005368 | 0.005372 | 0.04894 | 0.001631 |

**200,000 rows (r-3, r-4, r-5)**

| route | mark | database time, point (s) | database time, upper side (s) | bound of the application CPU time (s) | share of the limit |
| --- | --- | --- | --- | --- | --- |
| `GET /books/{bookSlug}/chapter/{chapterSlug}/references` | not marked | 0.002485 | 0.002485 | 0.02264 | 0.0007545 |
| `GET /books/{bookSlug}/export/zip` | marked | 69.84 | 69.85 | 636.3 | 21.21 |
| `GET /books/{bookSlug}/page/{pageSlug}/references` | not marked | 0.002497 | 0.002497 | 0.02275 | 0.0007582 |
| `GET /books/{slug}` | not marked | 2.413 | 2.495 | 22.72 | 0.7575 |
| `GET /books/{slug}/references` | not marked | 0.002195 | 0.002195 | 0.02 | 0.0006666 |
| `GET /images/edit/{id}` | not marked | 0.001893 | 0.001893 | 0.01725 | 0.0005749 |
| `GET /oidc/callback` | not marked | 0 | 0 | 0 | 0 |
| `GET /shelves/{slug}` | not marked | 0.005382 | 0.00959 | 0.08736 | 0.002912 |

The output carries this note beside the limit rps: `the limit rps counts the marked routes as requests that run to the end; a marked route may be cut by the app's limit first`

The routes the EXPLAIN marks as changing plan or as unstable (the routes whose upper side is used on the safe side, which is a different mark from `app_limit_risk`):

| scenario | routes put at the upper side on the safe side |
| --- | --- |
| r-1 | `GET /books/{bookSlug}/export/zip`, `GET /books/{bookSlug}/page/{pageSlug}/references`, `GET /books/{slug}`, `GET /shelves/{slug}` |
| r-2 | `GET /books/{bookSlug}/export/zip`, `GET /books/{bookSlug}/page/{pageSlug}/references`, `GET /books/{slug}`, `GET /shelves/{slug}` |
| r-3 | `GET /books/{slug}`, `GET /shelves/{slug}` |
| r-4 | `GET /books/{slug}`, `GET /shelves/{slug}` |
| r-5 | `GET /books/{slug}`, `GET /shelves/{slug}` |

### 3.6 The order that makes the test valid

1. The calibration (section 1.7), the estimates (`8f0984c`, copied in `de6b17a`), the formula (D176, D177), the mark's threshold (D178) and the lab's entry (section 1.6) were committed first (section 3.3).
2. The prediction file was made and committed, with the paths named. The commit is the one in the table below.
3. Only then are the new scenarios measured. The measuring entry has a gate: it starts only after confirming that the prediction file is committed in HEAD, that it has no difference in the working tree and that `poc/lab/predictions/` holds no other untracked or changed file. Right after the commit the gate answered `PREDICTION OK` (exit 0) and `git status --short poc/lab/predictions` was empty. For the 50,000- and 200,000-row databases there is a second gate: the entry of the table check refuses them (exit 17, without sending a query) unless the prediction file passes that gate and holds a scenario with that row count (D189). The prediction file of the first round holds 10,000, 30,000 and 100,000 rows only and cannot open it.

After the prediction is committed, the prediction, the estimate, the calibration, the ladder, the scenarios, the threshold of the mark and the rules of the spec are not changed. If a step is later found to be wrong, it is recorded and reported, and not repaired in the prediction. No step of a new scenario was measured before the commit, and `CHECKSUM TABLE` was not run on a database of 50,000 or 200,000 rows before it.

| | |
| --- | --- |
| Commit of the prediction file | `089d9ad9c2cb5cb9f12d7ed746f4104a91b048ec` |
| Commit time | 2026-11-15T05:14:41+09:00 |
| SHA-256 of the prediction file as committed | `24b9280216d7917e3c9613273e8d317493616aa38e5a9998d98923d66c7453ec` |
| SHA-256 of its input as committed | `eca797e14efcbf66a43b1ff25a3d57698fd73652117ce80c87f9b088553f31e4` |
| First step of a new scenario started | to be written from the record of the first step, once it is measured (section 4) |

## 4. Measurement (B and the mark)

To be written after the measurements are in.

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

To be written after the measurements are in.

What goes in: the verdict of B by items 1 to 4 of section 7.4 of the spec, at the lowest standard and at the goal, and the verdict of the mark by hits, misses and false alarms (the line for passing is no misses; the false alarms are counted and shown). The table of the marks the prediction gave against the routes that were cut. The verdict is made from the raw records alone, and the order of the prediction's commit and the first step is checked in the history and in the records. If the result is a fail or undecidable, the items that missed are recorded and nothing is repaired in this file.

## 6. Comparison with the naive estimate

To be written after the measurements are in.

What goes in: the naive estimate (spec section 7.3) set beside the simulator on items 1 and 4, step by step and scenario by scenario, as in the first round. Whether the simulator beats it is shown; it is not used for the verdict of item 4.

## 7. What the lab cannot say

The limits of the lab are those of section 6 of `lab-backtest.md`: the cache behaviour of a real deployment, data that does not fit in the database's memory, and contention with other work on the same database or machine. Write load is different in this round and is stated below: a request now commits an update of the view counter, and nothing else is written. What is specific to this round:

- B was measured with the application's time limit lifted, so B says nothing about the application stopping early;
- the mark is checked one request at a time and says nothing about the application under load;
- the lab's data (for example how many books a shelf holds) is the generator's and may differ from a deployment's.

Settled before the measurements of this round, from the records written before them:

- **The estimate assumes a database whose statistics are in order** (analyzed with `ANALYZE TABLE` after the build; D180). It is not claimed for a database with stale or missing statistics. The prediction's output carries the same note.
- **The offsetting of the four main statements cannot be checked.** `s7ff3e12408`, `s24834a57a6`, `s400220f42f` and `s58efd897dc` carry almost all of the book page's rows (their estimated sum is 891,216 of 891,734 at 70,000 rows). The route totals agree with the measurement (1.011), but the measurement holds rows per request and not per statement, so an over-estimate of one statement and an under-estimate of another could cancel without being seen. Showing it would take a per-statement reading, which this round does not have.
- **How far the estimate is carried beyond what was captured.** The capture's largest size is 30,000 rows; the targets are 50,000 rows (about 1.7 times that size, 1.667) and 200,000 rows (about 6.7 times, 6.667). The 70,000-row check (2.333 times) is the only check of the estimate beyond the capture, and it is one count; the estimate at 200,000 rows is carried further than anything checked. The calibration, too, was measured at 3,000, 10,000 and 30,000 rows only, and its coefficients are used at 50,000 and 200,000; both runs of the book-page mix are at 30,000 rows.
- **Which routes are marked as changing plan with the row count depends on the smaller size they are compared with.** The comparison is made against a database of one tenth of the target (5,000 rows for 50,000; 20,000 for 200,000). At 50,000 rows the four main statements are marked and the book page's upper side is 4.51 times its point (the ZIP export's 2.85 times); at 200,000 rows none of the four is marked and the upper side is 1.018 times the point for the book page and 1.009 times for the ZIP export (`8f0984c`). So the safe side at 200,000 rows is practically the same as the point: in the prediction the safe-side limit rps of r-3 is 0.1769 against a point of 0.1771 (section 3.4). One possible reason, not tested: a plan that has already changed between 5,000 and 30,000 rows shows as a change at 50,000 rows and not at 200,000. The records cannot say which of the two is nearer to the plan of a database of that size. The marks were not adjusted, since adjusting them after seeing the EXPLAIN would be fitting them. At 200,000 rows it is the agreement of the point that is looked at.
- **The upper side is wide at 50,000 and 70,000 rows, and not at 200,000.** At 70,000 rows it is about four times the point (book page 4.32, ZIP export 3.79 times the measured rows); at 50,000 rows the book page's is 4.51 times (the ZIP export's 2.85). At those two counts the safe side of B will be much stricter than the point, and point and safe side are read apart (in the prediction, limit rps 0.6122 against 0.7079 for r-1 and 0.3828 against 0.4426 for r-2). At 200,000 rows the upper side is 1.018 and 1.009 times the point, so the two nearly coincide. How much the upper side moves the utilization also depends on the calibration (section 1.7).
- **The lab's entry is not the application's.** Until this round the lab's entry wrapped each request in a transaction that was rolled back after the response. The transaction kept the lock of the book page's view-counter row until after the response, so book pages were served one at a time (section 1.6). That is a property of the lab's construction and not of BookStack in production. The first calibration with the limits off was affected (its first `cal-6` reached 0.398 rps of the 2.2 asked); the calibration of section 1.7 and the steps of this round are measured at the entry without the transaction. The comparison of the two calibrations is a comparison of two records and not of a single change.
- **The disappearance of the lock wait is seen only up to 30,000 rows, with two requests at once.** The check was two pairs of requests for one book, on a database of 30,000 rows, and polls of the lock tables about every 0.3 s, which cannot see a hold shorter than that; no rate was sent in it. A book-page rate of 1.2 rps passed in `cal-6` (576 requests, all HTTP 200, at 30,000 rows; `calibration-r4/cal-6/record.json`). At 50,000 and 200,000 rows nothing of the kind was checked, because the table check and the measuring entries refuse those databases until the prediction is committed (section 3.6). Whether the lock returns at those counts, or at a higher rate, is not known before the steps are measured.
- **All 6,000 books of the lab's 30,000-row database have the same slug**, so `GET /books/{slug}` always reaches the same book and the same row of `views`. The serialization of book pages came partly from this construction of the data; what would happen with requests for different books is not seen. Whether the databases of 50,000 and 200,000 rows have the same property was not read.
- **The number of php-fpm workers is 16, as in the first round, and was not changed.** A step may stop climbing because all the workers are busy and not because of the database; the application's CPU and the waits recorded for each step are where this is looked for (section 4).
- **The entry carries a statement-by-statement check in PHP.** Its measured cost per statement and the arithmetic for the book page and the ZIP export are in section 1.6. It is in the application's CPU in every step and not in the database's. Taking away the transaction removed one BEGIN and one ROLLBACK a request, and the balance of the two was not measured. The amount depends on the number of statements a request sends, which is large for the ZIP export.
- **The check at 70,000 rows was made at the earlier entry.** Its measurements (limits off, with the rollback, before the guards) were not repeated at the entry of this round, so the counts of statements and rows per request at the present entry were not measured at that count.
- **The view counter is committed and grows.** The lab differs from production in that the requests' increments of `views` stay, so that `views` is not constant between measurements (D187); it is not one of the three N tables, whose counts and checksums are compared before and after, and the estimate does not model its growth.

Items that depend on the measurements (for example steps stopped on the application side, routes the application's limit cut, and the validity of any step) are added after section 5.

This file does not claim any precision on a real deployment.
