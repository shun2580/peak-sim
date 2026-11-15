# Extrapolation test (A), third run: do the estimates predict 300,000 rows?

Status: **SKELETON. No result is in this file.** What is written here does not depend on a result: the purpose, what is tested, the rules, the order of work and the limits. Every place that a result, a commit, a time or a value chosen later has to fill is marked `TBD`. When a `TBD` is filled, the section says where the figure comes from. Nothing in the rules below is changed when the figures are added.

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

1. Check that a 300,000-row database can be built for each target, by the way A builds data (per route, for the verification app and for BookStack). Count the rows; record the time and the size. No request, no `EXPLAIN`, no load. If the build of either target cannot be done, or the total of the per-route builds is expected to pass half a day, the work stops and is reported; no other count is chosen in its place. `TBD` (time and size).
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
| Commit that fixed the estimates and the list of marked routes (step 5) | `TBD` |
| Commit time | `TBD` |
| Files in it | `TBD` |
| Step 6 started, verification app | `TBD` |
| Step 6 started, BookStack | `TBD` |
| Step 7 started | `TBD` |
| Comparison | tools: `extrapolation-data-2/compare_test2.py` (the second run's script) compares the committed estimates with the cold values and gives rule B, rule A, the shares by group, the marked routes and the failed tries; `bin/compare-extrapolation` (`ExtrapolationJudge`) checks the recorded predictions against the cold values at the predicted size and gives the pass, fail or undecidable of the spec's section 7.4 (A). Neither counts a route without a complete estimate in a denominator (D205). Script file of the third run `TBD`; output `TBD` |

The start times are read from the records the runner wrote. The files committed are checked with `cmp` against the files the comparison reads before the comparison is made; nothing in the estimates or the marked list changes after the commit.

### Runs of the measurement

`TBD` (a table as in the second run: step, target, tool commit, time of the run, end; and what differs between tool commits and why it does not change a measured value). A run that stops before it makes a record is listed and nothing from it is used.

Where the numbers come from (kept outside the repository): the records of step 6 for each target (`attempts.jsonl`), the records of step 7 (`attempt.jsonl`, one per route and lap) and the time records. A file lists the SHA-256 of every record file the comparison read. `TBD`.

If a measurement is interrupted (a stop of the host or anything else), the statistics tables, the counts of the N tables and the real values of the limits are read before and after the restart, and the scenario that stopped is measured again from its first route. The interrupted record is kept apart and enters no count.

## 2. Result: share within tolerance

`TBD`. Taken from the output of the comparison; the figures are copied and not rounded again by hand.

### 2.1 Verdict

`TBD`: rule B for the verification app, for BookStack and for both together; queries and rows examined; pass, fail or undecidable. Rule A beside it.

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

Nothing is dropped silently. `TBD`: every route that has an estimate and is not in a denominator, with the reason (the request ran longer than 600 seconds; HTTP 500 in all four tries; no estimate), and every case where a failed try's class name and its detail differ. The way the denominator of the number of queries is read (the sum over the statement types of the route, for every route, as in the second run) is the stricter of two readings; the other reading is shown beside it in the same way as in the second run.

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
- **Peak memory is reported and does not decide the test.** It is the input of the memory part of the mark `app_limit_risk` (`lab-backtest-3.md`, section 1.1); how a route whose peak-memory estimate is outside ±30% is treated there is a decision recorded before the estimates: `TBD` (decision number).
- **The estimate assumes a database whose statistics are in order** (D180). It is not claimed for a database with stale or missing statistics, and the estimate's output says so.
- **The denominator is what could be estimated and measured.** Routes without an estimate (authentication, exceptions, validation errors) are out of the test; routes that failed at 300,000 rows are out of the denominator and are listed in section 5.
- **The check of rows examined against the server's own count** was done on the verification app only in the first run (`docs/research/extrapolation-test.md`, section 5); it is not repeated here.

This file does not claim any precision on a real deployment.

## 7. Conclusion

`TBD`. Facts only, as in the second run: rule B and rule A for each scope, the shares by group, the routes with the largest errors, the marked routes, the routes not measured, the multiple of the capture's largest size (10), and what is left of conditions 1 to 3 of section 0 of the spec, counted as D153 counts them (with the change of D167).

What these results do not show is in the list of limits above.
