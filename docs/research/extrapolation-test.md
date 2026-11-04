# Extrapolation test (A): do formulas fitted at 100 to 10,000 rows predict 100,000?

Status: COMPLETE for the first run of test A on both targets (measured and compared on 2026-11-04). Sections 2 to 4 are taken from the output of the comparison; the figures are copied from it and not rounded again by hand. The limits in section 6 were written before the result and are kept as they were; the conclusion at the end of section 6 states facts only.

This file is the record of test A of `docs/simulator-spec.md` (version 0.1, section 7.4). It says what was done in which order, what was measured, and what the numbers can and cannot support. The pass line was fixed before any data were seen (D71) and is not moved by this file.

## 0. What is tested

For each route and each of three quantities, a formula `y = a + b·N^k` (a ≥ 0, b ≥ 0, 0 ≤ k ≤ 3) is fitted to the cold request measured at the scales 0 (empty system), 100, 1,000 and 10,000 (D73 to D89). The formula is evaluated at N = 100,000 and written down. Then the same request is measured at N = 100,000 and the two are compared.

| Quantity | Tolerance (relative error) |
| --- | --- |
| Number of queries | ±5% |
| Rows examined | ±20% |
| Peak memory | ±30% |

Relative error is `|predicted − measured| / measured`; when the measured value is 0, the prediction counts as a match if it is below 0.5 (otherwise a mismatch). The comparison is done by multiplication rather than division, so a route that is exactly on the tolerance passes (D89).

Pass line (D71, D95): the share of routes within tolerance, counted per quantity, is 80% or more for **both** the number of queries and the rows examined. Peak memory is reported but does not decide A (it is used in the simulator to choose which routes to look at, not to predict database cost). A is "undecidable", and is not counted as a pass, when a quantity has fewer than 5 routes in its denominator, or when more than 20% of the routes that have a formula could not be measured at 100,000.

The denominator of a quantity is: the routes whose formula for that quantity has `fit_status: ok` and whose cold request at 100,000 was measured. Routes with `contradicts_static` or `insufficient_scales` for that quantity are not in its denominator, and are listed in section 4 (they are never dropped silently). A route that is out for one quantity can be in the denominator of another.

Rows examined are the sum of the session's `Handler_read_*` counters over the request, less the counters' own idle growth (D73). They are not the rows returned by the queries; section 5 shows the two side by side.

## 1. Procedure and the commit that fixed the predictions

The order is fixed, and the order is what makes the test valid: the prediction is committed before the 100,000-row measurement is started. If step 5 were run before step 4, A would be void for that target and would have to be redone with a scale not yet seen.

1. Measure at scales 100, 1,000, 10,000 (the empty system, scale 0, is measured automatically).
2. Fit the formulas from the records of step 1.
3. Evaluate the formulas at 100,000 and write the predictions.
4. Commit the fits and the predictions.
5. Measure at 100,000 (`--scales=100000 --request-timeout=600 --warm=off`, D90 to D92).
6. Compare the predictions with the measurement.

| | |
| --- | --- |
| Commit that fixed the predictions (step 4) | `305c928` |
| Commit time | 2026-11-04T17:09:44+09:00 |
| Files in it | `docs/research/extrapolation-data/{fixtures,bookstack}/{fits,predictions}.json` (D94) |
| Step 5 started, verification app | 2026-11-04T17:10:24+09:00 (40 s after the commit) |
| Step 5 started, BookStack | 2026-11-04T17:51:57+09:00 (2 h 42 min after the commit) |

The step-5 start times are read from the records the runner wrote. Step 5 was allowed to start only after 17:09:44+09:00, and both targets started after it. For each target the commit time, the time the commit was recorded (17:09:48+09:00) and the start of step 5 are in the target's time records.

### The measurer at each step

The measuring tool was fixed at three commits. The run of a target at a given step used the tool as of the commit shown.

| Target and step | Measurer commit | Time of the run |
| --- | --- | --- |
| Verification app, step 1 | `2ce6bac` | 2026-11-04T14:01:56+09:00 to 14:08:12+09:00 |
| BookStack, step 1, first try | `2ce6bac` | started 2026-11-04T14:02:00+09:00; stalled and was stopped at 15:18:28+09:00 after 4,588 s; it left no measurement records and nothing from it is used |
| BookStack, step 1 | `9364527` | 2026-11-04T15:50:14+09:00 to 16:20:05+09:00 (1,791 s) |
| Verification app, step 5 | `305c928` | 2026-11-04T17:10:24+09:00 to 17:49:08+09:00 (2,324 s, exit 0) |
| BookStack, step 5 | `305c928` | 2026-11-04T17:51:57+09:00 to 19:10:03+09:00 (4,686 s, exit 0) |

What changed between them, and why it does not change the measured values:

- **`2ce6bac` to `9364527`** (changes in `poc/runtime/src` and `poc/runtime/profiles`). The BookStack data profile was changed so that the permission rows grow linearly in the scale (D100), because the first try built a table that grows with the square of the scale and blocked the run. The measurer now kills the statement that a timed-out database build or measurement leaves on the server, and marks that scale `not_run` instead of waiting (D97 to D99). The comparison code was changed to count the denominator per quantity (D95); it does not touch the recorded measurements. The profile file is used only for BookStack (the verification app is seeded by its own seeder), and the other changes act only on a time-out or a lock wait (D97 to D99). The verification app was therefore measured by `2ce6bac` and not re-measured. The first BookStack try was not used, and the BookStack data were built from the profile of `9364527`.
- **`9364527` to `305c928`**: no change under `poc/runtime/src`, `poc/runtime/bin`, `poc/runtime/compose`, `poc/runtime/profiles` or `fixtures/`; only tests and the decision log changed. `305c928` itself adds only the four data files (the fits and predictions of the two targets).
- Between those commits the check of rows examined against the server's own count was finished (section 5). It is a test; it does not change the figure that is measured.

Where the numbers come from (kept outside the repository, in the measurement output directory of the run): the step-1 records (`s1`, `s1b`) of each target, the step-5 records (`s5`) of each target, the step-4 and step-5 time records of each target, and the output of the comparison. The measurement records are kept outside the repository. Relative to that directory, for each target (`fixtures` and `bookstack`): `s1` (and `s1b` for BookStack) hold the step-1 records, `s5` the step-5 records, `T3_step4_commit.txt` the commit time, `T3_step5_start.txt` the step-5 start (BookStack also keeps the wall time of step 5 in `wall_s5.txt`), `fits.json` and `predictions.json` the committed formulas and predictions (copies are in `docs/research/extrapolation-data/`), and `compare.json` the output of the comparison. The verification app also has `compare.md`, the same result as text. The figures in sections 2 to 4 are copied from `compare.json`.

## 2. Result: share within tolerance, per target and quantity

Source: the output of the comparison of each target (`compare.json`, schema 0.1, at N = 100,000). The predictions compared are the ones committed at `305c928`. The count of routes is per quantity; a route that is out for one quantity can be in another (section 0).

**Verification app** (`fixtures/laravel-app`, seeded data; 24 routes)

| Quantity | Tolerance | Denominator | Within tolerance | Share | Formula but not measured at 100,000 | `contradicts_static` | `insufficient_scales` |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: |
| Number of queries | ±5% | 23 | 23 | 100% | 1 | 0 | 0 |
| Rows examined | ±20% | 23 | 22 | 95.65% | 1 | 0 | 0 |
| Peak memory (reported, does not decide A) | ±30% | 23 | 23 | 100% | 1 | 0 | 0 |

**BookStack** (generated data with the minimal BookStack profile; 63 routes in the step-1 set)

| Quantity | Tolerance | Denominator | Within tolerance | Share | Formula but not measured at 100,000 | `contradicts_static` | `insufficient_scales` |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: |
| Number of queries | ±5% | 12 | 12 | 100% | 0 | 11 | 40 |
| Rows examined | ±20% | 23 | 15 | 65.22% | 0 | 0 | 40 |
| Peak memory (reported, does not decide A) | ±30% | 23 | 17 | 73.91% | 0 | 0 | 40 |

The denominators are the upper bounds that section 4.2 read from the fit status before step 5 (12 and 23); every route with a formula was measured at 100,000, so none was removed after step 5.

**Verdict of A**

| Target | Number of queries ≥ 80% | Rows examined ≥ 80% | Undecidable condition hit | Verdict |
| --- | --- | --- | --- | --- |
| Verification app | yes (23 of 23, 100%) | yes (22 of 23, 95.65%) | no (denominators 23 and 23; 1 of 24 routes with a formula not measured, 4.2%) | **pass** |
| BookStack | yes (12 of 12, 100%) | **no** (15 of 23, 65.22%; 19 of 23 were needed) | no (denominators 12 and 23; 0 routes with a formula not measured) | **fail** |

How the verdict is read: pass needs both quantities at 80% or more and no undecidable condition; if a condition is hit, the verdict is "undecidable" and is not a pass. A failing or undecidable verdict is not repaired here: the pass line is not moved, the part that missed is recorded, and the next check uses routes or scales not yet seen (D71).

Margins on the verification app, stated because the result is a pass: three routes of rows examined lie within two points of the 20% line. `GET /ok/lazy` and `GET /ok/chunk` are inside by 1.58 points (predicted 40,912,022.8 against measured 50,151,002; error 18.42%), and `GET /ok/with-count`, the one miss, is outside by 1.31 points (predicted 1,091,791.1 against measured 900,003; error 21.31%). The pass is therefore 22 of 23 with three routes near the line, not a clear margin on every route.

Routes whose peak memory is within ±30%, listed because the simulator uses them to choose routes to look at (it does not decide A):

- Verification app (23 routes): `GET /hidden/appends`, `GET /hidden/blade`, `GET /hidden/cache`, `GET /hidden/conditional-binding`, `GET /hidden/cursor`, `GET /hidden/model-with`, `GET /hidden/resource`, `GET /ng/collection-filter`, `GET /ng/count-in-loop`, `GET /ng/first-or-create-in-loop`, `GET /ng/get-then-count`, `GET /ng/layered`, `GET /ng/n-plus-one`, `GET /ng/nested`, `GET /ng/save-in-loop`, `GET /ng/service`, `GET /ng/unbounded`, `GET /ok/chunk`, `GET /ok/eager`, `GET /ok/lazy`, `GET /ok/paginate`, `GET /ok/with-count`, `GET /unknown/dynamic`.
- BookStack (17 routes): `DELETE /books/{bookSlug}/chapter/{chapterSlug}`, `DELETE /books/{bookSlug}/draft/{pageId}`, `DELETE /books/{bookSlug}/page/{pageSlug}`, `DELETE /books/{id}`, `DELETE /shelves/{slug}`, `GET /books/{bookSlug}/draft/{pageId}`, `GET /images/edit/{id}`, `GET /oidc/callback`, `GET /settings/users/create`, `GET /settings/users/{id}`, `POST /import/{id}`, `POST /settings/recycle-bin/empty`, `PUT /books/{bookSlug}/chapter/{chapterSlug}`, `PUT /books/{bookSlug}/page/{pageSlug}`, `PUT /books/{bookSlug}/page/{pageSlug}/revisions/{revId}/restore`, `PUT /settings/users/{id}`, `PUT /shelves/{slug}`.

## 3. Routes with the largest error (top 10 per target)

Ranked by relative error, over the quantities that decide A (number of queries, rows examined). The fitted formula is the one committed at `305c928`.

Ranked by relative error, over the entries (route and quantity) in the denominators of the two quantities that decide A, taken from `compare.json`. The columns a, b and k are the committed fit of that route and quantity (`fits.json`, shown to six significant digits; the exact values are in the file); predicted and measured are the figures at N = 100,000. "Within" is against the tolerance of the quantity (±5% for the number of queries, ±20% for rows examined).

**Verification app** (46 entries: 23 routes for each of the two quantities)

| # | Route | Quantity | a | b | k | Predicted | Measured | Relative error | Within |
| ---: | --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | --- |
| 1 | `GET /ok/with-count` | rows examined | 266.237 | 972823 | 0.01 | 1,091,791.1 | 900,003 | 21.31% | no |
| 2 | `GET /ok/chunk` | rows examined | 50.5109 | 0.0129375 | 1.9 | 40,912,022.8 | 50,151,002 | 18.42% | yes |
| 3 | `GET /ok/lazy` | rows examined | 50.5109 | 0.0129375 | 1.9 | 40,912,022.8 | 50,151,002 | 18.42% | yes |
| 4 | `GET /hidden/model-with` | queries | 1.00106 | 0.931772 | 0.01 | 2.05 | 2 | 2.33% | yes |
| 5 | `GET /ok/eager` | queries | 1.00106 | 0.931772 | 0.01 | 2.05 | 2 | 2.33% | yes |
| 6 | `GET /ok/paginate` | queries | 1.00106 | 0.931772 | 0.01 | 2.05 | 2 | 2.33% | yes |
| 7 | `GET /hidden/model-with` | rows examined | 8.58266 | 1.09832 | 0.99 | 97,896.2 | 100,026 | 2.13% | yes |
| 8 | `GET /ok/eager` | rows examined | 8.58266 | 1.09832 | 0.99 | 97,896.2 | 100,026 | 2.13% | yes |
| 9 | `GET /ok/paginate` | rows examined | 12.8854 | 1.00067 | 1 | 100,080.1 | 100,019 | 0.06% | yes |
| 10 | `GET /hidden/appends` | queries | 1 | 1 | 1 | 100,001 | 100,001 | 0.00% | yes |

Nine entries have a non-zero error; the other 37 have an error of exactly 0, so rank 10 is the first of them in route order and the order among them carries no meaning. The fit with k = 1.9 for `GET /ok/chunk` and `GET /ok/lazy` predicted 40.9 million rows examined; 50.2 million were measured.

**BookStack** (35 entries: 12 for the number of queries and 23 for rows examined)

| # | Route | Quantity | a | b | k | Predicted | Measured | Relative error | Within |
| ---: | --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | --- |
| 1 | `POST /settings/maintenance/regenerate-references` | rows examined | 304.55 | 0.0610119 | 1.68 | 15,325,810.5 | 64,849,407 | 76.37% | no |
| 2 | `PUT /books/{bookSlug}/chapter/{chapterSlug}` | rows examined | 224.282 | 0.384365 | 0.99 | 34,480.9 | 115,232 | 70.08% | no |
| 3 | `PUT /books/{bookSlug}/page/{pageSlug}` | rows examined | 249.282 | 0.384365 | 0.99 | 34,505.9 | 115,257 | 70.06% | no |
| 4 | `DELETE /settings/maintenance/cleanup-images` | rows examined | 358.664 | 6.4521 | 1.12 | 2,568,984.5 | 6,450,294 | 60.17% | no |
| 5 | `POST /settings/recycle-bin/empty` | rows examined | 306.44 | 9.34097 | 0.99 | 832,821.5 | 520,467 | 60.01% | no |
| 6 | `DELETE /books/{bookSlug}/chapter/{chapterSlug}` | rows examined | 326.569 | 9.34033 | 0.99 | 832,784.4 | 520,510 | 59.99% | no |
| 7 | `DELETE /books/{bookSlug}/draft/{pageId}` | rows examined | 320.93 | 9.34029 | 0.99 | 832,774.9 | 520,514 | 59.99% | no |
| 8 | `DELETE /books/{bookSlug}/page/{pageSlug}` | rows examined | 326.65 | 9.3402 | 0.99 | 832,772.8 | 595,461 | 39.85% | no |
| 9 | `PUT /settings/users/{id}` | rows examined | 193 | 0 | 0 | 193 | 204 | 5.39% | yes |
| 10 | `GET /books/{bookSlug}/page/{pageSlug}/references` | rows examined | 210.384 | 0.439405 | 0.99 | 39,372.4 | 40,218 | 2.10% | yes |

The eight largest errors in rows examined are all outside ±20% (ranks 1 to 8); there are no entries for the number of queries among the ten, since the largest error in the number of queries is 2.05% (`POST /settings/recycle-bin/empty`), well below the eight above.

## 4. Routes outside the denominator

Nothing is dropped silently. What is known before step 5 is taken from the `fit_status` of `docs/research/extrapolation-data/*/fits.json`; the part that depends on the 100,000-row measurement is taken from the output of the comparison.

### 4.1 Verification app

All 24 routes have `fit_status: ok` for all three quantities. No route is excluded before step 5. Routes with a formula that could not be measured at 100,000: **1** (`POST /ok/bulk`), out of the denominator of all three quantities (its predictions were 2.05 queries, 100,002 rows examined and 218,400,737.96 bytes of peak memory). The first request of the route at 100,000 (the warm-up, which is made before the cold request) raised an exception: `Illuminate\Database\QueryException`, SQLSTATE[HY000] general error 1390, "Prepared statement contains too many placeholders". The route inserts the 100,000 rows in one statement, and the database refuses a statement with more than 65,535 placeholders. The cold request was not run, so the comparison gives the reason "no cold attempt at the predicted size". This is not a miss of the formula: the app does not work at that size, which is a limit of correctness and not of cost. The formulas predicted a cost for a request that fails at 100,000.

### 4.2 BookStack

**Removed from the measurement before step 1 (D93).** `GET /my-account`, `POST /saml2/acs` and `GET /settings/{category}` are not in the BookStack step-1 set and so not in `fits.json`. They run with a plan that grows every table to N rows. That plan has no empty-system point by design, so no formula could be made for them, and their permission rows grow with the square of the scale (about 100 million rows at 10,000), which stops the measurement itself. They would not have been in any denominator; the code was not changed.

**Routes in the step-1 set: 63.** The count of each `fit_status`, from `fits.json`:

| Quantity | `ok` | `contradicts_static` | `insufficient_scales` |
| --- | ---: | ---: | ---: |
| Number of queries | 12 | 11 | 40 |
| Rows examined | 23 | 0 | 40 |
| Peak memory | 23 | 0 | 40 |

So the denominator of BookStack is at most 12 routes for the number of queries and at most 23 for rows examined and for peak memory, before the routes that cannot be measured at 100,000 are removed. These are upper bounds read from the fit status; they are not a prediction of the result.

**`contradicts_static` (number of queries; 11 routes).** The static analysis puts a lower bound of O(N) on the number of queries, and the measured exponent is 0.2 or less, so the fit was set aside (D80, D84; the rejected formula is kept under `rejected_fit` and is never used for a prediction). The measured exponent was 0.01 for four routes and 0 for seven. The rows examined of these routes are fitted and stay in the rows-examined denominator (D95).

- `GET /books/{bookSlug}/chapter/{chapterSlug}/references`, `GET /books/{bookSlug}/page/{pageSlug}/references`, `GET /books/{slug}/references`, `GET /shelves/{slug}/references` (exponent 0.01)
- `GET /images/edit/{id}`, `GET /oidc/callback`, `POST /import/{id}`, `PUT /books/{bookSlug}/chapter/{chapterSlug}`, `PUT /books/{bookSlug}/page/{pageSlug}`, `PUT /books/{bookSlug}/page/{pageSlug}/revisions/{revId}/restore`, `PUT /shelves/{slug}` (exponent 0)

**`insufficient_scales` (all three quantities; 40 routes).** Fewer than three scales including scale 0 could be measured. The reason the measurer recorded for each group:

- *No scale measured, request refused for authentication (21 routes).* The class is `unauthenticated`: "No authorization token found on the request". These are routes under `/api`, which need an API token the measurement does not have: `DELETE /api/books/{id}`, `DELETE /api/chapters/{id}`, `DELETE /api/imports/{id}`, `DELETE /api/pages/{id}`, `DELETE /api/shelves/{id}`, `DELETE /api/users/{id}`, `GET /api/books/{id}`, `GET /api/books/{id}/export/zip`, `GET /api/chapters/{id}`, `GET /api/imports/{id}`, `GET /api/pages/{id}`, `GET /api/shelves/{id}`, `GET /api/users`, `GET /api/users/{id}`, `POST /api/imports/{id}`, `POST /api/users`, `PUT /api/books/{id}`, `PUT /api/chapters/{id}`, `PUT /api/pages/{id}`, `PUT /api/shelves/{id}`, `PUT /api/users/{id}`.
- *No scale measured, the request raised an exception (5 routes).* `DELETE /settings/users/{id}`, `GET /login/service/{socialDriver}/callback`, `GET /register`, `POST /register` (class `exception`), and `GET /import/{id}` (a view exception: attempt to read property "name" on null).
- *No scale measured, validation error (1 route).* `POST /settings/users/create` (class `validation_422`: the email and password fields are required).
- *Scale 0 not measured, scales 100 to 10,000 measured (12 routes).* The request at scale 0 returned `not_found` (class `not_found`), so there is no point for the empty system, and no formula is made from three scales without it: `DELETE /import/{id}`, `DELETE /settings/sorting/rules/{id}`, `GET /books/{bookSlug}/chapter/{chapterSlug}`, `GET /books/{bookSlug}/copy`, `GET /books/{bookSlug}/export/zip`, `GET /books/{bookSlug}/page/{pageSlug}`, `GET /books/{slug}`, `GET /settings/sorting/rules/{id}`, `GET /shelves/{slug}`, `POST /books/{bookSlug}/draft/{pageId}`, `PUT /books/{slug}`, `PUT /settings/sorting/rules/{id}`.
- *Scale 0 and scale 10,000 not measured (1 route).* `POST /books/{bookSlug}/copy` (class `not_found` at scale 0).

(21 + 5 + 1 + 12 + 1 = 40.) The reasons are the class and message the measurer recorded; the table does not say why a given request was refused beyond that.

**Not measured at 100,000 (formula exists, no measurement at the large scale).** None. All 23 routes with a formula (12 for the number of queries, 23 for rows examined and peak memory) were measured at 100,000, so the BookStack denominators are the same as the upper bounds above.

**Counts of routes outside a denominator, per target** (the same numbers as in the tables of section 2):

| Target | Reason | Number of queries | Rows examined | Peak memory |
| --- | --- | ---: | ---: | ---: |
| Verification app | Formula but not measured at 100,000 (`POST /ok/bulk`) | 1 | 1 | 1 |
| Verification app | `contradicts_static` | 0 | 0 | 0 |
| Verification app | `insufficient_scales` | 0 | 0 | 0 |
| BookStack | Formula but not measured at 100,000 | 0 | 0 | 0 |
| BookStack | `contradicts_static` (listed above, 11 routes) | 11 | 0 | 0 |
| BookStack | `insufficient_scales` (listed above, 40 routes) | 40 | 40 | 40 |
| BookStack | Removed from the measurement before step 1 (D93, 3 routes, not in `fits.json`) | not counted | not counted | not counted |

## 5. Check of rows examined against the server's own count

Rows examined is the figure the formulas for the database cost rest on, so before it was used it was checked against what the database server itself counted for the same statements. The check runs inside the sealed measurement stack, on all 24 routes of the verification app, with its own sizes (100 customers, 100 products, 500 orders, 3 items per order). The server's side is read through a separate connection of the test, never from the app's connection. For each route:

- the request is run through the normal measurement tool (cold request);
- the server's `ROWS_EXAMINED` is summed over the statements between the two counter snapshots (`performance_schema.events_statements_history_long`);
- the same request is sent once more through a tap that reads the counters statement by statement and the plan (`EXPLAIN`) of each statement, so the app's figure can be paired with the server's per statement.

The two numbers are made by different mechanisms (the storage engine's handler calls against the optimizer's per-statement count). The handler's count is the one that is kept, because it is what the database spends its CPU on (D96). Rules, all decided before the run:

- Three kinds of statement are left out of both sides before comparing, for a reason known in advance (D96): a `count(*)` over a whole table with no `WHERE` (the server records 0, the engine reads the whole index), the end-of-range read of a range read of an index, and a look-up by a unique index that finds nothing. Which statement is of which kind is decided mechanically from the statement text and its plan, never by route name.
- A fourth kind is not left out but adjusted (D101, a ruling by the project owner): for a scan of a whole table or index (plan type `ALL` or `index`) whose handler count is 1 to 3 rows above the server's, only that small difference (the read that finds the end of the scan) is taken off the app's count, and the statement stays in the comparison. The cap of 3 is fixed and was not widened after seeing the result. A difference above 3 on any scan statement fails the route whatever the route total is.
- A route passes when it is within 10% of the server's count on what is left, with no statement over the cap and with the app's figure fully explained by the tap's statements (D102 gives the order in which these are checked). The limit of 10% was not moved to make a route pass.

Result on the 24 routes: all 24 passed, with a difference of 0% on what is left; no scan statement was over the cap; nothing in the app's figure was left unexplained by the tap. The Docker test suite that contains the check passed in both stages (stage 1: 20 tests; stage 2: 92 tests, which include the check), with 0 failures and 0 skipped.

| Kind | Statements | Routes | Largest difference after |
| --- | ---: | ---: | ---: |
| `count(*)` of a whole table (left out) | 2 | 2 | 0% |
| End-of-range read (left out) | 1,001 | 3 | 0% |
| Unique look-up that finds nothing (left out) | 100 | 1 | 0% |
| Scan end (3 rows or fewer taken off, statement kept) | 34 | 22 | 0% |

The scan-end difference was 1 to 3 rows per statement, 78 rows in all over the 34 statements, and did not depend on the number of rows (the sizes above run from 100 to 2,000 rows per route). The three left-out kinds and the scan end, taken together, account for every difference between the handler count and the server's count in the 24 routes.

Rows returned and rows examined are different things and are reported under separate names. Examples from the same run (one cold request each; the sizes are those of the check, not of the scales of the test):

| Route | Queries | Rows returned | Rows examined (handler) | Server's count | Remark |
| --- | ---: | ---: | ---: | ---: | --- |
| `GET /ok/eager` | 2 | 600 | 606 | 600 | Two scans; 3 rows of scan end each |
| `GET /ng/n-plus-one` | 501 | 1,000 | 1,003 | 1,000 | One query per row; the examined rows equal the rows returned plus the scan end |
| `GET /ng/unbounded` | 1 | 500 | 503 | 500 | One unbounded scan |
| `GET /ng/count-in-loop` | 501 | 1,000 | 2,503 | 2,000 | The 500 per-row statements are of the end-of-range kind and are left out of both sides; on what is left the two agree (500 and 500) |

In the first three, the number of queries differs by orders of magnitude (2, 501, 1) while the rows examined are close to the rows returned, so a growth in the number of queries is not a growth in the rows examined; the two are measured and fitted separately for that reason. The last row is a case where the figure that is kept (2,503) is larger than the server's (2,000), because of the left-out kind.

## 6. What can be said, and what cannot

The list of limits below was written before the result and is kept as it was, except where a cell was filled from the output. The conclusion is after the list.

What the test is:

- It checks whether a power law `a + b·N^k`, fitted to four points (0, 100, 1,000, 10,000), predicts the number of queries, the rows examined and the peak memory of a cold request at 100,000. The prediction was committed before the measurement it is compared with (section 1).
- The pass line, the tolerances and the denominator were fixed before the data (D71, D95), and are not moved by the result.

What it does not support:

- **Two applications only.** One is the small verification app that was written for this project, with a seeder; the other is BookStack, with a data profile written for this test. Nothing here is a statement about other Laravel applications.
- **Synthetic data.** The data are generated from schemas and a profile, not taken from a real system. Distributions that matter in production (skew, hot rows, cache behaviour) are not reproduced.
- **One measurement.** Each route is measured once at 100,000 (cold request; the warm request was left out to save time, D92). Run-to-run variation at that scale is not measured, so a route near a tolerance could fall on either side of it on another run.
- **The fit is by an unweighted sum of squares.** The 10,000-row point therefore dominates the formula; the points at 100 and 1,000 carry little weight in comparison. A formula that is good at 10,000 and extrapolated 10 times further is what is tested; it is not a test of the formula between the fitted points.
- **Axis of the child table is not fitted (D83).** A formula has a single axis N (rows of the main table). The sweep of the child-table axis M (3, 30, 300 rows) is not fitted, so a route whose cost depends mainly on M is not tested for that dependence.
- **`contradicts_static` routes** (D84) were set aside for the number of queries. This is a judgement that the static analysis is right and the four points are not; it is not shown here which of the two is wrong. They are listed in section 4 and are not counted in either direction.
- **The BookStack denominator is mixed.** It contains routes whose query count does not depend on N (a constant number of queries) and routes whose query count grows. A share over the whole denominator would be dominated by the constant ones, which are the easy case for a power law. The result is therefore to be reported with the two groups separated, using the fitted exponent of the committed formula (counts below). The split is read from the committed fits after the result, and the pass line itself is not changed by it. For BookStack, within tolerance over the routes in each group (a constant route has k = 0 in the committed formula, D77):

  | Quantity | Routes with k = 0 | Routes with k > 0 | Exponents k > 0 |
  | --- | ---: | ---: | --- |
  | Number of queries (denominator 12) | 4, all within | 8, all within | 0.99 to 1.01 |
  | Rows examined (denominator 23) | 5, all within (100%) | 18, 10 within (55.56%) | 0.99, 1, 1.12, 1.68 |
  | Peak memory (denominator 23) | 6, all within | 17, 11 within | |

  The constant routes of rows examined are `GET /books/{bookSlug}/draft/{pageId}`, `GET /images/edit/{id}`, `GET /oidc/callback`, `POST /import/{id}` and `PUT /settings/users/{id}`. All 8 misses of rows examined are among the 18 routes that grow. The share over the whole denominator (65.22%) is the figure that decides A and is not changed by the split; the split shows that the constant routes alone would pass and the growing routes alone would not (55.56%, below 80%).
- **The 12 BookStack routes whose empty-system request was `not_found`,** the 21 `/api` routes that needed a token, and the 6 that raised an exception or a validation error are out of the test because they could not be measured. This limits what BookStack can say about its API and about routes that address a specific record.
- **Rows examined count the engine's handler reads** (D96). The figure agrees with the server's own count after the rules of section 5, which were fixed before they were applied. The check was run on the verification app only; it was not run on BookStack.

## 7. Conclusion

Facts, for the first run of test A, with the predictions committed at `305c928` before step 5 and the pass line of D71 and D95 unchanged:

- **The verification app passed.** Number of queries 23 of 23 within ±5%; rows examined 22 of 23 within ±20% (95.65%); peak memory 23 of 23 within ±30% (reported only). One route (`POST /ok/bulk`) is out of the denominator because the app fails at 100,000 rows (a statement with too many placeholders); the formula predicted a cost for a request that does not succeed. Three routes of rows examined are within two points of the 20% line (`GET /ok/lazy` and `GET /ok/chunk` inside at 18.42%, `GET /ok/with-count` outside at 21.31%), so the margin on rows examined is thin.
- **BookStack did not pass, on rows examined.** Number of queries 12 of 12 within ±5% (100%); rows examined 15 of 23 within ±20% (65.22%, against the 80% needed, which is 19 of 23); peak memory 17 of 23 within ±30% (73.91%, reported only). The undecidable conditions were not hit. The verdict is a fail and was not repaired here.
- **How much of BookStack was tested.** Of 63 routes in the step-1 set, 12 enter the denominator of the number of queries and 23 that of rows examined; 40 routes had too few scales (`insufficient_scales`, for all three quantities) and 11 more were set aside for the number of queries (`contradicts_static`). Three further routes were removed before step 1 (D93). The 100% for the number of queries is over 12 routes, 8 of which grow with N.
- **Where BookStack missed.** All 8 misses are among the 18 routes of rows examined whose committed formula grows (k = 0.99 to 1.68); the 5 constant routes all passed. Four were predicted below the measurement (`POST /settings/maintenance/regenerate-references`, the two `PUT` routes of a chapter and a page, `DELETE /settings/maintenance/cleanup-images`; measured 2.5 to 4.2 times the prediction), and four above (`POST /settings/recycle-bin/empty` and the `DELETE` routes of a chapter, a draft and a page; measured about 0.6 to 0.7 times the prediction). The ten largest errors are in section 3.
- **Reading, not tested.** The four over-predicted routes were given almost the same formula (a about 306 to 327, b about 9.34, k = 0.99), and two other routes with that formula matched (`DELETE /books/{id}`, measured 850,504, and `DELETE /shelves/{slug}`, measured 850,456). Their measured values at 100,000 differ (520,467 to 595,461 against 850,504), which suggests that the points up to 10,000 do not tell these routes apart. This is a reading of the table, not a result of a test, and nothing here says what to do about it. Whether and how to change anything is decided by the project owner.

What these results do not show is in the list of limits above: two applications, synthetic data, one measurement per route at 100,000, a fit weighted toward the 10,000-row point, and a BookStack denominator of 12 and 23 routes out of 63.

