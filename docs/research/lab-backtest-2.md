# Lab backtest (B), second round: does the repaired simulator predict the lab at row counts it had not seen?

Status: COMPLETE. Sections 1 to 3 and the first part of section 7 were written from records that were closed before the first step of the new scenarios was measured (section 3.6). Sections 4 to 6 and the last part of section 7 were written after the measurements. Every figure below is copied from a record or from the output of a tool, with the commit or the directory it comes from, or computed from those records by a script outside the repository (the means, ratios and limit rps of sections 5 and 6, which a second, independent calculation from the same records reproduced). Nothing was repaired after the result was seen.

This file is the record of the second round of test B and of the mark `app_limit_risk` (`docs/simulator-spec.md`, version 0.2, sections 6.4, 7.2, 7.4 and 7.4.1). It follows `docs/research/lab-backtest.md` (the first round, which did not pass) and `docs/research/lab-backtest-causes.md` (where the first round's miss came from). It says what was repaired, what was done in which order, what was measured, and what the numbers can and cannot support. The pass lines, the tolerances and the rule for "undecidable" are those of the spec and are not moved by this file.

What is checked is a **check against a busy period reproduced in the lab**: a prediction compared with a measurement made in the lab. Nothing here was observed on a real deployment, and no value measured in production appears.

**Result in brief** (sections 5 and 6 have the figures and the readings behind them):

- **B** (the database's limit, with the application's time limit lifted) **meets the lowest standard on all four items. It misses the goal on item 4 only**: the limit rps was measured for 4 scenarios and the point is within ±10% of the measured in 3 of them (75%; 80% is needed). 4 measured scenarios is exactly the least that does not make B undecidable. The mean difference of utilization is 1.989 points against 31.933 for the naive estimate.
- **The mark `app_limit_risk` fails**: 1 hit (ZIP export, 200,000 rows), 1 miss (the book page at 200,000 rows: HTTP 500 with no mark) and 1 false alarm (ZIP export, 50,000 rows). The line for passing is no misses. The records point to the memory limit, not the run-time limit, as the cause of the miss (an inference, section 5.3); the mark does not look at memory.
- **Nothing was repaired.** Two candidates for what to repair are named in section 5.4; both change the specification.

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
| First step of a new scenario started | 2026-11-15T05:21:46+09:00 (r-1, step 1; `docs/research/lab-data/recheck-ladder-b/r-1-50000/rung-01-0.05/started_at.txt`), 7 minutes 5 seconds after the commit |

## 4. Measurement (B and the mark)

Two measurements, kept apart. **B** was measured with the application's run-time limit lifted, in five ladders (sections 4.1 to 4.4). **The mark** was measured afterwards with the limit back at its default, one cold request per route at each of two row counts (section 4.5). Times are the host's clock on 2026-11-15 unless a date is given. The records are in `docs/research/lab-data/recheck-ladder-b/` and `docs/research/lab-data/recheck-mark/` (committed as `2c5599d`, `d506c0c` and `55803d3` for B, `fa60db8` for the mark at 50,000 rows and `2432522` for the mark at 200,000 rows; the order of events is also in D192 of `docs/decisions.md`). They are kept as the tools wrote them, and no pass or fail is written in them: this file is where the verdict is first written.

### 4.1 The order of work, and what was built

The 50,000- and 200,000-row databases had been built and analyzed before the prediction was committed (section 1.4), and until then were only counted (section 3.6). The `last_update` of their statistics is 2026-11-11 05:17:06 (50,000 rows) and 05:17:08 to 05:17:09 (200,000 rows). No `ANALYZE` was run during any measurement of this section, and the statistics were read before and after every ladder and after every step (`stats-scenarios*.tsv`, `stats-steps*.tsv`: 41 tables in each of the two databases, 82 pairs): **no `last_update` changed in any reading**, so the automatic recalculation (section 1.5) did not run in B.

| time | event |
| --- | --- |
| 05:14:41 | the prediction was committed (`089d9ad`, section 3.6) |
| 05:21:46 to 06:49:12 | r-1 (50,000 rows), 9 steps |
| 06:49:29 | r-2 started. Step 1 was finished. Step 2 (started 07:04:55) was cut at 07:13 when the host stopped (the containers exited with 255; the ladder tool ended with exit 13). This run is kept apart, in `r-2-50000-interrupted-0713/`, and enters no count below |
| 13:05 | restart (`bin/up`, `bin/use-db 50000`). The statistics tables (41), and the count and `CHECKSUM TABLE` of the three N tables, were read before the interrupted r-2 and after the restart and were identical (`after-restart/`) |
| 13:06:52 to 14:26:49 | r-2 measured again from step 1 (8 steps) |
| 14:27:23 to 15:22:50 | r-3 (200,000 rows), 5 steps |
| 15:23:07 to 15:58:52 | r-4 (200,000 rows), 3 steps |
| 15:59:09 to 17:19:11 | r-5 (200,000 rows), 8 steps |
| 17:20 | the settings were put back (section 4.2) |
| 17:20:27 to 17:22:29 | the mark at 50,000 rows |
| 17:23:39 to 17:26:38 | the mark at 200,000 rows |
| 2026-11-16 00:23 | the lab was put back on the 10,000-row database with the limit at 30 s (`check-limits --require=limited` exit 0; `git diff -- poc/lab/compose` empty) |

- **r-1 was measured before the host stopped, and r-2 to r-5 and both marks after the restart**, so those ran on a database that had been restarted cold. The effect of that was not separated. One comparison exists: step 1 of the interrupted r-2 measured 9.53% of database utilization and step 1 of the repeated r-2 measured 9.93% (0.39 points apart; both below 10%, so neither is used in item 2).
- **The mark at 200,000 rows was measured after a pause had been asked for.** The pause was asked for at 17:22 and received at 17:23:05; the switch to the 200,000-row database had been made at 17:23:23, before it was read, and the measurement was made as specified. It was decided at 17:28 to use the record, because it was measured as specified and a second measurement would change the cold state of the database. The only thing that differs from the plan is the time.
- Order by row count was kept (50,000 rows first, then 200,000), and no `ANALYZE` was run between two scenarios on the same database (section 1.5).

### 4.2 The three settings, and how they were changed and put back

In B the three settings of section 7.4.1 of the spec were in force in **every one of the 34 step records** (the 33 steps below and the one step of the interrupted r-2; each record carries them in `settings`, and none differs): PHP's `max_execution_time` 0; php-fpm's `request_terminate_timeout` 0s (it was already 0s); the web server's `fastcgi_read_timeout` 600s; and the load tool's time limit for one request 600s (`REQUEST_TIMEOUT`, which the load tool reported back as 600s). The measuring entry ran `bin/check-limits --require=unlimited` before every step and refused to start a step otherwise; all 35 `check-limits.out` of the ladders (including the two steps of the interrupted r-2) read `LIMITS OK (require unlimited)`, and the load tool of each of the 33 steps ended with exit 0 (the one other exit, 255, is the step cut by the host stop). The values were read from the side of the application server (one request through php-fpm, `nginx -T`, the php-fpm configuration), not from the command line.

- How the limits were lifted: as a difference in the working tree of the two compose files under `poc/lab/compose/` (PHP's `max_execution_time` and the web server's read limit), never committed.
- **`memory_limit` was not touched**: it was 512M before, during and after B (`zz-lab.ini`), as the spec lifts the time limits only. Section 4.4 and section 7 depend on this.
- How they were put back: at 17:20 the two compose files were restored from copies kept outside the repository (`recheck-mark/orig-sha256.txt` and `compose-sha256-after-restore.txt` hold the same two hashes) and `bin/use-db 50000` rebuilt the application and web containers. Read back from the server side with `check-limits --require=limited` (exit 0): `max_execution_time` 30, `request_terminate_timeout` 0s, web server read limit 120s (`recheck-mark/limits-after-restore/`). The same reading was taken again at 17:23:38 on the 200,000-row database (`limits-mark-200000/`), at 17:26:55 (`limits-at-stop/`) and at 2026-11-16 00:23, with the same values; `git diff -- poc/lab/compose` was empty when it was looked at (17:26:55, and 2026-11-16 00:21 and 00:23).

### 4.3 The steps of B

One table per scenario. A step discards its first minute and keeps a window (section 3.1); "requests in window" counts the requests that started in the window, and the statuses are those of the requests of the window. "Valid" means the intended rps came out within ±5% (ratio from 0.95 to 1.05). Database utilization is the database's CPU time over the window against the database's capacity (the threshold of 0.8 with 2 CPUs, section 3.2); application CPU is the application's CPU utilization as the tool records it. Dropped is the load tool's count of iterations it could not start; it was 0 in every step. The three settings are those of section 4.2 in every step.

**r-1: 50,000 rows, even** (`recheck-ladder-b/r-1-50000/`; stopped after step 9: db utilization at or above 80 percent, rps not reached)

| step | intended rps | achieved rps | ratio | valid | database utilization (%) | application CPU (%) | dropped | requests in window | HTTP statuses in window | started to ended |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | 0.05 | 0.0500 | 1.000 | yes | 6.29 | 2.77 | 0 | 40 | 200 ×35, 302 ×5 | 05:21:46 to 05:37:11 |
| 2 | 0.07 | 0.0701 | 1.001 | yes | 8.45 | 3.76 | 0 | 40 | 200 ×35, 302 ×5 | 05:37:12 to 05:48:45 |
| 3 | 0.1 | 0.1000 | 1.000 | yes | 11.66 | 5.32 | 0 | 40 | 200 ×35, 302 ×5 | 05:48:47 to 05:57:31 |
| 4 | 0.15 | 0.1501 | 1.001 | yes | 17.09 | 7.95 | 0 | 80 | 200 ×70, 302 ×10 | 05:57:32 to 06:08:29 |
| 5 | 0.2 | 0.2000 | 1.000 | yes | 22.50 | 10.56 | 0 | 80 | 200 ×70, 302 ×10 | 06:08:31 to 06:17:15 |
| 6 | 0.3 | 0.3000 | 1.000 | yes | 34.16 | 16.18 | 0 | 120 | 200 ×105, 302 ×15 | 06:17:16 to 06:26:00 |
| 7 | 0.45 | 0.4494 | 0.999 | yes | 51.69 | 24.55 | 0 | 160 | 200 ×140, 302 ×20 | 06:26:01 to 06:33:59 |
| 8 | 0.7 | 0.6706 | 0.958 | yes | 77.13 | 36.96 | 0 | 230 | 200 ×200, 302 ×30 | 06:34:01 to 06:41:48 |
| 9 | 1.0 | 0.8406 | 0.841 | **no** | 98.56 | 46.85 | 0 | 269 | 200 ×242, 302 ×27 | 06:41:50 to 06:49:12 |

**r-2: 50,000 rows, first four routes 80%** (`recheck-ladder-b/r-2-50000/`; stopped after step 8: db utilization at or above 80 percent, rps not reached)

| step | intended rps | achieved rps | ratio | valid | database utilization (%) | application CPU (%) | dropped | requests in window | HTTP statuses in window | started to ended |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | 0.05 | 0.0500 | 1.000 | yes | 9.93 | 4.48 | 0 | 40 | 200 ×38, 302 ×2 | 13:06:52 to 13:22:16 |
| 2 | 0.07 | 0.0701 | 1.001 | yes | 12.98 | 5.97 | 0 | 40 | 200 ×38, 302 ×2 | 13:22:18 to 13:33:50 |
| 3 | 0.1 | 0.1000 | 1.000 | yes | 18.10 | 8.44 | 0 | 40 | 200 ×38, 302 ×2 | 13:33:51 to 13:42:35 |
| 4 | 0.15 | 0.1501 | 1.001 | yes | 26.84 | 12.68 | 0 | 80 | 200 ×76, 302 ×4 | 13:42:36 to 13:53:33 |
| 5 | 0.2 | 0.2000 | 1.000 | yes | 36.50 | 17.25 | 0 | 80 | 200 ×76, 302 ×4 | 13:53:34 to 14:02:17 |
| 6 | 0.3 | 0.3000 | 1.000 | yes | 54.85 | 26.04 | 0 | 120 | 200 ×114, 302 ×6 | 14:02:19 to 14:11:02 |
| 7 | 0.45 | 0.4466 | 0.993 | yes | 78.80 | 37.89 | 0 | 159 | 200 ×151, 302 ×8 | 14:11:03 to 14:19:01 |
| 8 | 0.7 | 0.5627 | 0.804 | **no** | 99.63 | 47.76 | 0 | 193 | 200 ×185, 302 ×8 | 14:19:02 to 14:26:49 |

**r-3: 200,000 rows, even** (`recheck-ladder-b/r-3-200000/`; stopped after step 5: db utilization at or above 80 percent)

| step | intended rps | achieved rps | ratio | valid | database utilization (%) | application CPU (%) | dropped | requests in window | HTTP statuses in window | started to ended |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | 0.05 | 0.0488 | 0.975 | yes | 20.29 | 9.86 | 0 | 39 | 200 ×29, 302 ×5, 500 ×5 | 14:27:23 to 14:42:46 |
| 2 | 0.07 | 0.0683 | 0.976 | yes | 31.30 | 14.84 | 0 | 39 | 200 ×29, 302 ×5, 500 ×5 | 14:42:47 to 14:54:20 |
| 3 | 0.1 | 0.0950 | 0.950 | yes | 43.54 | 20.75 | 0 | 38 | 200 ×28, 302 ×5, 500 ×5 | 14:54:21 to 15:03:05 |
| 4 | 0.15 | 0.1463 | 0.976 | yes | 62.15 | 29.91 | 0 | 78 | 200 ×58, 302 ×10, 500 ×10 | 15:03:06 to 15:14:03 |
| 5 | 0.2 | 0.1900 | 0.950 | yes | 81.46 | 40.61 | 0 | 76 | 200 ×56, 302 ×10, 500 ×10 | 15:14:05 to 15:22:50 |

**r-4: 200,000 rows, first four routes 80%** (`recheck-ladder-b/r-4-200000/`; stopped after step 3: rps not reached)

| step | intended rps | achieved rps | ratio | valid | database utilization (%) | application CPU (%) | dropped | requests in window | HTTP statuses in window | started to ended |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | 0.05 | 0.0488 | 0.975 | yes | 39.02 | 18.50 | 0 | 39 | 200 ×29, 302 ×2, 500 ×8 | 15:23:07 to 15:38:31 |
| 2 | 0.07 | 0.0665 | 0.951 | yes | 51.58 | 24.75 | 0 | 38 | 200 ×28, 302 ×2, 500 ×8 | 15:38:33 to 15:50:06 |
| 3 | 0.1 | 0.0925 | 0.925 | **no** | 65.95 | 32.49 | 0 | 37 | 200 ×27, 302 ×2, 500 ×8 | 15:50:08 to 15:58:52 |

**r-5: 200,000 rows, last four routes 80%** (`recheck-ladder-b/r-5-200000/`; stopped after step 8: db utilization at or above 80 percent, rps not reached)

| step | intended rps | achieved rps | ratio | valid | database utilization (%) | application CPU (%) | dropped | requests in window | HTTP statuses in window | started to ended |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | 0.05 | 0.0500 | 1.000 | yes | 9.95 | 4.47 | 0 | 40 | 200 ×30, 302 ×8, 500 ×2 | 15:59:09 to 16:14:33 |
| 2 | 0.07 | 0.0701 | 1.001 | yes | 13.15 | 6.00 | 0 | 40 | 200 ×30, 302 ×8, 500 ×2 | 16:14:34 to 16:26:07 |
| 3 | 0.1 | 0.0975 | 0.975 | yes | 15.97 | 7.53 | 0 | 39 | 200 ×29, 302 ×8, 500 ×2 | 16:26:09 to 16:34:52 |
| 4 | 0.15 | 0.1482 | 0.988 | yes | 24.51 | 11.86 | 0 | 79 | 200 ×59, 302 ×16, 500 ×4 | 16:34:54 to 16:45:51 |
| 5 | 0.2 | 0.1975 | 0.988 | yes | 37.30 | 17.70 | 0 | 79 | 200 ×59, 302 ×16, 500 ×4 | 16:45:53 to 16:54:36 |
| 6 | 0.3 | 0.2950 | 0.983 | yes | 52.52 | 25.31 | 0 | 118 | 200 ×88, 302 ×24, 500 ×6 | 16:54:38 to 17:03:22 |
| 7 | 0.45 | 0.4410 | 0.980 | yes | 70.07 | 34.76 | 0 | 157 | 200 ×117, 302 ×32, 500 ×8 | 17:03:23 to 17:11:21 |
| 8 | 0.7 | 0.6501 | 0.929 | **no** | 92.74 | 46.94 | 0 | 223 | 200 ×166, 302 ×46, 500 ×11 | 17:11:23 to 17:19:11 |

`GET /oidc/callback` answers 302 in every step (the redirect a guest gets) and is not an error. Every scenario stopped by the stopping rule of section 3.1 before the ladder's top; no step was repeated or left out.

**Steps that were not valid** (4 of 33, and why):

- r-1 step 9 (intended 1.0, achieved 0.8406, ratio 0.841; database utilization 98.56%): the rps did not come out while the database was at or above 80%. The scenario stopped.
- r-2 step 8 (intended 0.7, achieved 0.5627, ratio 0.804; 99.63%): the same.
- r-4 step 3 (intended 0.1, achieved 0.0925, ratio 0.925; 65.95%): **the rps did not come out while the database was below 80%**, and the scenario stopped there. The record notes `app_side_stall: the rps did not come out while the DB was under 80%`. The cause was not determined (section 7).
- r-5 step 8 (intended 0.7, achieved 0.6501, ratio 0.929; 92.74%): the same as r-1 step 9.

Two steps of r-3 (3 and 5) have a ratio of exactly 0.950; the spec's "within ±5%" includes the boundary, and the record marks them valid. Section 5.5 shows that reading them the other way changes no verdict.

### 4.4 The HTTP 500 at 200,000 rows

At 200,000 rows **every `GET /books/{slug}` request in every step's window answered HTTP 500**, and no other route did. At 50,000 rows (r-1 and r-2) there was no 500. The application's own log (php-fpm's standard error, copied read-only in `recheck-ladder-b/status500/` after r-3, r-4 and r-5) gives the cause: `Allowed memory size of 536870912 bytes exhausted (tried to allocate 97271808 bytes)` at one line of Laravel's view code (`ManagesLayouts.php`, line 102), which puts the rendered body of the page into its outer frame. `Maximum execution time` appears in the log 0 times, as it should with the time limit lifted. The same failure was seen in the mark's measurement at 200,000 rows without the application's log being kept for it (section 4.5).

| scenario | 500 in the windows (step records) | 500 that started in a discarded first minute | 500 in all (web server's access log) | falls of the memory limit (application's log) |
| --- | --- | --- | --- | --- |
| r-3 | 35 (5, 5, 5, 10, 10) | 5 | 40 | 40 |
| r-4 | 24 (8, 8, 8) | 3 | 27 | 27 |
| r-5 | 39 (2, 2, 2, 4, 4, 6, 8, 11) | 5 | 44 | 45 |

- A fall of the memory limit writes two lines in the application's log (one by PHP, one by Laravel); **falls are counted as the lines by PHP**, as the difference between the cumulative counts of the three copies (40, 67 and 112 after r-3, r-4 and r-5).
- For r-3 and r-4 the number of falls is the number of 500 (one fall for each 500, none without the other). **For r-5 they differ by one**: at the end of step 8 the load tool ended the requests that were still open (HTTP 499 on the web server's side), among them one book page that had been running for 72.9 s; PHP went on and ran out of memory at 17:19:08, after the client had gone. There is no 500 without a fall.
- The access log of the web server is in the lab's output directory and not in the repository; the window counts and the falls are in the repository.

The prediction counts a book page as running to its end. What this does to the measured utilization is in section 7.

### 4.5 The mark

The limit was at its default (`max_execution_time` 30; section 4.2). `request_probe.py run --part=http --reps=1 --require-limits=limited`, per route one warm-up request (repetition 0) and one request (repetition 1), one at a time and without load, on the 50,000-row database from 17:20:27 to 17:22:29 and on the 200,000-row database from 17:23:39 to 17:26:38 (load tool's time limit 180 s). The mark is judged on repetition 1; the warm-up's result is kept beside it (decision D191). No `ANALYZE` was run, and `last_update` was the same before and after. The prediction marked one route at both counts, `GET /books/{bookSlug}/export/zip`, and none of the other seven.

PHP CPU time is that of the application's process for the request; "database CPU" is the database's CPU time net of idle (`net.cpu_net_usec`); seconds to answer are the time until the response ended. A small negative database CPU value is the correction for idle time exceeding the little work the request did.

**50,000 rows** (`recheck-mark/mark-50000/`)

| route | marked | warm-up: HTTP / PHP CPU (s) | request: HTTP / PHP CPU (s) | request: database CPU (s) | request: seconds to answer | request: body (bytes) | cut by the limit |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `GET /books/{bookSlug}/chapter/{chapterSlug}/references` | no | 200 / 0.288 | 200 / 0.030 | 0.003 | 0.3 | 23,577 | no |
| `GET /books/{bookSlug}/export/zip` | yes | 200 / 21.566 | 200 / 21.432 | 17.083 | 37.1 | 278,474 | no |
| `GET /books/{bookSlug}/page/{pageSlug}/references` | no | 200 / 0.028 | 200 / 0.033 | 0.005 | 0.3 | 23,505 | no |
| `GET /books/{slug}` | no | 200 / 5.176 | 200 / 4.348 | 0.546 | 5.2 | 24,329,703 | no |
| `GET /books/{slug}/references` | no | 200 / 0.025 | 200 / 0.024 | 0.035 | 0.3 | 21,082 | no |
| `GET /images/edit/{id}` | no | 200 / 0.022 | 200 / 0.019 | 0.002 | 0.3 | 4,175 | no |
| `GET /oidc/callback` | no | 302 / 0.015 | 302 / 0.013 | -0.001 | 0.3 | 334 | no |
| `GET /shelves/{slug}` | no | 200 / 0.031 | 200 / 0.028 | 0.009 | 0.3 | 33,490 | no |

**200,000 rows** (`recheck-mark/mark-200000/`)

| route | marked | warm-up: HTTP / PHP CPU (s) | request: HTTP / PHP CPU (s) | request: database CPU (s) | request: seconds to answer | request: body (bytes) | cut by the limit |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `GET /books/{bookSlug}/chapter/{chapterSlug}/references` | no | 200 / 0.265 | 200 / 0.031 | 0.003 | 0.3 | 23,577 | no |
| `GET /books/{bookSlug}/export/zip` | yes | 500 / 31.247 | 500 / 30.629 | 24.318 | 52.6 | 15,790 | HTTP 500 |
| `GET /books/{bookSlug}/page/{pageSlug}/references` | no | 200 / 0.034 | 200 / 0.027 | 0.005 | 0.3 | 23,505 | no |
| `GET /books/{slug}` | no | 500 / 18.974 | 500 / 15.265 | 1.098 | 16.6 | 15,934 | HTTP 500 |
| `GET /books/{slug}/references` | no | 200 / 0.034 | 200 / 0.026 | 0.002 | 0.3 | 21,082 | no |
| `GET /images/edit/{id}` | no | 200 / 0.026 | 200 / 0.026 | 0.003 | 0.3 | 4,175 | no |
| `GET /oidc/callback` | no | 302 / 0.023 | 302 / 0.015 | -0.000 | 0.3 | 334 | no |
| `GET /shelves/{slug}` | no | 200 / 0.045 | 200 / 0.029 | 0.008 | 0.3 | 33,490 | no |

At both counts the warm-up and the measured request gave the same status on every route. The ZIP export at 200,000 rows answered 500 after 30.63 s of PHP CPU time, a little over the limit of 30 s, with a short body (15,790 bytes): it was stopped by the limit (no log of the application was kept for this measurement, so that reading is from the CPU time). The book page at 200,000 rows answered 500 after 15.27 s of PHP CPU time, about half the limit, with a short body (15,934 bytes). **No log of the application was kept for the mark's measurement** (the containers were rebuilt afterwards), so the cause of that 500 is not directly observed (section 5.3).

## 5. Verdict

### 5.1 What was checked before the verdict

- **The order.** The prediction was committed at 2026-11-15T05:14:41+09:00 (`089d9ad`). The first step started at 05:21:46. Each of the 34 step records names the same prediction commit and commit time (`prediction` in the record). The two marks were measured at 17:20:27 and 17:23:39.
- **Nothing was changed after the prediction.** `git diff 089d9ad..HEAD` over `poc/` (the tools, the prediction file, the scoring code) and `docs/simulator-spec.md` is empty; the files that changed are this document, `docs/decisions.md` and `docs/research/lab-data/recheck-ladder-b/` and `recheck-mark/` (the records). No rule, threshold, estimate, calibration, formula or mark was touched after the result was seen.
- **From the raw records alone.** Every figure in this section was computed from the step records (`record.json` and `ladder.json`), the mark's `http.jsonl` and the prediction as committed (`git show 089d9ad:poc/lab/predictions/ladder-prediction-2.json`), by a script outside the repository, and set beside a second calculation made independently from the same records; the two agree in every figure. The scoring tool of `poc/lab/score/` was read for its stated reading of the rules (its README was committed before the prediction) and not run.
- **Not undecidable** (spec 7.2): 25 steps are usable in item 2 (valid, and measured between 10% and 90%; the rule asks for 10 or more), and the limit rps was measured for 4 scenarios (the rule asks for 4 or more). **The second number is exactly at the line**: if one more scenario had not been measurable, B would have been undecidable.
- The prediction used is the point (spec 6.3: the pass lines use the predicted value, not its width). The safe side and the naive estimate are shown beside it.

### 5.2 B

Error is predicted minus measured, in points of database utilization. Steps used in item 2 are the valid steps measured between 10% and 90%. "Over 80%" says whether the measured and the predicted point are above 80% (item 3 counts the valid steps where the two agree).

**r-1: 50,000 rows, even**

| step | intended rps | valid | measured (%) | point | safe side | naive | point − measured | used in item 2 | over 80%: measured / point |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | 0.05 | yes | 6.29 | 5.65 | 6.53 | 1.82 | -0.64 | no | no / no |
| 2 | 0.07 | yes | 8.45 | 7.91 | 9.15 | 2.55 | -0.54 | no | no / no |
| 3 | 0.1 | yes | 11.66 | 11.30 | 13.07 | 3.64 | -0.36 | yes | no / no |
| 4 | 0.15 | yes | 17.09 | 16.95 | 19.60 | 5.46 | -0.14 | yes | no / no |
| 5 | 0.2 | yes | 22.50 | 22.60 | 26.13 | 7.28 | +0.10 | yes | no / no |
| 6 | 0.3 | yes | 34.16 | 33.90 | 39.20 | 10.93 | -0.26 | yes | no / no |
| 7 | 0.45 | yes | 51.69 | 50.86 | 58.80 | 16.39 | -0.83 | yes | no / no |
| 8 | 0.7 | yes | 77.13 | 79.11 | 91.47 | 25.50 | +1.98 | yes | no / no |
| 9 | 1.0 | **no** | 98.56 | 113.01 | 130.67 | 36.42 | +14.46 | no | yes / yes |

**r-2: 50,000 rows, first four routes 80%**

| step | intended rps | valid | measured (%) | point | safe side | naive | point − measured | used in item 2 | over 80%: measured / point |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | 0.05 | yes | 9.93 | 9.04 | 10.45 | 1.82 | -0.89 | no | no / no |
| 2 | 0.07 | yes | 12.98 | 12.65 | 14.63 | 2.55 | -0.33 | yes | no / no |
| 3 | 0.1 | yes | 18.10 | 18.08 | 20.90 | 3.64 | -0.03 | yes | no / no |
| 4 | 0.15 | yes | 26.84 | 27.11 | 31.35 | 5.46 | +0.27 | yes | no / no |
| 5 | 0.2 | yes | 36.50 | 36.15 | 41.80 | 7.28 | -0.35 | yes | no / no |
| 6 | 0.3 | yes | 54.85 | 54.23 | 62.70 | 10.93 | -0.62 | yes | no / no |
| 7 | 0.45 | yes | 78.80 | 81.34 | 94.05 | 16.39 | +2.54 | yes | no / yes **(differ)** |
| 8 | 0.7 | **no** | 99.63 | 126.53 | 146.30 | 25.50 | +26.90 | no | yes / yes |

**r-3: 200,000 rows, even**

| step | intended rps | valid | measured (%) | point | safe side | naive | point − measured | used in item 2 | over 80%: measured / point |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | 0.05 | yes | 20.29 | 22.58 | 22.61 | 1.82 | +2.29 | yes | no / no |
| 2 | 0.07 | yes | 31.30 | 31.62 | 31.66 | 2.55 | +0.32 | yes | no / no |
| 3 | 0.1 | yes | 43.54 | 45.16 | 45.23 | 3.64 | +1.63 | yes | no / no |
| 4 | 0.15 | yes | 62.15 | 67.75 | 67.84 | 5.46 | +5.60 | yes | no / no |
| 5 | 0.2 | yes | 81.46 | 90.33 | 90.45 | 7.28 | +8.87 | yes | yes / yes |

**r-4: 200,000 rows, first four routes 80%**

| step | intended rps | valid | measured (%) | point | safe side | naive | point − measured | used in item 2 | over 80%: measured / point |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | 0.05 | yes | 39.02 | 36.13 | 36.18 | 1.82 | -2.90 | yes | no / no |
| 2 | 0.07 | yes | 51.58 | 50.58 | 50.65 | 2.55 | -1.00 | yes | no / no |
| 3 | 0.1 | **no** | 65.95 | 72.26 | 72.35 | 3.64 | +6.31 | no | no / no |

**r-5: 200,000 rows, last four routes 80%**

| step | intended rps | valid | measured (%) | point | safe side | naive | point − measured | used in item 2 | over 80%: measured / point |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | 0.05 | yes | 9.95 | 9.04 | 9.05 | 1.82 | -0.92 | no | no / no |
| 2 | 0.07 | yes | 13.15 | 12.65 | 12.67 | 2.55 | -0.50 | yes | no / no |
| 3 | 0.1 | yes | 15.97 | 18.07 | 18.10 | 3.64 | +2.10 | yes | no / no |
| 4 | 0.15 | yes | 24.51 | 27.11 | 27.15 | 5.46 | +2.60 | yes | no / no |
| 5 | 0.2 | yes | 37.30 | 36.15 | 36.20 | 7.28 | -1.16 | yes | no / no |
| 6 | 0.3 | yes | 52.52 | 54.22 | 54.30 | 10.93 | +1.69 | yes | no / no |
| 7 | 0.45 | yes | 70.07 | 81.33 | 81.45 | 16.39 | +11.25 | yes | no / yes **(differ)** |
| 8 | 0.7 | **no** | 92.74 | 126.51 | 126.71 | 25.50 | +33.77 | no | yes / yes |

**Items 1 and 2: the mean absolute difference, over the 25 steps used** (points of utilization)

| scenario | steps used | point | safe side | naive | signed mean of point − measured |
| --- | --- | --- | --- | --- | --- |
| r-1 | 6 | 0.61 | 5.67 | 24.17 | +0.08 |
| r-2 | 6 | 0.69 | 6.23 | 30.30 | +0.25 |
| r-3 | 5 | 3.74 | 3.81 | 43.59 | +3.74 |
| r-4 | 2 | 1.95 | 1.89 | 43.12 | -1.95 |
| r-5 | 6 | 3.22 | 3.25 | 27.88 | +2.67 |
| **all** | **25** | **1.989** | **4.550** | **31.933** | **+1.31** |

**Item 3: the threshold.** The predicted point and the measurement agree on whether utilization is above 80% in **27 of 29 valid steps (93.1%)**. The two steps that differ are r-2 step 7 (measured 78.80%, predicted 81.34%) and r-5 step 7 (measured 70.07%, predicted 81.33%); both are steps where the prediction was above and the measurement was not. The safe side agrees in 26 of 29 and the naive estimate in 28 of 29; neither is used for the verdict. **Only 1 of the 29 valid steps was measured above 80%** (r-3 step 5, 81.46%), so item 3 hardly separates a prediction that finds the threshold from one that never reaches it (section 6).

**Item 4: the limit rps.** The measured limit rps is found by the rule of spec 7.2: a straight line between the two valid steps that straddle 80%; where the step at or above 80% was not valid, a straight line through the two valid steps with the largest rps, extended to 80% and recorded as extended. The abscissa is the intended rps of the step (the reading of the scoring tool's README).

| scenario | measured limit rps | how, from which steps | point | point ÷ measured | safe side | safe side ÷ measured | naive | naive ÷ measured |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| r-1 | 0.7282 | extended, steps 7 and 8 | 0.7079 | 0.972 | 0.6122 | 0.841 | 2.1964 | 3.02 |
| r-2 | 0.4575 | extended, steps 6 and 7 | 0.4426 | 0.967 | 0.3828 | 0.837 | 2.1964 | 4.80 |
| r-3 | 0.1962 | interpolated, steps 4 and 5 | 0.1771 | 0.903 | 0.1769 | 0.901 | 2.1964 | 11.19 |
| r-4 | **not measured** | steps 1 and 2 are valid but both below 80%; step 3 (65.95%) is not valid, and a step at or above 80% was not measured (`app_bound_below_threshold`) | 0.1107 | — | 0.1106 | — | 2.1964 | — |
| r-5 | 0.5348 | extended, steps 6 and 7 | 0.4427 | 0.828 | 0.4420 | 0.826 | 2.1964 | 4.11 |

- Limit rps measured for **4 scenarios** (r-1, r-2, r-3, r-5). The point is within ±20% of the measured in **4 of 4 (100%)** and within ±10% in **3 of 4 (75%)**. r-5 is the one outside ±10% (0.828 of the measured). The safe side is within ±20% in 4 of 4 and within ±10% in 1 of 4 (r-3, 0.901); the naive estimate in 0 of 4.
- **r-4 was not measured.** The rule extends the line only when the step at or above 80% was not valid, and r-4's invalid step is at 65.95%. The rule has no other way to extend; this was the reading of the rule written before the prediction (the scoring tool's README, committed before the prediction). Only as a reference, and **not a value the rule provides**: extending the line through the two valid steps anyway would give 0.1153 rps and a point ÷ measured of 0.961. It is not used in any count.
- Three of the four measured limits are extended, not interpolated (r-1, r-2, r-5): the step above 80% was not valid and the line is carried from two steps below 80%. Only r-3 is between two measured steps that straddle 80%.
- In every measured scenario the point is below the measured limit (0.828 to 0.972 of it); the error of the limit goes one way.

**The verdict of B**

| item | lowest standard | goal |
| --- | --- | --- |
| 1. the mean difference is smaller than the naive estimate's | **met**: 1.989 against 31.933 points | **met** |
| 2. the mean absolute difference of utilization | **met**: 1.989 points (at most 15) | **met** (at most 5) |
| 3. agreement on "over the threshold" | **met**: 27 of 29 = 93.1% (at least 80%) | **met** |
| 4. the limit rps within ±X% | **met**: ±20%, 4 of 4 = 100% | **not met**: ±10%, 3 of 4 = 75% (80% needed) |

**B meets the lowest standard on all four items (pass). B meets the goal on items 1 to 3 and misses it on item 4 only.** Item 4 at the goal is short by one scenario (4 scenarios measured, 80% of 4 rounded up is 4, 3 are inside ±10%). Spec section 0, condition 1 asks for the lab backtest to pass at the goal: B misses it on item 4 only. In the count made for the conditions for version 1.0 (D153, as changed by D167), item 4 is one scenario short at the goal and none short at the lowest standard.

### 5.3 The mark

By spec 7.4.1: marked and cut is a hit; not marked and cut is a miss; marked and not cut is a false alarm. A route counts as cut if it answered HTTP 500 or was stopped by the limit. The line for passing is no misses; the false alarms are counted and shown.

| route | rows | marked | cut (request) | class | PHP CPU (s) | note |
| --- | --- | --- | --- | --- | --- | --- |
| `GET /books/{bookSlug}/export/zip` | 50,000 | yes | no (HTTP 200, whole body) | **false alarm** | 21.432 | 37.1 s to answer; PHP CPU time did not reach 30 s |
| `GET /books/{bookSlug}/export/zip` | 200,000 | yes | yes (HTTP 500) | **hit** | 30.629 | stopped at the limit |
| `GET /books/{slug}` | 200,000 | no | yes (HTTP 500) | **miss** | 15.265 | about half the limit; not cut by time |
| the other 13 route-and-count pairs | 50,000 and 200,000 | no | no | correctly not marked | 0.013 to 4.348 | |

**Hits 1, misses 1, false alarms 1. The line (no misses) is not met: the mark fails.** The count follows the wording of spec 7.4.1, which names HTTP 500 as a cut and does not limit it by cause; it was not changed after the result was seen, and neither was any reading of it chosen after seeing it (the same wording is applied to the 13 pairs that were not cut).

**The cause of the miss, kept apart from the count** (a reading from other records, not observed in this measurement): the book page was cut at 15.3 s of PHP CPU time, about half the limit, so it was not the run-time limit. In B the same route at the same row count ran out of memory (512M) in every window (section 4.4). The mark's rule (D178) looks at PHP CPU time only and has no input for memory; the tool's memory estimate (spec 6.2) was not checked for this route and was not given for it in the lab's estimate at 200,000 rows. So the miss is a gap in what the mark watches that the measurement brought out. The application's log of the mark's own request was not kept, so that the memory is the cause of that very request is an inference.

**Things the false alarm and the hit show.** The mark's factor 9.11 comes from the book page (section 1.3). For the ZIP export the ratio of PHP CPU time to database CPU time is near 1.25 (1.25 at 50,000 rows, 1.26 at 200,000 rows in a run that was cut), so the bound the mark gives it is about 7.7 times the real one (165.7 s predicted at 50,000 rows against 21.4 s measured); the false alarm was expected from section 1.3. For the book page at 200,000 rows the ratio of PHP CPU time to database CPU time is 13.90 (15.265 s over 1.098 s), above 9.11; but the request ended early with a 500, so the database time is of a partial run, and **this ratio is not a check of the factor**.

### 5.4 What is not repaired, and candidates

Nothing was repaired. The prediction, the estimates, the calibration, the formula, the thresholds, the mark and the pass rules are as they were committed; none was changed after the result was seen. The two items that missed are recorded above: item 4 at the goal (one scenario short) and the mark (one miss). Candidates for what to repair, **not implemented**, each a change of the specification and therefore not made here:

- put a memory limit into the mark's input and rule, so that a route that runs out of memory before it reaches the time limit is marked;
- make the tool's memory estimate (spec 6.2, `peak_memory`) available for the book page, so that a route like it can be warned about from the estimate.

Neither is a test until it is made and checked on counts that were not seen: 50,000 and 200,000 rows are now seen counts and cannot be used again for a test (spec 7.4.1).

### 5.5 Readings of the rules that could have gone another way

Each reading below is fixed by the wording of the spec or the scoring tool's README, both committed before the prediction. The effect of the other reading is shown so that the reader can see how far the verdict depends on it.

| point | reading used | the other reading and its effect |
| --- | --- | --- |
| r-4's limit (item 4) | not measured: the rule extends only when the step at or above 80% was invalid (spec 7.2) | extending anyway would give 4 of 5 inside ±10% (80%) and the goal would be met; but that is a change of the rule, so it is not used (section 5.2) |
| the book page at 200,000 rows (mark) | a miss: spec 7.4.1 counts "HTTP 500 or stopped by the limit" as cut and names no cause | counting only cuts by the run-time limit would give 0 misses and the mark would pass; the wording does not support it, so it is not used |
| the ZIP export at 50,000 rows (mark) | not cut: HTTP 200, whole body, PHP CPU 21.4 s | counting its 37.1 s to answer as "stopped by the limit" would make it a hit; the limit counts CPU time (D178), and the number of misses does not change |
| ratio of exactly 0.950 (r-3 steps 3 and 5) | valid ("within ±5%" includes the boundary) | reading both as not valid: 23 steps used, mean difference 1.706 points, item 3 25 of 27 (92.6%), the limit of r-3 0.1963 (point ÷ measured 0.902); every item keeps its verdict |
| abscissa of the line | the intended rps | using the achieved rps: point ÷ measured 1.018, 0.975, 0.949 and 0.845 for r-1, r-2, r-3 and r-5; ±10% still 3 of 4, ±20% 4 of 4 |
| "over 80%" for a step at exactly 80% | strictly above | there is no step at exactly 80% (the nearest are 78.80% and 81.46%) |
| the warm-up or the measured request (mark) | the measured request (repetition 1), by D191 | the warm-up gives the same class for every route at both counts |

## 6. Comparison with the naive estimate

The naive estimate (spec 7.3) scales the utilization of the largest calibration run at 10,000 rows (`cal-2`, 36.4231% at 1.0 rps in total) by the total rps; it knows nothing of the row count or the mix of routes. It is in the step tables of section 5.2.

**Item 1 and item 2: the simulator beats it at every scenario.** Mean absolute difference over the steps used, in points of utilization:

| scenario | point | naive |
| --- | --- | --- |
| r-1 | 0.61 | 24.17 |
| r-2 | 0.69 | 30.30 |
| r-3 | 3.74 | 43.59 |
| r-4 | 1.95 | 43.12 |
| r-5 | 3.22 | 27.88 |
| all (25 steps) | 1.989 | 31.933 |

The naive estimate is below the measured utilization at **every one** of the 25 steps used (its signed mean is −31.93), because it keeps the 10,000-row load for a 50,000- or 200,000-row database; the simulator's mean is +1.31.

**Item 3.** The naive estimate agrees with the measurement in 28 of 29 valid steps and the simulator in 27 of 29; the naive estimate is not used for the verdict. That is because only one valid step was measured above 80% (r-3 step 5, 81.46%): the naive estimate misses that one (predicted 7.28%) and agrees on the other 28 by never reaching 80% before 2.2 rps; the simulator finds the one step above 80% and is above 80% in two steps where the measurement was not (r-2 step 7, r-5 step 7). **This ladder cannot say much about item 3.**

**Item 4: the limit rps.** The naive estimate gives one number for all five scenarios, 2.1964 rps, which is 3.0 to 11.2 times the measured limit (0.7282, 0.4575, 0.1962, 0.5348 rps; r-4 was not measured) and is outside ±20% in 0 of 4 scenarios; the simulator's point is inside ±20% in 4 of 4. Beating the naive estimate on item 4 is not part of the pass rule (spec 7.4); the figures are shown.

**What this shows and what it does not.** The simulator beats the naive estimate on the mean difference (item 1) and on the limit rps (item 4's figures) in every scenario, by a wide margin; the naive estimate is a poor comparison at these row counts, because the load grows with the rows and it does not. It does not show that the simulator is right on a deployment. The naive estimate's one advantage is item 3, for the reason above.

## 7. What the lab cannot say

The limits of the lab are those of section 6 of `lab-backtest.md`: the cache behaviour of a real deployment, data that does not fit in the database's memory, and contention with other work on the same database or machine. Write load is different in this round and is stated below: a request now commits an update of the view counter, and nothing else is written. What is specific to this round:

- B was measured with the application's time limit lifted (its memory limit was left at 512M, section 4.4), so B says nothing about the application stopping early, and at 200,000 rows the book page did stop early, for lack of memory;
- the mark is checked one request at a time and says nothing about the application under load;
- the lab's data (for example how many books a shelf holds) is the generator's and may differ from a deployment's.

Settled before the measurements of this round, from the records written before them:

- **The estimate assumes a database whose statistics are in order** (analyzed with `ANALYZE TABLE` after the build; D180). It is not claimed for a database with stale or missing statistics. The prediction's output carries the same note.
- **The offsetting of the four main statements cannot be checked.** `s7ff3e12408`, `s24834a57a6`, `s400220f42f` and `s58efd897dc` carry almost all of the book page's rows (their estimated sum is 891,216 of 891,734 at 70,000 rows). The route totals agree with the measurement (1.011), but the measurement holds rows per request and not per statement, so an over-estimate of one statement and an under-estimate of another could cancel without being seen. Showing it would take a per-statement reading, which this round does not have.
- **How far the estimate is carried beyond what was captured.** The capture's largest size is 30,000 rows; the targets are 50,000 rows (about 1.7 times that size, 1.667) and 200,000 rows (about 6.7 times, 6.667). The 70,000-row check (2.333 times) is the only check of the estimate beyond the capture, and it is one count; the estimate at 200,000 rows is carried further than anything checked. The calibration, too, was measured at 3,000, 10,000 and 30,000 rows only, and its coefficients are used at 50,000 and 200,000; both runs of the book-page mix are at 30,000 rows.
- **Which routes are marked as changing plan with the row count depends on the smaller size they are compared with.** The comparison is made against a database of one tenth of the target (5,000 rows for 50,000; 20,000 for 200,000). At 50,000 rows the four main statements are marked and the book page's upper side is 4.51 times its point (the ZIP export's 2.85 times); at 200,000 rows none of the four is marked and the upper side is 1.018 times the point for the book page and 1.009 times for the ZIP export (`8f0984c`). So the safe side at 200,000 rows is practically the same as the point: in the prediction the safe-side limit rps of r-3 is 0.1769 against a point of 0.1771 (section 3.4). One possible reason, not tested: a plan that has already changed between 5,000 and 30,000 rows shows as a change at 50,000 rows and not at 200,000. The records cannot say which of the two is nearer to the plan of a database of that size. The marks were not adjusted, since adjusting them after seeing the EXPLAIN would be fitting them. At 200,000 rows it is the agreement of the point that is looked at.
- **The upper side is wide at 50,000 and 70,000 rows, and not at 200,000.** At 70,000 rows it is about four times the point (book page 4.32, ZIP export 3.79 times the measured rows); at 50,000 rows the book page's is 4.51 times (the ZIP export's 2.85). At those two counts the safe side of B is much stricter than the point, and point and safe side are read apart (in the prediction, limit rps 0.6122 against 0.7079 for r-1 and 0.3828 against 0.4426 for r-2; against the measured limit rps the safe side is 0.841 and 0.837 and the point 0.972 and 0.967, section 5.2). How much stricter depends on the calibration (section 1.7), and at 200,000 rows it does not apply: the upper side is 1.018 and 1.009 times the point, so the two nearly coincide (r-3: 0.901 and 0.903 of the measured).
- **The lab's entry is not the application's.** Until this round the lab's entry wrapped each request in a transaction that was rolled back after the response. The transaction kept the lock of the book page's view-counter row until after the response, so book pages were served one at a time (section 1.6). That is a property of the lab's construction and not of BookStack in production. The first calibration with the limits off was affected (its first `cal-6` reached 0.398 rps of the 2.2 asked); the calibration of section 1.7 and the steps of this round are measured at the entry without the transaction. The comparison of the two calibrations is a comparison of two records and not of a single change.
- **The disappearance of the lock wait is seen only up to 30,000 rows, with two requests at once.** The check was two pairs of requests for one book, on a database of 30,000 rows, and polls of the lock tables about every 0.3 s, which cannot see a hold shorter than that; no rate was sent in it. A book-page rate of 1.2 rps passed in `cal-6` (576 requests, all HTTP 200, at 30,000 rows; `calibration-r4/cal-6/record.json`). At 50,000 and 200,000 rows nothing of the kind was checked, because the table check and the measuring entries refuse those databases until the prediction is committed (section 3.6). The step records of this round do not include polls of the lock tables, so it is still not known whether the lock returned at those counts or at a higher rate; the step where the rps did not come out while the database was below 80% (r-4 step 3) was not examined for it.
- **All 6,000 books of the lab's 30,000-row database have the same slug**, so `GET /books/{slug}` always reaches the same book and the same row of `views`. The serialization of book pages came partly from this construction of the data; what would happen with requests for different books is not seen. Whether the databases of 50,000 and 200,000 rows have the same property was not read.
- **The number of php-fpm workers is 16, as in the first round, and was not changed.** A step may stop climbing because all the workers are busy and not because of the database; the application's CPU and the waits recorded for each step are where this is looked for (section 4). r-4 step 3 is the sign this item names: the rps did not come out while the database was at 65.95% (`app_side_stall` in its record), with the application's CPU at 32.49%. The cause was not determined; the number of busy workers was not recorded.
- **The entry carries a statement-by-statement check in PHP.** Its measured cost per statement and the arithmetic for the book page and the ZIP export are in section 1.6. It is in the application's CPU in every step and not in the database's. Taking away the transaction removed one BEGIN and one ROLLBACK a request, and the balance of the two was not measured. The amount depends on the number of statements a request sends, which is large for the ZIP export.
- **The check at 70,000 rows was made at the earlier entry.** Its measurements (limits off, with the rollback, before the guards) were not repeated at the entry of this round, so the counts of statements and rows per request at the present entry were not measured at that count.
- **The view counter is committed and grows.** The lab differs from production in that the requests' increments of `views` stay, so that `views` is not constant between measurements (D187); it is not one of the three N tables, whose counts and checksums are compared before and after, and the estimate does not model its growth.

What the measurements added (sections 4 to 6):

- **At 200,000 rows B measured a book page that ran out of memory, and the prediction counts it as running to its end.** In every window of r-3, r-4 and r-5 every `GET /books/{slug}` request answered HTTP 500 after running out of its 512M memory limit (section 4.4); the spec lifts the time limits for B and not this one. The measured utilization of those steps therefore lacks the database work the book page would have done after the failure, and the prediction does not. A bound, not a measurement: in the prediction's points the book page is about 3.3% to 3.4% of the predicted database time in each 200,000-row scenario (the book page 2.413 s and the ZIP export 69.84 s a request at equal rates, the other routes negligible; computed from the prediction file, not a measured share), so if all its work were missing the measured utilization would be at most about 3.4% too low and the measured limit rps at most about 3.4% too high. The effect can only reduce the errors found, since the prediction is above the measured utilization and below the measured limit rps at 200,000 rows. If the measured limit had been 3.4% lower, r-5's point ÷ measured would be 0.857 instead of 0.828 (still outside ±10%) and r-3's 0.935 instead of 0.903 (still inside). So **no item's verdict depends on it**, at the lowest standard or at the goal. How much of the work a failing book page does is known from one cold request only: at 200,000 rows the mark's book-page request read 1.098 s of database CPU time before it failed, 0.455 of the 2.413 s predicted for it. That is a request of the mark and not one of the steps.
- **The error is larger at 200,000 rows than at 50,000 rows**, and it is in one direction: the mean absolute difference of utilization is 0.61 and 0.69 points for r-1 and r-2 and 3.74 and 3.22 for r-3 and r-5, with the point above the measured (signed means +0.08 and +0.25 against +3.74 and +2.67); the limit rps of the point is 0.972 and 0.967 of the measured at 50,000 rows and 0.903 and 0.828 at 200,000 rows. This coincides with the failing book page above and with the longer reach of the estimate (6.7 times the capture's largest size against 1.7 times); the two causes were not separated.
- **B's goal on item 4 was missed by one scenario, and the measured limits are mostly extended.** The limit rps was measured for exactly 4 scenarios, the least that does not make B undecidable; r-4 was not measured because its rps stopped coming out at 65.95%, before the database reached 80%. Three of the four measured limits are extended from two steps below 80% (r-1, r-2, r-5), because the step above 80% was not valid; only r-3's is between two measured steps. The extended ones depend on the straight line carried to 80% (the reading of the abscissa changes none of the verdicts, section 5.5).
- **Item 3 hardly separates predictors.** Only one of the 29 valid steps was measured above 80% (r-3 step 5). The naive estimate, which never reaches 80% in the valid steps, agrees in 28 of 29 steps, one more than the simulator (section 6).
- **The ZIP export's planned number of requests was not reached in the windows at 200,000 rows.** In every step of r-3 and r-4 and in most of r-5, the window holds fewer ZIP requests than planned (for example 4 of 5 in r-3 step 1, 5 of 8 in r-4 step 3, `per_route` in the step records), and the other routes hold their planned numbers. A guess, not tested: a ZIP request that lasts about 52 s is not counted when it has not ended by the end of the window. Whether this moved the measured utilization was not examined; the ZIP export is about 97% of the predicted database time at 200,000 rows.
- **r-1 was measured before the host stopped and r-2 to r-5 and both marks after a cold restart** (section 4.1). The effect was not separated; the only comparison is one step of the interrupted r-2 against the same step of the repeated r-2, 0.39 points apart.
- **The mark is one cold request per route at each of two row counts**, without load, and says nothing about the application under load or about a second request to the same route. The application's log was not kept for it, so the cause of the book page's 500 at 200,000 rows is an inference from the CPU time and from B (section 5.3). The mark gave a false alarm for the ZIP export at 50,000 rows because its factor, 9.11, comes from the book page, while the ZIP export's PHP CPU time is about 1.25 times its database time (section 5.3); this was expected (section 1.3).
- **What the mark does not watch.** The mark's rule looks at PHP CPU time against the run-time limit and has no input for the memory limit, so it gives no warning for a route that fails by memory first. This is a gap of the tool that the measurement showed, and not only a limit of the lab (section 5.3).
- **50,000 and 200,000 rows are now seen counts.** They cannot be used again as a test of anything repaired after this round (spec 7.4.1).

This file does not claim any precision on a real deployment.
