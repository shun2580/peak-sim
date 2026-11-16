# Extrapolation test (A), third run: do the estimates predict 300,000 rows?

Status: **The result is written** (sections 2 to 5 and 7), except one line: what is left of conditions 1 to 3 of section 0 of the spec, counted as D153 counts them, is `TBD` (section 7). Sections 0, 1 and 6 were written before the measurement and are not changed by the result; the places in section 1 that a time or a file had to fill are filled, and each says where its figure comes from. Nothing in the rules was changed when the figures were added.

Result in one line (rule B, R1, which gives the verdict; the multiple of the capture's largest size is 10): the verification app **passes** (queries 21 of 21, rows examined 21 of 21 inside the lowest standard); BookStack **fails** (queries 32 of 33, rows examined 24 of 33 = 72.73%, 27 needed); the two applications together **pass** (queries 53 of 54 = 98.15%, rows examined 45 of 54 = 83.33%, 44 needed). Rule A, shown beside it, gives the same except that the two applications together fail (rows examined 42 of 54 = 77.78%). R2 (the two heavy verification-app routes inside the denominator) gives the same verdicts. The pass line, the tolerances and the denominator are those of the earlier runs.

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
| Step 6 started, verification app | 2026-11-16 17:31:13 +09:00 (8 h 38 min after the commit); ended 19:05:22, exit 0 |
| Step 6 started, BookStack | 2026-11-16 19:06:43 +09:00 (first launch, stopped at 20:00:56 before any route was measured; nothing from it is used); started again from the first route 2026-11-16 23:29:36 +09:00 (the run whose records are used); ended 2026-11-17 04:13:17, exit 0 |
| Step 7 started | 2026-11-16 17:57:23 +09:00 (first launch, stopped at 20:00:56 after 11 of 66 trials; nothing from it is used); started again from the first trial 2026-11-16 23:29:56 +09:00 (the run whose records are used); ended 2026-11-17 05:01:30, exit 0 |
| Comparison | tools: `extrapolation-data-2/compare_test2.py` (the second run's script) compares the committed estimates with the cold values and gives rule B, rule A, the shares by group, the marked routes and the failed tries. The pass, fail or undecidable of the spec's section 7.4 (A) is given by rule B of this script (or a verbatim copy of it), with rule A shown beside it, as in the second run (D212). `bin/compare-extrapolation` (`ExtrapolationJudge`) is not used for the verdict: it reads the recorded predictions (`predictions.json`, committed in `05fdea9` under `extrapolation-data-3/`), not the estimates of the row counts that were committed in the commit above. Its output may be shown for reference and is not counted. The BookStack route `POST /books/{bookSlug}/copy` is outside the denominator and outside the count of undecidable routes (D205). The two verification-app routes whose estimate is not `complete` are outside the denominator under R1, which gives the verdict, and R2 (the two inside) is shown beside it (D212, the ruling item; next section). Script file of the third run: `extrapolation-data-2/compare_test2.py` itself (SHA-256 `95da6a9b77bdc5c43a1455c9c1ac9b4f11cb0e966aa1cfeac4e42220e382bb8b`, checked before the run by the wrapper that calls it), run on the four committed estimate and marked files and the records of the measurement, as R1. R2 is a copy with one changed place (a line that lets the two routes through the `complete` test; the difference is in `R2_patch.diff`, the SHA-256 of the copy in `R2_script_sha256.txt`). Output: `extrapolation-data-3/measured/compare/R1/` and `R2/` (`compare.json` and `compare.md` in each), with `input_sha256.txt` listing the record files that were read |

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

| Step | Target | Tool commit | Time of the run | End |
| --- | --- | --- | --- | --- |
| 6 | Verification app, every route cold once | `206d114` | 2026-11-16T17:31:13+09:00 to 19:05:22+09:00 (5,625.9 s) | exit 0; 24 of 24 routes tried |
| 6 | BookStack, first launch | `206d114` | 2026-11-16T19:06:43+09:00 to 20:00:56+09:00 | stopped in the build phase, 0 routes measured; kept apart, nothing from it is used |
| 7 | BookStack, repeat of the marked routes, first launch | `206d114` | 2026-11-16T17:57:23+09:00 to 20:00:56+09:00 | stopped after 11 of 66 trials; kept apart, nothing from it is used |
| 6 | BookStack, every route cold once, from the first route | `206d114` | 2026-11-16T23:29:36+09:00 to 2026-11-17T04:13:17+09:00 (17,001 s) | exit 0; 63 routes tried |
| 7 | BookStack, 22 marked routes, 3 laps, from the first trial | `206d114` | 2026-11-16T23:29:56+09:00 to 2026-11-17T05:01:30+09:00 (19,894 s) | exit 0; 66 trials |

What differs between tool commits: nothing. Every run of step 6 and step 7 used a copy of the repository at the commit that fixed the estimates (`206d114`, the commit of the table above), so the measuring code, the estimates and the marked lists are the same in all of them. The runs after the stop differ from the first launches only in the databases being built again and in the directory the output was written to (a variable of the wrapper script outside the repository; the measuring tool was not changed). The stop at 2026-11-16 20:00 (the session was closed) was made with `compose stop` on the two measurement projects, which ended the interrupted runs; their records are kept outside the repository and enter no count. The host was restarted by hand once afterwards (20:18 to 20:19) with nothing running. The two resumed runs were started 20 seconds apart and ran at the same time (see the paragraph below).

Where the numbers come from (kept outside the repository): the records of step 6 for each target (`attempts.jsonl`), the records of step 7 (`attempt.jsonl`, one per route and lap) and the time records. `extrapolation-data-3/measured/compare/input_sha256.txt` lists the SHA-256 of every record file the comparison read (70 files, paths made relative); the records themselves are in `extrapolation-data-3/measured/` (the verification app's in `fixtures/`, BookStack's in `bookstack/` and `variance/`), and `sha256.txt` there lists each file with its original hash. Three files are not in the repository (the `stderr.log` of the three `GET /books/{slug}` trials of the repeat, 5.5 MB each); their SHA-256 are in `sha256.txt`.

Resumed run (from 2026-11-16T23:29): BookStack, every route cold once, and BookStack, the repeat of the marked routes, ran at the same time on separate databases (the cold-once run ended at 2026-11-17T04:13:17). The two did not overlap at 200,000 rows. The quantities compared are counts and sizes, not times, so the overlap is not expected to change them. The exception is `PUT /books/{slug}`, which was cut at the 600 s request limit at 300,000 rows (it ended with 302 at 200,000 rows); whether it would have ended inside 600 s if run alone cannot be observed. The reading stays as fixed in D126, and no run is repeated in order to change it. The measuring database of the cold-once run took 4,056.73 s to build, as recorded. The preflight check of the statistics, the CHECKSUM and the real limits after the rebuild was not reached: the tool builds and drops its own database, runs no `ANALYZE`, and takes no CHECKSUM, and the limits are the defined values.

If a measurement is interrupted (a stop of the host or anything else), the statistics tables, the counts of the N tables and the real values of the limits are read before and after the restart, and the scenario that stopped is measured again from its first route. The interrupted record is kept apart and enters no count.

## 2. Result: share within tolerance

Source: `extrapolation-data-3/measured/compare/R1/compare.json` and `compare.md` (R1, which gives the verdict) and `R2/compare.json` and `compare.md` (R2, shown beside it), at N = 300,000. The estimates compared are the ones committed at `206d1148fce29a8643b50a2bb21d0732b7b2cc03`. The tables below are copied from `compare.md`; no figure was rounded again by hand.

### 2.1 Verdict

R1 gives the verdict (D212, the ruling item). R2 is shown beside it and does not decide.

| Scope | Rule B (decides), R1 | Rule A (shown), R1 | Rule B, R2 | Rule A, R2 |
| --- | --- | --- | --- | --- |
| Verification app | **pass** | pass | pass | pass |
| BookStack | **fail** | fail | fail | fail |
| Both applications together | **pass** | fail | pass | fail |

Rule B, R1, with the counts that decide:

- Verification app: queries: 21 of 21 inside ±5% (enough), needs 80%; rows_examined: 21 of 21 inside ±20% (enough), needs 80%
- BookStack: queries: 32 of 33 inside ±5% (enough), needs 80%; rows_examined: 24 of 33 inside ±20% (not enough), needs 80%
- Both applications together: queries: 53 of 54 inside ±5% (enough), needs 80%; rows_examined: 45 of 54 inside ±20% (enough), needs 80%

R2 has the same pass or fail, with the same counts of routes inside the lines, for every scope and both rules: the two verification-app routes that R2 adds (`GET /ng/nested`, `POST /ok/bulk`) have no cold value at 300,000 rows (each ran longer than 600 seconds), so they enter no denominator under either reading and change only the columns "estimated but not measured" and "no estimate" (the table below).

R1 leaves out two heavy routes of the verification app, `GET /ng/nested` and `POST /ok/bulk`, whose estimate is not `complete` because they already failed at 30,000 rows in the capture (`GET /ng/nested` was cut at the 60 s limit; `POST /ok/bulk` answered HTTP 500). They are named here so that R1 is not read as a count with the hard cases quietly removed. R2 counts them as routes with an estimate that could not be measured. At 300,000 rows both ran past the 600-second limit (section 5), so the verdicts do not depend on which reading is used.

**The two rules give different verdicts for the two applications together:** rule B passes (rows examined 45 of 54, 83.33%; 44 needed) and rule A fails (42 of 54, 77.78%). The difference is three BookStack routes that rule B moves inside the lowest standard (section 2.4). For the verification app alone, and for BookStack alone, the two rules give the same verdict.

How the verdict is read: pass needs both quantities at 80% or more and no undecidable condition. No undecidable condition was hit in any scope: every denominator has more than 5 routes (the smallest is 21), and the routes with an estimate that could not be measured, for the number of queries and the rows examined, are

- R1: 1 of 22 (verification app), 2 of 35 (BookStack), 3 of 57 (together);
- R2: 3 of 24 (verification app), 2 of 35 (BookStack), 5 of 59 (together);

all below 20% (the largest is 3 of 24, 12.50%, the verification app under R2). The two applications are scored separately as in the earlier runs; the pooled figure is shown beside them. A failing verdict is not repaired here: the pass line, the tolerances, the denominator, the estimates and the list of marked routes are not touched (D71).

### 2.2 Rule B: share within tolerance, by group

R1. "Estimated but not measured" and "No estimate" are the columns of the comparison; under R1 the two routes of section 2.1 are counted in "No estimate" for the number of queries and the rows examined.

| Scope | Group | Quantity | Denominator | Within lowest standard | Share | Within goal | Share | Estimated but not measured | No estimate |
| --- | --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| Verification app | all routes | Number of queries | 21 | 21 | 100.00% | 21 | 100.00% | 1 | 2 |
| Verification app | all routes | Rows examined | 21 | 21 | 100.00% | 21 | 100.00% | 1 | 2 |
| Verification app | all routes | Peak memory (reported only) | 21 | 21 | 100.00% | 21 | 100.00% | 3 | 0 |
| Verification app | unmarked | Number of queries | 21 | 21 | 100.00% | 21 | 100.00% | 1 | 2 |
| Verification app | unmarked | Rows examined | 21 | 21 | 100.00% | 21 | 100.00% | 1 | 2 |
| Verification app | unmarked | Peak memory (reported only) | 21 | 21 | 100.00% | 21 | 100.00% | 3 | 0 |
| Verification app | marked | Number of queries | 0 | 0 | n/a | 0 | n/a | 0 | 0 |
| Verification app | marked | Rows examined | 0 | 0 | n/a | 0 | n/a | 0 | 0 |
| Verification app | marked | Peak memory (reported only) | 0 | 0 | n/a | 0 | n/a | 0 | 0 |
| BookStack | all routes | Number of queries | 33 | 32 | 96.97% | 32 | 96.97% | 2 | 1 |
| BookStack | all routes | Rows examined | 33 | 24 | 72.73% | 15 | 45.45% | 2 | 1 |
| BookStack | all routes | Peak memory (reported only) | 33 | 22 | 66.67% | 22 | 66.67% | 2 | 1 |
| BookStack | unmarked | Number of queries | 13 | 13 | 100.00% | 13 | 100.00% | 1 | 0 |
| BookStack | unmarked | Rows examined | 13 | 13 | 100.00% | 11 | 84.62% | 1 | 0 |
| BookStack | unmarked | Peak memory (reported only) | 13 | 12 | 92.31% | 12 | 92.31% | 1 | 0 |
| BookStack | marked | Number of queries | 20 | 19 | 95.00% | 19 | 95.00% | 1 | 1 |
| BookStack | marked | Rows examined | 20 | 11 | 55.00% | 4 | 20.00% | 1 | 1 |
| BookStack | marked | Peak memory (reported only) | 20 | 10 | 50.00% | 10 | 50.00% | 1 | 1 |
| Both applications together | all routes | Number of queries | 54 | 53 | 98.15% | 53 | 98.15% | 3 | 3 |
| Both applications together | all routes | Rows examined | 54 | 45 | 83.33% | 36 | 66.67% | 3 | 3 |
| Both applications together | all routes | Peak memory (reported only) | 54 | 43 | 79.63% | 43 | 79.63% | 5 | 1 |
| Both applications together | unmarked | Number of queries | 34 | 34 | 100.00% | 34 | 100.00% | 2 | 2 |
| Both applications together | unmarked | Rows examined | 34 | 34 | 100.00% | 32 | 94.12% | 2 | 2 |
| Both applications together | unmarked | Peak memory (reported only) | 34 | 33 | 97.06% | 33 | 97.06% | 4 | 0 |
| Both applications together | marked | Number of queries | 20 | 19 | 95.00% | 19 | 95.00% | 1 | 1 |
| Both applications together | marked | Rows examined | 20 | 11 | 55.00% | 4 | 20.00% | 1 | 1 |
| Both applications together | marked | Peak memory (reported only) | 20 | 10 | 50.00% | 10 | 50.00% | 1 | 1 |

R2 differs from R1 only in two columns, for the number of queries and the rows examined (the verification app and the two applications together; every other cell, and the whole BookStack block, is the same):

| Scope | Group | Estimated but not measured, R1 | No estimate, R1 | Estimated but not measured, R2 | No estimate, R2 |
| --- | --- | ---: | ---: | ---: | ---: |
| Verification app | all routes | 1 | 2 | 3 | 0 |
| Verification app | unmarked | 1 | 2 | 3 | 0 |
| Both applications together | all routes | 3 | 3 | 5 | 1 |
| Both applications together | unmarked | 2 | 2 | 4 | 0 |

### 2.3 Rule A: share within tolerance, by group

Rule A (shown, does not decide), R1:

- Verification app: queries: 21 of 21 inside ±5% (enough), needs 80%; rows_examined: 21 of 21 inside ±20% (enough), needs 80%
- BookStack: queries: 32 of 33 inside ±5% (enough), needs 80%; rows_examined: 21 of 33 inside ±20% (not enough), needs 80%
- Both applications together: queries: 53 of 54 inside ±5% (enough), needs 80%; rows_examined: 42 of 54 inside ±20% (not enough), needs 80%

| Scope | Group | Quantity | Denominator | Within lowest standard | Share | Within goal | Share | Estimated but not measured | No estimate |
| --- | --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| Verification app | all routes | Number of queries | 21 | 21 | 100.00% | 21 | 100.00% | 1 | 2 |
| Verification app | all routes | Rows examined | 21 | 21 | 100.00% | 21 | 100.00% | 1 | 2 |
| Verification app | all routes | Peak memory (reported only) | 21 | 21 | 100.00% | 21 | 100.00% | 3 | 0 |
| Verification app | unmarked | Number of queries | 21 | 21 | 100.00% | 21 | 100.00% | 1 | 2 |
| Verification app | unmarked | Rows examined | 21 | 21 | 100.00% | 21 | 100.00% | 1 | 2 |
| Verification app | unmarked | Peak memory (reported only) | 21 | 21 | 100.00% | 21 | 100.00% | 3 | 0 |
| Verification app | marked | Number of queries | 0 | 0 | n/a | 0 | n/a | 0 | 0 |
| Verification app | marked | Rows examined | 0 | 0 | n/a | 0 | n/a | 0 | 0 |
| Verification app | marked | Peak memory (reported only) | 0 | 0 | n/a | 0 | n/a | 0 | 0 |
| BookStack | all routes | Number of queries | 33 | 32 | 96.97% | 31 | 93.94% | 2 | 1 |
| BookStack | all routes | Rows examined | 33 | 21 | 63.64% | 17 | 51.52% | 2 | 1 |
| BookStack | all routes | Peak memory (reported only) | 33 | 21 | 63.64% | 21 | 63.64% | 2 | 1 |
| BookStack | unmarked | Number of queries | 13 | 13 | 100.00% | 13 | 100.00% | 1 | 0 |
| BookStack | unmarked | Rows examined | 13 | 13 | 100.00% | 11 | 84.62% | 1 | 0 |
| BookStack | unmarked | Peak memory (reported only) | 13 | 12 | 92.31% | 12 | 92.31% | 1 | 0 |
| BookStack | marked | Number of queries | 20 | 19 | 95.00% | 18 | 90.00% | 1 | 1 |
| BookStack | marked | Rows examined | 20 | 8 | 40.00% | 6 | 30.00% | 1 | 1 |
| BookStack | marked | Peak memory (reported only) | 20 | 9 | 45.00% | 9 | 45.00% | 1 | 1 |
| Both applications together | all routes | Number of queries | 54 | 53 | 98.15% | 52 | 96.30% | 3 | 3 |
| Both applications together | all routes | Rows examined | 54 | 42 | 77.78% | 38 | 70.37% | 3 | 3 |
| Both applications together | all routes | Peak memory (reported only) | 54 | 42 | 77.78% | 42 | 77.78% | 5 | 1 |
| Both applications together | unmarked | Number of queries | 34 | 34 | 100.00% | 34 | 100.00% | 2 | 2 |
| Both applications together | unmarked | Rows examined | 34 | 34 | 100.00% | 32 | 94.12% | 2 | 2 |
| Both applications together | unmarked | Peak memory (reported only) | 34 | 33 | 97.06% | 33 | 97.06% | 4 | 0 |
| Both applications together | marked | Number of queries | 20 | 19 | 95.00% | 18 | 90.00% | 1 | 1 |
| Both applications together | marked | Rows examined | 20 | 8 | 40.00% | 6 | 30.00% | 1 | 1 |
| Both applications together | marked | Peak memory (reported only) | 20 | 9 | 45.00% | 9 | 45.00% | 1 | 1 |

### 2.4 Rule A and rule B side by side

Only marked routes can differ between the two rules, and only where the largest of the four cold values is not the single cold value. For rows examined, and for the number of queries, these are the marked BookStack routes (in the denominator) whose largest cold value differs from the single one. Their errors under each rule:

| Route | Quantity | Estimate | Single cold value | Largest cold value | Error against single | Error against largest | Inside lowest standard, rule A | Rule B | Inside goal, rule A | Rule B |
| --- | --- | ---: | ---: | ---: | ---: | ---: | --- | --- | --- | --- |
| `DELETE /books/{bookSlug}/chapter/{chapterSlug}` | rows examined | 2,121,131.8 | 2,550,453 | 2,550,461 | 16.83% | 16.83% | yes | yes | no | no |
| `DELETE /books/{bookSlug}/draft/{pageId}` | rows examined | 2,121,117.1 | 1,560,454 | 2,550,455 | 35.93% | 16.83% | no | yes | no | no |
| `DELETE /books/{bookSlug}/page/{pageSlug}` | rows examined | 2,121,113.4 | 1,560,459 | 2,550,460 | 35.93% | 16.83% | no | yes | no | no |
| `DELETE /books/{id}` | rows examined | 2,121,154.5 | 1,560,500 | 2,550,475 | 35.93% | 16.83% | no | yes | no | no |
| `GET /books/{bookSlug}/chapter/{chapterSlug}` | rows examined | 2,011,475.8 | 1,980,240 | 2,355,230 | 1.58% | 14.60% | yes | yes | yes | no |
| `GET /books/{bookSlug}/page/{pageSlug}` | rows examined | 2,011,437.3 | 1,260,237 | 1,635,227 | 59.61% | 23.01% | no | no | no | no |
| `POST /settings/maintenance/regenerate-references` | rows examined | 494,672,778.3 | 544,458,853 | 571,035,404 | 9.14% | 13.37% | yes | yes | yes | no |
| `POST /settings/maintenance/regenerate-references` | number of queries | 6,068.8 | 5,839 | 5,972 | 3.94% | 1.62% | yes | yes | no | yes |
| `PUT /books/{bookSlug}/chapter/{chapterSlug}` | rows examined | 75,763.8 | 345,232 | 345,240 | 78.05% | 78.05% | no | no | no | no |
| `PUT /books/{bookSlug}/page/{pageSlug}` | rows examined | 75,790.2 | 345,257 | 345,265 | 78.05% | 78.05% | no | no | no | no |
| `PUT /books/{bookSlug}/page/{pageSlug}/revisions/{revId}/restore` | rows examined | 75,787.2 | 345,246 | 345,262 | 78.05% | 78.05% | no | no | no | no |
| `PUT /shelves/{slug}` | rows examined | 315,797.3 | 345,232 | 345,240 | 8.53% | 8.53% | yes | yes | yes | yes |

For rows examined, 11 of the 20 marked BookStack routes in the denominator have a largest cold value that differs from the single one; for the number of queries, 1. Against the lowest standard, 3 routes move from outside to inside under rule B (`DELETE /books/{bookSlug}/draft/{pageId}`, `DELETE /books/{bookSlug}/page/{pageSlug}`, `DELETE /books/{id}`) and 0 move from inside to outside. Against the goal, 0 move from outside to inside and 2 from inside to outside under rule B (`GET /books/{bookSlug}/chapter/{chapterSlug}`, `POST /settings/maintenance/regenerate-references`). For the number of queries, the one route that differs (`POST /settings/maintenance/regenerate-references`, 3.94% against the single value and 1.62% against the largest) is inside the lowest standard under both rules and moves from outside to inside the goal under rule B. The rows examined inside the lowest standard are 24 of 33 under rule B against 21 of 33 under rule A for BookStack, and 45 of 54 against 42 of 54 for the two applications together. The effect of rule B on the verdict is the pass of the two applications together (section 2.1); it does not change the verdict of either application alone.

### 2.5 Against the second run

The second run (200,000 rows, `extrapolation-test-2.md`, rule B) beside this run (300,000 rows, R1, rule B). The two runs are on different sizes and the estimate's rules differ (the tangent rule and the analyzed capture), so this is a comparison of two results and not of one change; the routes in the denominators are not the same set (the second run counted 22, 34 and 56 routes, this run 21, 33 and 54).

| Scope | Quantity | Second run, 200,000 rows | Third run, 300,000 rows |
| --- | --- | --- | --- |
| Verification app | Number of queries, inside the lowest standard | 22 of 22 (100.00%) | 21 of 21 (100.00%) |
| Verification app | Rows examined, inside the lowest standard | 21 of 22 (95.45%) | 21 of 21 (100.00%) |
| BookStack | Number of queries, inside the lowest standard | 32 of 34 (94.12%) | 32 of 33 (96.97%) |
| BookStack | Rows examined, inside the lowest standard | 22 of 34 (64.71%) | 24 of 33 (72.73%) |
| Both applications together | Number of queries, inside the lowest standard | 54 of 56 (96.43%) | 53 of 54 (98.15%) |
| Both applications together | Rows examined, inside the lowest standard | 43 of 56 (76.79%) | 45 of 54 (83.33%) |
| Verification app / BookStack / together | Rule B verdict | pass / fail / fail | pass / fail / pass |
| Verification app / BookStack / together | Rule A verdict | pass / fail / fail | pass / fail / fail |
| Together, unmarked routes | Rows examined, inside the goal | 31 of 37 (83.78%) | 32 of 34 (94.12%) |
| Together, marked routes | Rows examined, inside the lowest standard | 10 of 19 (52.63%) | 11 of 20 (55.00%) |
| BookStack, unmarked routes | Rows examined, inside the lowest standard | 12 of 15 (80.00%) | 13 of 13 (100.00%) |
| BookStack, marked routes | Rows examined, inside the lowest standard | 10 of 19 (52.63%) | 11 of 20 (55.00%) |
| Multiple of the capture's largest size | | 6.667 | 10 |

The second run's figures are copied from section 7 of `extrapolation-test-2.md`. The capture's largest size was 10,000 rows in the second run and is 30,000 rows in this one.

## 3. Routes with the largest error (top 10 per target, rule B)

Ranked by relative error over the entries (route and quantity) in the denominators of the two quantities that decide the test (queries and rows examined). "Estimate" is the committed point estimate (H1 for rows examined); for a marked route "measured" is the largest of its cold values (rule B). The table is copied from `compare.md`. The measurements record no rows examined per statement, so the statement that carries most of the rows is known only on the estimate's side: the last column gives the statement with the largest share of the estimated rows, from the committed estimate file (it says nothing about which statement carries the measured rows).

**Verification app** (42 entries: 21 routes for each of the two quantities)

| # | Route | Quantity | Marked | Estimate | Measured | Relative error | Inside lowest standard | Inside goal | Largest statement of the estimate (share of the estimated rows) |
| ---: | --- | --- | --- | ---: | ---: | ---: | --- | --- | --- |
| 1 | `GET /ok/chunk` | rows examined | no | 441,256,683.7 | 450,453,002 | 2.04% | yes | yes | select, 100.0%: `select * from orders order by orders.id asc limit ? offset ?` |
| 2 | `GET /ok/lazy` | rows examined | no | 441,256,683.7 | 450,453,002 | 2.04% | yes | yes | select, 100.0%: `select * from orders order by orders.id asc limit ? offset ?` |
| 3 | `GET /hidden/appends` | queries | no | 300,001 | 300,001 | 0.00% | yes | yes |  |
| 4 | `GET /hidden/appends` | rows examined | no | 600,003 | 600,003 | 0.00% | yes | yes | select, 50.0%: `select * from orders` |
| 5 | `GET /hidden/blade` | queries | no | 300,001 | 300,001 | 0.00% | yes | yes |  |
| 6 | `GET /hidden/blade` | rows examined | no | 600,003 | 600,003 | 0.00% | yes | yes | select, 50.0%: `select * from orders` |
| 7 | `GET /hidden/cache` | queries | no | 300,001 | 300,001 | 0.00% | yes | yes |  |
| 8 | `GET /hidden/cache` | rows examined | no | 600,003 | 600,003 | 0.00% | yes | yes | select, 50.0%: `select * from orders` |
| 9 | `GET /hidden/conditional-binding` | queries | no | 300,001 | 300,001 | 0.00% | yes | yes |  |
| 10 | `GET /hidden/conditional-binding` | rows examined | no | 600,003 | 600,003 | 0.00% | yes | yes | select, 50.0%: `select * from orders` |

2 entries have a non-zero error (`GET /ok/chunk` rows examined, `GET /ok/lazy` rows examined, 2.04%), and the other 40 have an error of exactly 0, so ranks 3 to 10 are in route order and their order carries no meaning. No entry is outside the lowest standard; both non-zero entries are inside the lowest standard and inside the goal.

**BookStack** (66 entries: 33 routes for each of the two quantities)

| # | Route | Quantity | Marked | Estimate | Measured | Relative error | Inside lowest standard | Inside goal | Largest statement of the estimate (share of the estimated rows) |
| ---: | --- | --- | --- | ---: | ---: | ---: | --- | --- | --- |
| 1 | `GET /shelves/{slug}` | rows examined | yes | 289.0 | 375,226 | 99.92% | no | no | select, 65.7%: `select distinct role_permissions.name as name from role_user as ru left ...` |
| 2 | `PUT /books/{bookSlug}/chapter/{chapterSlug}` | rows examined | yes | 75,763.8 | 345,240 | 78.05% | no | no | delete, 79.2%: `delete from references where from_type = ? and from_id in (?)` |
| 3 | `PUT /books/{bookSlug}/page/{pageSlug}/revisions/{revId}/restore` | rows examined | yes | 75,787.2 | 345,262 | 78.05% | no | no | delete, 79.2%: `delete from references where from_type = ? and from_id in (?)` |
| 4 | `PUT /books/{bookSlug}/page/{pageSlug}` | rows examined | yes | 75,790.2 | 345,265 | 78.05% | no | no | delete, 79.2%: `delete from references where from_type = ? and from_id in (?)` |
| 5 | `GET /books/{slug}/references` | rows examined | yes | 40,810.7 | 105,210 | 61.21% | no | no | select, 99.5%: `select * from references where to_type = ? and to_id = ? and ((reference...` |
| 6 | `GET /shelves/{slug}/references` | rows examined | yes | 40,813.7 | 105,210 | 61.21% | no | no | select, 99.5%: `select * from references where to_type = ? and to_id = ? and ((reference...` |
| 7 | `GET /books/{bookSlug}/chapter/{chapterSlug}/references` | rows examined | yes | 40,817.2 | 105,215 | 61.21% | no | no | select, 99.5%: `select * from references where to_type = ? and to_id = ? and ((reference...` |
| 8 | `GET /books/{bookSlug}/page/{pageSlug}/references` | rows examined | yes | 49,560.5 | 120,219 | 58.77% | no | no | select, 99.6%: `select * from references where to_type = ? and to_id = ? and ((reference...` |
| 9 | `GET /books/{bookSlug}/page/{pageSlug}` | rows examined | yes | 2,011,437.3 | 1,635,227 | 23.01% | no | no | select, 60.8%: `select name, id, slug, book_id, chapter_id, draft, template, text, creat...` |
| 10 | `DELETE /books/{bookSlug}/page/{pageSlug}` | rows examined | yes | 2,121,113.4 | 2,550,460 | 16.83% | yes | no | delete, 43.4%: `delete from deletions where deletions.deletable_type = ? and deletions.d...` |

10 entries are outside the lowest standard under rule B: 9 of rows examined (`GET /shelves/{slug}`, `PUT /books/{bookSlug}/chapter/{chapterSlug}`, `PUT /books/{bookSlug}/page/{pageSlug}/revisions/{revId}/restore`, `PUT /books/{bookSlug}/page/{pageSlug}`, `GET /books/{slug}/references`, `GET /shelves/{slug}/references`, `GET /books/{bookSlug}/chapter/{chapterSlug}/references`, `GET /books/{bookSlug}/page/{pageSlug}/references`, `GET /books/{bookSlug}/page/{pageSlug}`) and 1 of the number of queries (`GET /shelves/{slug}` 5.27%, rank 21). 9 of the 9 misses of rows examined are marked routes. The largest, `GET /shelves/{slug}` (estimate 289.0 against 375,226 measured), is under-estimated by 99.92%; `DELETE /books/{bookSlug}/page/{pageSlug}` (rank 10) is inside the lowest standard and outside the goal.

## 4. Marked routes: the point and the upper side against the cold values

For each marked BookStack route (22 routes; the verification app has none), the H1 point and the upper side (the H2 value of the marked statements) against the smallest and the largest of the cold values measured at 300,000 rows, and whether the point and the upper side are below, inside or above that range. "Cold values used" is the number of values that exist among the four (one in step 6 and three in step 7). The table is copied from `compare.md`; the cold values are given in the order the tries were made (the first is the single value of step 6).

| Route | Point (H1) | Upper side (H2) | Cold min | Cold max | Cold values | Cold values used | Point against the cold range | Upper side against the cold range | Range enclosed by point and upper side |
| --- | ---: | ---: | ---: | ---: | --- | --- | --- | --- | --- |
| `DELETE /books/{bookSlug}/chapter/{chapterSlug}` | 2,121,131.8 | 18,933,014.7 | 1,560,450 | 2,550,461 | 2,550,453, 1,560,450, 1,560,458, 2,550,461 | 4 of 4 | inside | above | no |
| `DELETE /books/{bookSlug}/draft/{pageId}` | 2,121,117.1 | 18,932,860.0 | 1,560,454 | 2,550,455 | 1,560,454, 1,560,454, 2,550,455, 1,560,454 | 4 of 4 | inside | above | no |
| `DELETE /books/{bookSlug}/page/{pageSlug}` | 2,121,113.4 | 18,932,716.4 | 1,560,459 | 2,550,460 | 1,560,459, 1,560,459, 2,550,460, 1,725,512 | 4 of 4 | inside | above | no |
| `DELETE /books/{id}` | 2,121,154.5 | 18,933,177.3 | 1,560,500 | 2,550,475 | 1,560,500, 1,785,503, 1,560,500, 2,550,475 | 4 of 4 | inside | above | no |
| `DELETE /shelves/{slug}` | 2,121,144.3 | 18,933,306.9 | 2,100,450 | 2,550,456 | 2,550,456, 2,100,450, 2,550,456, 2,550,456 | 4 of 4 | inside | above | no |
| `GET /books/{bookSlug}/chapter/{chapterSlug}` | 2,011,475.8 | 2,059,603.2 | 1,980,240 | 2,355,230 | 1,980,240, 1,980,240, 2,355,230, 2,355,230 | 4 of 4 | inside | inside | no |
| `GET /books/{bookSlug}/chapter/{chapterSlug}/references` | 40,817.2 | 2,121,369.4 | 105,215 | 105,215 | 105,215, 105,215, 105,215, 105,215 | 4 of 4 | below | above | yes |
| `GET /books/{bookSlug}/draft/{pageId}` | 211 | 211 | 211 | 211 | 211, 211, 211, 211 | 4 of 4 | inside | inside | yes |
| `GET /books/{bookSlug}/export/zip` | 2,536,922.4 | 5,371,397.7 | 2,505,196 | 2,505,196 | 2,505,196, 2,505,196, 2,505,196, 2,505,196 | 4 of 4 | above | above | no |
| `GET /books/{bookSlug}/page/{pageSlug}` | 2,011,437.3 | 2,057,605.1 | 1,260,237 | 1,635,227 | 1,260,237, 1,260,237, 1,635,227, 1,635,227 | 4 of 4 | above | above | no |
| `GET /books/{bookSlug}/page/{pageSlug}/references` | 49,560.5 | 2,424,365.7 | 120,219 | 120,219 | 120,219, 120,219, 120,219, 120,219 | 4 of 4 | below | above | yes |
| `GET /books/{slug}` | 3,840,199.8 | 3,935,034.4 | n/a | n/a |  | 0 of 4 | n/a | n/a | n/a |
| `GET /books/{slug}/references` | 40,810.7 | 2,550,721.6 | 105,210 | 105,210 | 105,210, 105,210, 105,210, 105,210 | 4 of 4 | below | above | yes |
| `GET /shelves/{slug}` | 289.0 | 1,268.9 | 375,226 | 375,226 | 375,226, 375,226, 375,226, 375,226 | 4 of 4 | below | below | no |
| `GET /shelves/{slug}/references` | 40,813.7 | 2,316,459.7 | 105,210 | 105,210 | 105,210, 105,210, 105,210, 105,210 | 4 of 4 | below | above | yes |
| `POST /books/{bookSlug}/copy` | n/a | 0 | n/a | n/a |  | 0 of 4 | n/a | n/a | n/a |
| `POST /settings/maintenance/regenerate-references` | 494,672,778.3 | 1,494,011,475.6 | 544,067,903 | 571,035,404 | 544,458,853, 550,761,653, 544,067,903, 571,035,404 | 4 of 4 | below | above | yes |
| `POST /settings/recycle-bin/empty` | 2,121,083.7 | 18,932,546.8 | 1,560,467 | 2,325,466 | 2,325,466, 1,560,467, 1,785,469, 1,785,469 | 4 of 4 | inside | above | no |
| `PUT /books/{bookSlug}/chapter/{chapterSlug}` | 75,763.8 | 1,806,389.1 | 345,232 | 345,240 | 345,232, 345,232, 345,240, 345,240 | 4 of 4 | below | above | yes |
| `PUT /books/{bookSlug}/page/{pageSlug}` | 75,790.2 | 1,806,414.1 | 345,257 | 345,265 | 345,257, 345,265, 345,265, 345,265 | 4 of 4 | below | above | yes |
| `PUT /books/{bookSlug}/page/{pageSlug}/revisions/{revId}/restore` | 75,787.2 | 1,806,411.1 | 345,246 | 345,262 | 345,246, 345,262, 345,262, 345,262 | 4 of 4 | below | above | yes |
| `PUT /shelves/{slug}` | 315,797.3 | 5,477,597.3 | 345,232 | 345,240 | 345,232, 345,240, 345,240, 345,240 | 4 of 4 | below | above | yes |

Of the 20 marked routes with at least one cold value: the point is inside the cold range for 8, below it for 10, above it for 2. The upper side is inside the range for 2, below it for 1, above it for 17. The range [point, upper side] encloses the whole measured cold range for 10 of 20. Two marked routes have no cold value: `GET /books/{slug}` (HTTP 500 in all four tries) and `POST /books/{bookSlug}/copy` (all four tries ran past 600 seconds); the second has no estimate and is outside every denominator (D205).

The four cold values of a route are identical for 7 of the 20 routes (for example the four `references` routes) and differ between laps for the other 13 (for example `DELETE /books/{bookSlug}/chapter/{chapterSlug}`: 1,560,450 to 2,550,461). Three laps were made on one database.

The repeat ran 66 trials (22 routes, 3 laps, in 5 groups of one database each; the groups were built in 17, 250, 35, 227 and 22 seconds). 60 trials ended with 2 of 2 attempts ok; 6 had 0 ok (the three laps of `GET /books/{slug}` and of `POST /books/{bookSlug}/copy`; the copy trials ran about 610 seconds each, against the 600-second request limit). Every try of a marked route that is listed in `compare.md` is "used"; none was superseded by a run again.

### Failed cold tries by class

Counted by what the record says (section 0):

| Target | Tries | timeout (600 seconds or more) | 422 | 500 | exception | other |
| --- | --- | ---: | ---: | ---: | ---: | ---: |
| Verification app | failed, rule B reads them as without a value | 2 | 0 | 0 | 0 | 1 |
| Verification app | failed, replaced by a run again (not used) | 0 | 0 | 0 | 0 | 0 |
| BookStack | failed, rule B reads them as without a value | 4 | 1 | 4 | 0 | 0 |
| BookStack | failed, replaced by a run again (not used) | 0 | 0 | 0 | 0 | 0 |

On the verification app the two timeouts are `GET /ng/nested` and `POST /ok/bulk`, and the one try of class "other" is `GET /ng/first-or-create-in-loop` (the runner failed, exit code 139). On BookStack the 4 tries of class 500 are all four tries of `GET /books/{slug}` (MySQL's limit of 65,535 placeholders in one prepared statement, SQLSTATE 1390); the 4 timeouts are all four tries of the marked route `POST /books/{bookSlug}/copy`; and the one 422 is the first try of the unmarked route `PUT /books/{slug}` (section 5 says what else the record holds for it). A marked route that has a timeout is "not measured, 600 seconds or more": `POST /books/{bookSlug}/copy`, which has no estimate and so is outside every denominator.

## 5. Routes outside the denominator

Nothing is dropped silently. The reason each of the two verification-app routes could not be captured at 30,000 rows is recorded as a result of this run: `GET /ng/nested` was cut at the 60 s limit after 105,472 queries; `POST /ok/bulk` answered HTTP 500 with SQLSTATE 1390 (MySQL's limit on the number of placeholders).

Every route that has an estimate and is not in a denominator, or has no estimate, with the reason:

- **Verification app (24 routes in the estimate; 21 in the denominator).**
  - `GET /ng/nested`: estimate partial. At 300,000 rows the request ran longer than 600 seconds, so there is no cold value. Outside the denominator under R1 (no estimate) and under R2 (not measured).
  - `POST /ok/bulk`: estimate partial. At 300,000 rows the request ran longer than 600 seconds (class timeout), so there is no cold value. Outside the denominator under R1 and R2 alike.
  - `GET /ng/first-or-create-in-loop`: estimate complete. The runner failed with exit code 139 and recorded no value (class `other:runner_failed`); at 200,000 rows this route had ended at the 600-second limit. Not measured under R1 and R2; it is the one route of the verification app that is "estimated but not measured" under R1.
- **BookStack (36 routes in the estimate; 33 in the denominator).**
  - `GET /books/{slug}` (marked): HTTP 500 in all four tries (SQLSTATE 1390, the placeholder limit), so there is no cold value for any quantity. Not measured.
  - `PUT /books/{slug}` (unmarked): the record of this route at 300,000 rows holds two tries, both numbered attempt 1. The first is HTTP 422 ("The name field is required", 5 queries, 198 rows examined); the second is a request that ran longer than 600 seconds (class timeout; 632,374 queries and 4,392,850 rows examined had been counted when it was cut, which are not values of a finished request). The comparison read the first, so `compare.md` lists this route as a 422; by either record the route has no cold value and is not measured. At 200,000 rows the route ended with HTTP 302.
  - `POST /books/{bookSlug}/copy` (marked): no estimate (D205; the estimate is 0.0 and has no peak-memory prediction); all four tries ran past 600 seconds. Outside the denominator and the undecidable count.
- **No estimate row:** BookStack's measurement has 63 routes; the 27 routes without an estimate row (authentication, validation errors and similar, listed in `compare.md`) are outside the test, as in the earlier runs. `GET /oidc/callback` has an estimate row and is in the denominator (queries and rows examined: the estimate and the measured value are both 0).

**Class name against detail.** The tool records a request that ran past the 600-second limit as class `timeout` on some routes and as class `exception` (status 500) on others, whose detail reads "the request ran longer than 600 seconds". The comparison counts them as timeouts by their detail. Cases in this run: `GET /ng/nested` (verification app, 1 try) and `POST /books/{bookSlug}/copy` (BookStack, 4 tries). Neither is inside a denominator. The list of timeouts in the comparison output recorded under another class name holds only these two routes, and neither is inside a denominator. The one record the comparison did not read is the second try of `PUT /books/{slug}` above (class timeout), which is outside every denominator whichever record is read.

### The other reading of the denominator of the number of queries

The way the denominator of the number of queries is read (the sum over the statement types of the route, for every route, as in the second run) is the stricter of two readings. D95 says that a route whose route-level formula is `contradicts_static` is left out of the denominator of that quantity. The other reading leaves those routes out. The route-level status is read from `extrapolation-data-3/{fixtures,bookstack}/predictions.json` (the routes' fits of the second run, copied as they are; D196, D202). Both readings, rule B, R1:

| Scope | Queries inside ±5%, as counted in this file | Queries inside ±5%, routes with a `contradicts_static` formula left out | Pass line (80%) under each reading |
| --- | --- | --- | --- |
| Verification app | 21 of 21 (100.00%) | 21 of 21 (100.00%) | met under both |
| BookStack | 32 of 33 (96.97%) | 20 of 20 (100.00%) | met under both |
| Both applications together | 53 of 54 (98.15%) | 41 of 41 (100.00%) | met under both |

13 BookStack routes have a `contradicts_static` formula for the number of queries and are in the denominator as counted in this file; 12 of them are inside the lowest standard and 1 is not (`GET /shelves/{slug}`, 5.27%). The verification app has none. The rows examined are not affected, because a route left out of one quantity stays in the other (D95). The verdicts are the same under both readings. Which reading is the correct one is left to the project owner's decision. This file keeps the stricter reading.

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

Facts for the third run of test A, with the estimates committed at `206d1148fce29a8643b50a2bb21d0732b7b2cc03` before the measurement and the pass line unchanged. R1 gives the verdict (D212, the ruling item); R2 gives the same verdicts and counts.

- **Rule B (decides):** verification app **pass** (queries 21 of 21 (100.00%), rows examined 21 of 21 (100.00%) inside the lowest standard); BookStack **fail** (queries 32 of 33 (96.97%), rows examined 24 of 33 (72.73%); 27 needed); both applications together **pass** (queries 53 of 54 (98.15%), rows examined 45 of 54 (83.33%); 44 needed).
- **Rule A (shown):** verification app pass, BookStack fail (rows examined 21 of 33 (63.64%)), both applications together **fail** (rows examined 42 of 54 (77.78%)). The two rules differ in the verdict of the two applications together only.
- **By group, rows examined, rule B, both applications together:** inside the lowest standard, all routes 45 of 54 (83.33%), unmarked 34 of 34 (100.00%), marked 11 of 20 (55.00%); inside the goal, all 36 of 54 (66.67%), unmarked 32 of 34 (94.12%), marked 4 of 20 (20.00%). Queries: all 53 of 54 (98.15%) inside the lowest standard and 53 of 54 (98.15%) inside the goal.
- **What version 1.0 asks of A (spec, section 0, condition 1), in these figures:** unmarked routes inside the goal, together: queries 34 of 34 (100.00%), rows examined 32 of 34 (94.12%); all routes inside the lowest standard, together: queries 53 of 54 (98.15%), rows examined 45 of 54 (83.33%). For BookStack alone: unmarked inside the goal, queries 13 of 13 (100.00%), rows examined 11 of 13 (84.62%); all inside the lowest standard, queries 32 of 33 (96.97%), rows examined 24 of 33 (72.73%).
- **Largest errors:** all on BookStack except two entries of the verification app (`GET /ok/chunk` and `GET /ok/lazy`, rows examined, 2.04%, inside both lines). The largest is `GET /shelves/{slug}` (rows examined, estimate 289.0 against 375,226 measured, a marked route, 99.92%); the three PUT routes (`PUT /books/{bookSlug}/chapter/{chapterSlug}`, `PUT /books/{bookSlug}/page/{pageSlug}`, `PUT /books/{bookSlug}/page/{pageSlug}/revisions/{revId}/restore`) are under-estimated by 78.05% (estimate about 75,800 against about 345,200 to 345,300 measured); the four `references` routes are under-estimated by 58.77% to 61.21%.
- **Marked routes:** the point lies above the measured cold range for 2 routes, below it for 10 and inside it for 8 of 20; the upper side lies above the range for 17 of 20; the range [point, upper side] encloses the cold range for 10 of 20. Marked routes are where the misses concentrate: 9 of the 9 BookStack misses of rows examined are marked routes (11 of 20 marked routes are inside the lowest standard, 13 of 13 unmarked ones).
- **Not measured:** with an estimate, 3 routes under R1 (1 on the verification app, 2 on BookStack) and 5 under R2 (3 and 2); the marked route `GET /books/{slug}` (HTTP 500 in all four tries) and the unmarked `PUT /books/{slug}` are two of them; no marked route that has an estimate was lost to a 600-second cut. `POST /books/{bookSlug}/copy`, which was cut at 600 seconds in all four tries, has no estimate and is outside every denominator.
- **Multiple of the capture's largest size:** 10 (30,000 rows captured, 300,000 rows measured).
- **What is left of conditions 1 to 3 of section 0 of the spec, counted as D153 counts them (with the change of D167):** `TBD` (the scope of the count for condition 1a, the verification app, BookStack or both together, is not fixed in D153, and condition 3 needs the cause and the mark of each remaining miss; asked, not guessed).

What these results do not show is in the list of limits above.
