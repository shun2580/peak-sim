# Extrapolation test (A), third run: do the estimates predict 300,000 rows?

Status: **No result is in this file.** What is written here does not depend on a result: the purpose, what is tested, the rules, the order of work, the limits, and the facts fixed before the measurement (the way the data was built, the capture, the estimates and the lists of marked routes that were committed, with where each figure comes from). Every place that a result, a time of the measurement or a value chosen later has to fill is marked `TBD`. When a `TBD` is filled, the section says where the figure comes from. Nothing in the rules below is changed when the figures are added.

Result in one line: `TBD` (written after section 2: rule B for the verification app, for BookStack and for the two together, and the multiple of the capture's largest size, which is 10).

This file is the record of the third run of test A of `docs/simulator-spec.md` (version 0.2, sections 7.1, 7.4 and 7.4.2). It says what is done in which order, what is measured, and what the numbers can and cannot support. The pass line, the tolerances and the denominator are those of the earlier runs (D71, D95, D105) and are not moved by this file. It follows `docs/research/extrapolation-test.md` (the first run, 100,000 rows, version 0.1; not rescored, D103) and `docs/research/extrapolation-test-2.md` (the second run, 200,000 rows). The third run of test B and of the mark, at the same row count, is in `docs/research/lab-backtest-3.md`.

## Why this run was made

The second run (200,000 rows) gave **pass** on the verification app (rows examined 21 of 22 inside ±20%) and **fail** on BookStack (22 of 34, 64.71%; 28 were needed); the two applications together gave **fail** (43 of 56, 76.79%; 45 were needed). That result is not moved.

After it, the rule for the estimate was changed in two ways (the spec, section 7.4.2, names them):

- **The tangent rule (D174).** Above the capture's largest size, a per-statement fit whose exponent is above 1 is carried on along its tangent at that size; the curve itself is kept as the upper side.
- **The capture on analyzed databases (D179).** The capture is made on databases that were analyzed after they were built, and its sizes now include 30,000 rows (100, 1,000, 10,000 and 30,000).

D153 says that after the way of making the estimate is changed, the count for condition 1a is made again at the next new-size extrapolation test. The size chosen is 300,000 rows, which none of the work behind the two changes has seen. The project owner ruled on 2026-11-16 00:57 (D194), before any 300,000-row estimate was made, that A, test B and the mark are all run once at 300,000 rows. The way of measuring is fixed in section 7.4.2 of the spec, and the same pass line is used.

## 0. What is tested

For each route, three quantities are estimated at N = 300,000 and the same cold request is then measured at N = 300,000.

- **Number of queries:** the sum over the statement types of the route of the count formula of each type (the statements are captured at small sizes).
- **Rows examined:** the sum over the statement types of per-execution rows (a per-statement fit, H1, with the tangent rule above the capture's largest size) times the count. EXPLAIN is not used for the point; it is used only to mark routes whose plan may change, and to give the upper side of the marked statements (the H2 value).
- **Peak memory:** the route-level fit. Reported; it does not decide the test (see section 6 for how it is used elsewhere).

| Quantity | Lowest standard | Goal (D108) |
| --- | --- | --- |
| Number of queries | ±5% | ±2% |
| Rows examined | ±20% | ±10% |
| Peak memory (reported, does not decide) | ±30% | ±30% |

Relative error is `|estimate − measured| / measured`; when the measured value is 0, the estimate counts as a match if it is below 0.5. A route exactly on the tolerance passes.

**The targets and the way the data is built** are those of section 7.1 of the spec and of the earlier runs: the verification app and BookStack, the routes whose cost could be estimated, the 12 routes whose empty-system request was `not_found` in the denominator (D105), and for each route the table that sets its N grown **for that route alone** (not the way the lab grows eight tables together).

**Capture and estimate.** The statements are captured at **100, 1,000, 10,000 and 30,000 rows**, on databases that are analyzed (`ANALYZE TABLE`) after they are built and before the capture (the same rule as the lab, D179). At 300,000 rows the database is built and analyzed and `EXPLAIN` is run on it, to make the marks; no request is sent to it before the estimates are committed. **The capture's largest size is 30,000 rows and the test size is 300,000 rows: 10 times.** (The second run was 6.667 times.)

**Marked route** (D122): a route with at least one statement marked `plan_changes_at_scale` or `plan_unstable` in the EXPLAIN of the 300,000-row database. The list is made mechanically from the EXPLAIN output and is committed with the estimates; no measured value goes into it.

**Rule B (decides the test):** an unmarked route is compared with its single cold value; a marked route is compared with the largest of all cold values measured at 300,000 rows (the single one plus three more). **Rule A (shown, does not decide):** every route is compared with its single cold value. The point estimate is used in both rules; the upper side is not.

**Pass line** (D71, D95): the share of routes within the lowest standard, counted per quantity, is 80% or more for **both** the number of queries and the rows examined. The result is undecidable, and not a pass, when a quantity has fewer than 5 routes in its denominator or when more than 20% of the routes with an estimate could not be measured. The denominator of a quantity is the routes that have an estimate for it and whose cold request at 300,000 rows was measured.

**Failed cold tries** (D126). Under rule B a marked route is compared with the largest of the cold values that exist. A try that failed (HTTP 422, HTTP 500, an exception) is dropped as a try without a value, and the number of values used (n of 4) is written for every marked route. If all four tries failed, the route is not measured. A try that ended at the 600-second cut of one request is recorded as "not measured, 600 seconds or more" and the route is not dropped from rule B. Failed tries are counted by what the record's detail says, not by the class name the runner gave it, and every case where the two differ is listed. The estimate and the lists are not changed by any of this.

**Shares** are given for all routes, for unmarked routes and for marked routes, each against the lowest standard and against the goal.

**What version 1.0 asks of A** (spec, section 0, condition 1): on a size not yet seen, 80% or more of the unmarked routes inside the goal (queries ±2%, rows ±10%), and 80% or more of all routes inside the lowest standard.

## 1. Procedure and the commits that fixed the estimates

The order is what makes the test valid: the estimates and the list of marked routes are committed before the measurement at 300,000 rows starts. If a request were sent to a 300,000-row database earlier, the test would be void for that target.

1. Check that a 300,000-row database can be built for each target, by the way A builds data (per route, for the verification app and for BookStack). Count the rows; record the time and the size. No request, no `EXPLAIN`, no load. If the build of either target cannot be done, or the total of the per-route builds is expected to pass half a day, the work stops and is reported; no other count is chosen in its place. **Done (D195, 2026-11-16, before any estimate was made): both targets can be built at 300,000 rows.** BookStack, two of the 12 targeted shapes were built as a check: 187 s and 209 s. BookStack, the `global` group: 4,366 s (2,813.1 MiB in `information_schema`, 3,382 MiB on disk). Verification app, the `global` group: 44 s (118.4 MiB). The total of the per-route builds is expected to be about 6,700 to 6,900 s (about 1.9 hours: 4,366 s and 44 s as built, and about 2,250 to 2,510 s for the 12 targeted BookStack shapes, extrapolated from the 2 that were built; the time of the measurements is not in it), far below half a day, so the work did not stop. The first try of the BookStack `global` group was stopped after about 4 minutes, because the build limit of 3,600 s was expected to be reached (the estimate from the 100,000- and 200,000-row builds was about 3,700 to 4,060 s, and 5,400 s on the slow side); the limit was raised to 7,200 s (D200, a change of one constant, made before the estimates) and the group was built again, once, inside the new limit (4,366 s is 61% of it). The build is repeated by the measurement itself, in step 6 and in step 7, so the spread of that time is not known.
2. Capture the statements of every route at 100, 1,000, 10,000 and 30,000 rows, on databases analyzed after the build.
3. At 300,000 rows: `ANALYZE`, then `EXPLAIN` in the states of the statistics of section 4.4 of the spec (no request).
4. Point estimates (H1 with the tangent rule), upper sides for marked statements, the list of marked routes (`bin/estimate`, `bin/marked-routes`).
5. Commit the estimates and the list of marked routes, **together with the prediction of test B and the mark** in one commit (the spec's section 7.4.2 fixes one prediction for all three). What these estimates name (the estimate, the calibration, the threshold of the mark) was committed before.
6. Measure every route cold once at 300,000 rows (same profile as the earlier runs, 600 seconds for one request).
7. Measure the marked routes cold three more times on a 300,000-row database.
8. Compare.

The measurement of A (the entry `poc/lab/bin/measure-extrapolation`) has the same gate as that of B: it starts only when the prediction is in HEAD and the working tree has no difference.

| | |
| --- | --- |
| Commit that fixed the estimates and the list of marked routes (step 5) | `206d1148fce29a8643b50a2bb21d0732b7b2cc03` |
| Commit time | 2026-11-16 08:53:18 +09:00 (after the capture, the `EXPLAIN` and the estimates, which ran 01:23 to 02:41 the same day, and before any request was sent to a 300,000-row database for this test) |
| Files in it | Six files under `poc/lab/predictions/`. For test A, four: `extrapolation-3-fixtures-estimate.json`, `extrapolation-3-fixtures-marked.json`, `extrapolation-3-bookstack-estimate.json`, `extrapolation-3-bookstack-marked.json`. For test B and the mark, two: `ladder-prediction-3.json` and `ladder-prediction-3-input.yaml` (the one prediction of the spec's section 7.4.2) |
| Step 6 started, verification app | `TBD` |
| Step 6 started, BookStack | `TBD` |
| Step 7 started | `TBD` |
| Comparison | tools: `extrapolation-data-2/compare_test2.py` (the second run's script) compares the committed estimates with the cold values and gives rule B, rule A, the shares by group, the marked routes and the failed tries. The pass, fail or undecidable of the spec's section 7.4 (A) is given by rule B of this script (or a verbatim copy of it), with rule A shown beside it, as in the second run (D212). `bin/compare-extrapolation` (`ExtrapolationJudge`) is not used for the verdict: it reads the recorded predictions (`predictions.json`, committed in `05fdea9` under `extrapolation-data-3/`), not the estimates of the row counts that were committed in the commit above. Its output may be shown for reference and is not counted. The BookStack route `POST /books/{bookSlug}/copy` is outside the denominator and outside the count of undecidable routes (D205). The two verification-app routes whose estimate is not `complete` are outside the denominator under R1, which gives the verdict, and R2 (the two inside) is shown beside it (D212, the ruling item; next section). Script file of the third run `TBD`; output `TBD` |

The start times are read from the records the runner wrote. The files committed are checked with `cmp` against the files the comparison reads before the comparison is made; nothing in the estimates or the marked list changes after the commit.

### What was fixed before the measurement

All of this was fixed and recorded (D195 to D205) before the commit of the estimates, and no request was sent to a 300,000-row database for this test before it. Where it comes from: the records of the capture, the `EXPLAIN` and the estimates (kept outside the repository, with the SHA-256 of each file in D202), the committed files of the commit above, and the decisions named.

- **Capture** (a copy of the repository at commit `8f4cd65`, which carries the 7,200 s build limit of D200; only the capture, the `EXPLAIN`, the prediction and the estimate tools were run, not the tools that send the 300,000-row requests). Every database was analyzed (`ANALYZE TABLE`) after it was built and before the capture. Verification app: 24 routes, 100, 1,000, 10,000 and 30,000 rows. BookStack: 36 routes, the same four sizes. The 12 routes whose empty-system request was `not_found` (D105, D124): 10, 100, 1,000, 10,000 and 30,000 rows. The three captures ran at the same time, 2026-11-16 01:23:32 to 02:21:45 (+09:00); the request limit of a capture was 60 s for the verification app and 300 s for BookStack. All three ended with exit code 0.
- **`EXPLAIN`** at 300,000 rows with the small side at the tool's default of 10,000 rows (the output says `small_scale` 10000): verification app 02:06:45 to 02:09:30, the 12 routes 02:06:45 to 02:16:47, BookStack 02:21:55 to 02:41:13, all with exit code 0. The databases of this step have no `global` group; the longest build was 237 s and no build limit was reached.
- **Estimates** (`bin/estimate`, H1 with the tangent rule, without `--axes-only`; run twice and compared with `cmp`, identical for both applications). The point of the per-statement fit is taken at the capture's largest size, so the estimate file says `small_scale` 30000 (the second run's said 10000); that follows from adding the 30,000-row size and changes no rule. The route-level fits that `bin/predict` reads (the number of queries, the rows examined and the peak memory of a route) are those of the second run, copied as they are and not fitted again (D196, D202; SHA-256 prefix `216f2b46435d` for the verification app, `5f409c300b49` for BookStack, the same as in the README of the second run's data). The estimate file has **24 routes for the verification app** (22 `complete`, 2 `partial`) and **36 routes for BookStack** (35 `complete`, 1 not `complete`); the second run's files had 24 and 35, all `complete`. `predictions.json` of each application, which `bin/predict` makes from those fits and which `bin/compare-extrapolation` reads, is not one of the six committed files; its SHA-256 is written in D202 (prefixes `2550bc464b30` and `5753567fbad5`).
- **Routes outside the denominator (D205, D212).** Three routes have an estimate that is not `complete`. All three are outside the denominator and outside the count of undecidable routes (the routes that have an estimate and could not be measured). For the two verification-app routes the owner ruled this before step 6, with no result of A seen (D212, the ruling item): the verdict is given by R1 below, and the count with the two routes inside the denominator (R2) is shown beside it in the same section of the result. D205 was recorded before the commit of the estimates; D212 after it and before any request of step 6.
  - `POST /books/{bookSlug}/copy` (the 36th BookStack route): **outside** the denominator and the undecidable count, as in D124 and D205. Its fit is `insufficient_scales` for all three quantities, as in the second run, where it had no row in the estimate; the estimate is 0.0 for the number of queries and the rows examined and has no peak-memory prediction.
  - `GET /ng/nested` (verification app): **outside the denominator under R1; counted inside under R2, shown beside it**. At 30,000 rows the capture was cut at the 60 s request limit after 105,472 queries; the estimate is made from the sizes that worked and is marked `complete: false`, `status: partial`. It has values: queries 1,200,001, rows examined 2,400,003 (estimated, not measured).
  - `POST /ok/bulk` (verification app): **outside the denominator under R1; counted inside under R2, shown beside it**. At 30,000 rows the capture answered HTTP 500 with MySQL's limit on the number of placeholders (SQLSTATE 1390); the estimate is made from the sizes that worked (two insert statements carry the basis `held_from_largest_scale`) and is marked `complete: false`, `status: partial`. It has values: queries 27,001.64, rows examined 300,002 (estimated, not measured).

  The two ways to count the two verification-app routes. **R1**: as `compare_test2.py` does: `estimate_of` returns no value for a route that is not `complete` (lines 168 to 169), the route goes to `no_estimate` (line 209) and `with_estimate` is `denominator + unmeasured` (line 259), so the route is outside the denominator and outside the undecidable count. **R2**: as the spec's section 7.1 says, "the routes whose cost could be estimated": a route with a value in the estimate is inside the denominator if it is measured, and counts as `unmeasured` (toward the undecidable count) if it is not; this needs a copy of the script whose rule is changed in that one place. The wording does not say whether `complete` is meant, so it does not decide the matter, and the choice changes the number of routes in the denominator. The owner decided it before step 6, with no result of A seen, and it is recorded in D212 (the ruling item): the verdict is given by R1, and R2 is shown beside it in the same section of the result. The peak memory is read from its own status and not from `complete` (lines 165 to 167); it is reported only and does not decide the test, and the two verification-app routes have a peak-memory estimate (`predicted`), so they may appear in that count under either way.

  In the second run (200,000 rows) the capture stopped at 10,000 rows: `GET /ng/nested` was inside the denominator and `POST /ok/bulk` answered 500 and was `unmeasured` (both estimates were `complete`). D205 wrote the other comparison tool as the one that gives the verdict; D212 corrects that and does not rewrite D205.
- **Marked routes** (`bin/marked-routes`, only from the `EXPLAIN` marks): verification app **0 of 24** (24 routes in the `EXPLAIN`); BookStack **22 of 35** (35 routes in the `EXPLAIN`; `GET /oidc/callback` is not in it, as in the second run). The second run had 0 of 24 and 20 of 34. So step 7 (three more cold tries) is run for BookStack only, on the 22 routes of the committed list. These marks are those of test A; the memory part of the mark `app_limit_risk` (`lab-backtest-3.md`) is a different list and is not mixed with them.
- **The committed files** (SHA-256 of the file in the commit; each equals the file the estimate was made into, checked with `cmp`):

| File | Bytes | SHA-256 |
| --- | ---: | --- |
| `extrapolation-3-fixtures-estimate.json` | 41,151 | `5c341692b9a329d195a798293877ac9af76b1a2df8213e439a6db105d55c4185` |
| `extrapolation-3-fixtures-marked.json` | 304 | `6990173cb6e732a73c0257c30cc501d8a7671dcee6b836fc71ed06362ed1091e` |
| `extrapolation-3-bookstack-estimate.json` | 911,865 | `9162945b83ceaa752316054ca3f33d416389ae9d92c944c8d63ac9878414a13a` |
| `extrapolation-3-bookstack-marked.json` | 7,958 | `51ac4bf056a7f1387fffef5607fa4ee6a584bf40354387d38b57add60cf947de` |

### Runs of the measurement

`TBD` (a table as in the second run: step, target, tool commit, time of the run, end; and what differs between tool commits and why it does not change a measured value). A run that stops before it makes a record is listed and nothing from it is used.

Where the numbers come from (kept outside the repository): the records of step 6 for each target (`attempts.jsonl`), the records of step 7 (`attempt.jsonl`, one per route and lap) and the time records. A file lists the SHA-256 of every record file the comparison read. `TBD`.

If a measurement is interrupted (a stop of the host or anything else), the statistics tables, the counts of the N tables and the real values of the limits are read before and after the restart, and the scenario that stopped is measured again from its first route. The interrupted record is kept apart and enters no count.

## 2. Result: share within tolerance

`TBD`. Taken from the output of the comparison; the figures are copied and not rounded again by hand.

### 2.1 Verdict

`TBD`: rule B for the verification app, for BookStack and for both together; queries and rows examined; pass, fail or undecidable. Rule A beside it.

R1 gives the verdict (D212, the ruling item). R2, the count with the two verification-app routes (`GET /ng/nested`, `POST /ok/bulk`) inside the denominator, is shown in the same table or section. The two routes that R1 leaves out are heavy routes that already failed at 30,000 rows in the capture (`GET /ng/nested` was cut at the 60 s limit; `POST /ok/bulk` answered HTTP 500), so R1 is not a count that leaves out an easy case.

### 2.2 Rule B: share within tolerance, by group

`TBD`: all routes, unmarked routes and marked routes, against the lowest standard and against the goal, for queries and for rows examined.

### 2.3 Rule A: share within tolerance, by group

`TBD`.

### 2.4 Rule A and rule B side by side

`TBD`.

### 2.5 Against the second run

`TBD`. The second run's figures (verification app 21 of 22, BookStack 22 of 34, together 43 of 56, rule B, rows examined) beside the third run's, with the note that the two runs are on different sizes and the estimate's rules differ (the tangent rule and the analyzed capture), so the comparison is a comparison of two results and not of one change.

## 3. Routes with the largest error (top 10 per target, rule B)

`TBD`. For each route: the estimate, the measured value, the relative error, whether the route is marked, and the statement that carries most of the rows when it is known.

## 4. Marked routes: the point and the upper side against the cold values

`TBD`. For each marked route: the point, the upper side, the lowest and highest cold value, the number of values used (n of 4), and whether the range [point, upper side] encloses the range of cold values. The tries that failed and the class of each.

## 5. Routes outside the denominator

Nothing is dropped silently. The reason each of the two verification-app routes could not be captured at 30,000 rows is recorded as a result of this run: `GET /ng/nested` was cut at the 60 s limit after 105,472 queries; `POST /ok/bulk` answered HTTP 500 with SQLSTATE 1390 (MySQL's limit on the number of placeholders). `TBD`: every route that has an estimate and is not in a denominator, with the reason (the request ran longer than 600 seconds; HTTP 500 in all four tries; no estimate), and every case where a failed try's class name and its detail differ. The way the denominator of the number of queries is read (the sum over the statement types of the route, for every route, as in the second run) is the stricter of two readings; the other reading is shown beside it in the same way as in the second run.

## 6. What can be said, and what cannot

Written before the result.

What the test is:

- It checks whether the per-statement fit (H1) with the tangent rule, made from small-size captures on analyzed databases, predicts the number of queries, the rows examined and the peak memory of a cold request at 300,000 rows. The estimates and the list of marked routes are committed before the measurement they are compared with (section 1).
- The pass line, the tolerances and the denominator are those of the earlier runs and are not moved by the result.
- **The capture's largest size is 30,000 rows and the test size is 300,000 rows: 10 times.** Agreement or disagreement is the result of carrying the estimate that far, and says nothing about a size beyond 300,000 rows.

What it does not support:

- **Two applications only.** The small verification app written for this project, and BookStack with a data profile written for the test. Nothing here is a statement about other Laravel applications; the third application (Bagisto) is a later step.
- **Synthetic data.** Skew, hot rows and cache behaviour of a real system are not reproduced.
- **A single size, once.** 300,000 rows is a size not seen before this run and is used up by it: it cannot be used again as a test of anything repaired afterwards. It says nothing about 3,000,000 rows.
- **Few repeats.** Unmarked routes are measured once. Marked routes are measured four times, three of them on one database; the four values are not an estimate of the full spread, and a route near a tolerance could fall on either side on another run.
- **Rule B compares marked routes with the heaviest of four values.** That is a decision of the spec (D107, D122) and it is stricter for a marked route that is light on most runs. Rule A is shown beside it for that reason.
- **The marking depends on EXPLAIN of this database.** A route that is not marked is not shown to be stable.
- **The point of rows examined is a fit, not a reading of the plan.** EXPLAIN gives only the marks and the upper side, and the upper side is not part of the pass line.
- **Peak memory is reported and does not decide the test.** It is the input of the memory part of the mark `app_limit_risk` (`lab-backtest-3.md`, section 1.1); how a route whose peak-memory estimate is outside ±30% is treated there is a decision recorded before the estimates, D196 (item 6): the rule that gives a size N from a peak-memory estimate is not changed, and the mark is decided with the same factor whatever the accuracy record says, so the accuracy status (within, outside, not checked) does not enter the mark.
- **The estimate assumes a database whose statistics are in order** (D180). It is not claimed for a database with stale or missing statistics, and the estimate's output says so.
- **The denominator is what could be estimated and measured.** Routes without an estimate (authentication, exceptions, validation errors) are out of the test; routes that failed at 300,000 rows are out of the denominator and are listed in section 5.
- **The check of rows examined against the server's own count** was done on the verification app only in the first run (`docs/research/extrapolation-test.md`, section 5); it is not repeated here.

This file does not claim any precision on a real deployment.

## 7. Conclusion

`TBD`. Facts only, as in the second run: rule B and rule A for each scope, the shares by group, the routes with the largest errors, the marked routes, the routes not measured, the multiple of the capture's largest size (10), and what is left of conditions 1 to 3 of section 0 of the spec, counted as D153 counts them (with the change of D167).

What these results do not show is in the list of limits above.
