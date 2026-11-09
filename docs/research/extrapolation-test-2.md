# Extrapolation test (A), second run: do the estimates of version 0.2 predict 200,000 rows?

Status: COMPLETE for the second run of test A on both targets (measured 2026-11-09, compared 2026-11-09). Sections 2 to 4 are taken from the output of the comparison (`extrapolation-data-2/compare.json`); the figures are copied from it and not rounded again by hand. The limits in section 6 were written before the result and are kept as they were; the conclusion in section 7 states facts only.

Result in one line: on the verification app rule B gives **pass** (rows examined 21 of 22 inside ±20%); on BookStack rule B gives **fail** (rows examined 22 of 34, 64.71%; 28 of 34 were needed); the two applications together give **fail** (43 of 56, 76.79%; 45 of 56 were needed). Rule A gives the same verdict in every scope.

This file is the record of the second run of test A of `docs/simulator-spec.md` (version 0.2, sections 7.1 and 7.4). It says what was done in which order, what was measured, and what the numbers can and cannot support. The pass line, the tolerances and the denominator are those of the first run (D71, D95, D105) and are not moved by this file.

## Why this run was made

The development check of version 0.2 on the 100,000-row records chose the per-statement fit (H1) for the point estimate of rows examined. The gate fixed for that choice was 37 of 46 routes within ±20%; H1 reached 36 of 46, one route short (D119). The gate was not moved and stays "not reached".

The project owner decided to run the 200,000-row test all the same (D121). The reason given: H1 is nearly equal to version 0.1 (one route apart), and a claim to be an accurate tool needs a check on data not yet seen. The way of measuring was fixed before any 200,000-row data were seen (D122, `docs/simulator-spec.md` section 7.1). The first run (version 0.1, 100,000 rows) is not rescored (D103).

## 0. What is tested

For each route, three quantities are estimated at N = 200,000 and the same cold request is then measured at N = 200,000.

- **Number of queries:** the sum over the statement types of the route of the count formula of each type (the statements are captured once at a small size).
- **Rows examined:** the sum over the statement types of per-execution rows (a per-statement fit, H1) times the count. EXPLAIN is not used for the point; it is used only to mark routes whose plan may change, and to give the upper side of the marked statements (H2 value).
- **Peak memory:** the route-level fit of version 0.1.

| Quantity | Lowest standard | Goal (D108) |
| --- | --- | --- |
| Number of queries | ±5% | ±2% |
| Rows examined | ±20% | ±10% |
| Peak memory (reported, does not decide) | ±30% | ±30% |

Relative error is `|estimate − measured| / measured`; when the measured value is 0, the estimate counts as a match if it is below 0.5. A route exactly on the tolerance passes.

**Marked route** (D122): a route with at least one statement marked `plan_changes_at_scale` or `plan_unstable` in the EXPLAIN of the 200,000-row database. The list is made mechanically from the EXPLAIN output and was committed with the estimates; no measured value went into it.

**Rule B (decides the test):** an unmarked route is compared with its single cold value; a marked route is compared with the largest of all cold values measured at 200,000 rows (the single one plus three more). **Rule A (shown, does not decide):** every route is compared with its single cold value, as in the first run. The point estimate is used in both rules; the upper side is not (section 7.1 of the spec).

Pass line (D71, D95): the share of routes within the lowest standard, counted per quantity, is 80% or more for **both** the number of queries and the rows examined. The result is undecidable, and not a pass, when a quantity has fewer than 5 routes in its denominator or when more than 20% of the routes with an estimate could not be measured. The denominator of a quantity is the routes that have an estimate for it and whose cold request at 200,000 rows was measured; the 12 routes whose empty-system request was `not_found` are in it (D105, estimated from N = 10). Routes outside it are listed in section 5.

**Failed cold tries (D126 and the project owner's addition to it).** Under rule B a marked route is compared with the largest of the cold values that exist. A try that failed (HTTP 422, HTTP 500, an exception) is dropped as a try without a value, and the number of values used (n of 4) is written for every marked route. If all four tries failed, the route is not measured. A try that ended at the 600-second cut of one request is different: a marked route with at least one such try is not dropped from rule B but recorded as "not measured, 600 seconds or more". The failed tries are counted by what the record says, not by the class name the runner gave it: the comparison counts a record whose detail says "the request ran longer than 600 seconds" as a timeout, and lists every case where the class name and the detail differ (section 5). This reading was fixed (D126) before the comparison was made.

Shares are given for all routes, for unmarked routes and for marked routes, each against the lowest standard and against the goal.

## 1. Procedure and the commits that fixed the estimates

The order is what makes the test valid: the estimates and the list of marked routes are committed before the measurement at 200,000 rows is started. If either measurement had started earlier, the test would be void for that target.

1. Capture the statements of every route (the 12 `not_found` routes from N = 10; the others as in the first run).
2. EXPLAIN at 200,000 and at 10,000 rows in three states of the statistics (`bin/explain-at-scale`).
3. Point estimates (H1), upper sides for marked statements (H2 value), list of marked routes (`bin/estimate`, `bin/marked-routes`).
4. Commit the estimates and the list of marked routes.
5. Measure every route cold once at 200,000 rows (`--warm=off`, same profile as the first run, 600 seconds for one request).
6. Measure the marked routes cold three more times on a 200,000-row database.
7. Compare.

| | |
| --- | --- |
| Commit that fixed the estimates and the list of marked routes (step 4) | `62ef51e` |
| Commit time | 2026-11-09T08:45:44+09:00 |
| Files in it | `docs/research/extrapolation-data-2/{fixtures,bookstack}/{fits,predictions,estimate,marked-routes}.json` |
| Step 5 started, verification app | 2026-11-09T08:49:09+09:00 (3 min 25 s after the commit) |
| Step 5 started, BookStack (first run) | 2026-11-09T10:04:22+09:00 |
| Step 6 started (BookStack; the verification app has no marked route) | 2026-11-09T08:50:14+09:00 (4 min 30 s after the commit) |
| Comparison | script `extrapolation-data-2/compare_test2.py`; output `extrapolation-data-2/compare.json` and `compare.md` |

The start times are read from the records the runner wrote. Both measurements started after the commit. The files committed at `62ef51e` are byte-identical to the files the comparison reads (checked with `cmp` before the comparison); nothing in the estimates or the marked list changed after the commit.

### Runs of the measurement

| Step | Target | Tool commit | Time of the run | End |
| --- | --- | --- | --- | --- |
| 5 | Verification app | `62ef51e` | 2026-11-09T08:49:09+09:00 to 10:04:22+09:00 | exit 0 |
| 5 | BookStack, first run | `62ef51e` | 2026-11-09T10:04:22+09:00 to 10:20:38+09:00 | stopped at once: the inspection database could not be built within its 900-second limit; no measurement record; nothing from it is used |
| 5 | BookStack, run again | `ca14780` | 2026-11-09T11:55:15+09:00 to 14:36:36+09:00 | exit 0 (63 routes measured at 200,000 rows) |
| 6 | BookStack, first launch | | 2026-11-09T08:49:50+09:00 | ended at once on a wrong option (a name over 21 characters); nothing was built or measured |
| 6 | BookStack, three laps of the 20 marked routes | `c55f23a` | 2026-11-09T08:50:14+09:00 to 11:51:50+09:00 | exit 0 |
| 6 | BookStack, three laps of three PUT routes, again | `c55f23a` | 2026-11-09T14:40:19+09:00 to 14:41:07+09:00 | exit 0 |

What differs between those tool commits, and why it does not change a measured value:

- **`62ef51e` to `ca14780`**: one constant, the time allowed for building the measurement database, raised from 900 to 3,600 seconds (D125). The data, the SQL, the profile, the 600-second limit for one request and the way of measuring are unchanged. The BookStack run of step 5 started after that change was committed (10:28:14).
- **`c55f23a` to `62ef51e`** (the tool of step 6): the changes in the measuring code are only how `--only` names a route (`METHOD uri`, D123); the rest is the estimation code, which step 6 does not use.
- Step 6 ran at the same time as step 5 of the verification app (08:50 to 10:04), on separate databases. The quantities compared (queries, rows examined, peak memory of one request) are counts and sizes, not times, so the concurrent run is not expected to change them; the record is stated here so that it can be checked.
- The three PUT routes got HTTP 422 in all three extra laps of the first run of step 6 (the request body had no `name`). They were measured again with a body added (same tool, same arguments, a new database). The first three laps are kept as failed tries and are **not used** for any value; the table in section 4 lists which tries were used.
- At 11:46 a trial run of the comparison script was made on the real 200,000-row records of the verification app, before the way of reading failed tries was fixed in the repository (D126, committed 11:58:01). That app has no marked route, so D126 does not touch its figures. A second trial of the script on the BookStack records was made at 14:41, after the reading was fixed. No estimate, list, rule, denominator or pass line was changed after either trial; the script was changed only to count failed tries by their detail and to print the tries used.

Where the numbers come from (kept outside the repository): the records of step 5 for each target (`attempts.jsonl`), the records of step 6 (`attempt.jsonl`, one per route and lap) and the time records. `extrapolation-data-2/input_sha256.txt` lists the SHA-256 of every record file the comparison read.

How to rebuild the comparison from the records (reads only, the same input always gives the same output):

```
python3 -I compare_test2.py --out <dir> \
  --app fixtures  <estimate.json> <marked-routes.json> <measurement dir> <empty dir> \
  --app bookstack <estimate.json> <marked-routes.json> <measurement dir> <step 6 dir 1>,<step 6 dir 2>
```

## 2. Result: share within tolerance

Source: `extrapolation-data-2/compare.json` at N = 200,000. The estimates compared are the ones committed at `62ef51e`.

### 2.1 Verdict

| Scope | Rule B (decides) | Rule A (shown) |
| --- | --- | --- |
| Verification app | **pass** | pass |
| BookStack | **fail** | fail |
| Both applications together | **fail** | fail |

Rule B, with the counts that decide:

- Verification app: queries: 22 of 22 inside +-5% (enough), needs 80%; rows_examined: 21 of 22 inside +-20% (enough), needs 80%
- BookStack: queries: 32 of 34 inside +-5% (enough), needs 80%; rows_examined: 22 of 34 inside +-20% (not enough), needs 80%
- Both applications together: queries: 54 of 56 inside +-5% (enough), needs 80%; rows_examined: 43 of 56 inside +-20% (not enough), needs 80%

How the verdict is read: pass needs both quantities at 80% or more and no undecidable condition. No undecidable condition was hit in any scope: every denominator has more than 5 routes, and the routes with an estimate that could not be measured are 2 of 24 (verification app), 1 of 35 (BookStack) and 3 of 59 (together), all below 20%. The two applications are scored separately as in the first run; the pooled figure is shown beside them. A failing verdict is not repaired here: the pass line, the tolerances, the denominator, the estimates and the list of marked routes are not touched (D71).

### 2.2 Rule B: share within tolerance, by group

| Scope | Group | Quantity | Denominator | Within lowest standard | Share | Within goal | Share | Estimated but not measured |
| --- | --- | --- | ---: | ---: | ---: | ---: | ---: | ---: |
| Verification app | all routes | Number of queries | 22 | 22 | 100.00% | 22 | 100.00% | 2 |
| Verification app | all routes | Rows examined | 22 | 21 | 95.45% | 19 | 86.36% | 2 |
| Verification app | all routes | Peak memory (reported only) | 22 | 22 | 100.00% | 22 | 100.00% | 2 |
| Verification app | unmarked | Number of queries | 22 | 22 | 100.00% | 22 | 100.00% | 2 |
| Verification app | unmarked | Rows examined | 22 | 21 | 95.45% | 19 | 86.36% | 2 |
| Verification app | unmarked | Peak memory (reported only) | 22 | 22 | 100.00% | 22 | 100.00% | 2 |
| Verification app | marked | Number of queries | 0 | 0 | n/a | 0 | n/a | 0 |
| Verification app | marked | Rows examined | 0 | 0 | n/a | 0 | n/a | 0 |
| Verification app | marked | Peak memory (reported only) | 0 | 0 | n/a | 0 | n/a | 0 |
| BookStack | all routes | Number of queries | 34 | 32 | 94.12% | 31 | 91.18% | 1 |
| BookStack | all routes | Rows examined | 34 | 22 | 64.71% | 22 | 64.71% | 1 |
| BookStack | all routes | Peak memory (reported only) | 34 | 22 | 64.71% | 22 | 64.71% | 1 |
| BookStack | unmarked | Number of queries | 15 | 15 | 100.00% | 15 | 100.00% | 0 |
| BookStack | unmarked | Rows examined | 15 | 12 | 80.00% | 12 | 80.00% | 0 |
| BookStack | unmarked | Peak memory (reported only) | 15 | 14 | 93.33% | 14 | 93.33% | 0 |
| BookStack | marked | Number of queries | 19 | 17 | 89.47% | 16 | 84.21% | 1 |
| BookStack | marked | Rows examined | 19 | 10 | 52.63% | 10 | 52.63% | 1 |
| BookStack | marked | Peak memory (reported only) | 19 | 8 | 42.11% | 8 | 42.11% | 1 |
| Both applications together | all routes | Number of queries | 56 | 54 | 96.43% | 53 | 94.64% | 3 |
| Both applications together | all routes | Rows examined | 56 | 43 | 76.79% | 41 | 73.21% | 3 |
| Both applications together | all routes | Peak memory (reported only) | 56 | 44 | 78.57% | 44 | 78.57% | 3 |
| Both applications together | unmarked | Number of queries | 37 | 37 | 100.00% | 37 | 100.00% | 2 |
| Both applications together | unmarked | Rows examined | 37 | 33 | 89.19% | 31 | 83.78% | 2 |
| Both applications together | unmarked | Peak memory (reported only) | 37 | 36 | 97.30% | 36 | 97.30% | 2 |
| Both applications together | marked | Number of queries | 19 | 17 | 89.47% | 16 | 84.21% | 1 |
| Both applications together | marked | Rows examined | 19 | 10 | 52.63% | 10 | 52.63% | 1 |
| Both applications together | marked | Peak memory (reported only) | 19 | 8 | 42.11% | 8 | 42.11% | 1 |

### 2.3 Rule A: share within tolerance, by group

Rule A (shown, does not decide):

- Verification app: queries: 22 of 22 inside +-5% (enough), needs 80%; rows_examined: 21 of 22 inside +-20% (enough), needs 80%
- BookStack: queries: 32 of 34 inside +-5% (enough), needs 80%; rows_examined: 20 of 34 inside +-20% (not enough), needs 80%
- Both applications together: queries: 54 of 56 inside +-5% (enough), needs 80%; rows_examined: 41 of 56 inside +-20% (not enough), needs 80%

| Scope | Group | Quantity | Denominator | Within lowest standard | Share | Within goal | Share | Estimated but not measured |
| --- | --- | --- | ---: | ---: | ---: | ---: | ---: | ---: |
| Verification app | all routes | Number of queries | 22 | 22 | 100.00% | 22 | 100.00% | 2 |
| Verification app | all routes | Rows examined | 22 | 21 | 95.45% | 19 | 86.36% | 2 |
| Verification app | all routes | Peak memory (reported only) | 22 | 22 | 100.00% | 22 | 100.00% | 2 |
| Verification app | unmarked | Number of queries | 22 | 22 | 100.00% | 22 | 100.00% | 2 |
| Verification app | unmarked | Rows examined | 22 | 21 | 95.45% | 19 | 86.36% | 2 |
| Verification app | unmarked | Peak memory (reported only) | 22 | 22 | 100.00% | 22 | 100.00% | 2 |
| Verification app | marked | Number of queries | 0 | 0 | n/a | 0 | n/a | 0 |
| Verification app | marked | Rows examined | 0 | 0 | n/a | 0 | n/a | 0 |
| Verification app | marked | Peak memory (reported only) | 0 | 0 | n/a | 0 | n/a | 0 |
| BookStack | all routes | Number of queries | 34 | 32 | 94.12% | 31 | 91.18% | 1 |
| BookStack | all routes | Rows examined | 34 | 20 | 58.82% | 20 | 58.82% | 1 |
| BookStack | all routes | Peak memory (reported only) | 34 | 22 | 64.71% | 22 | 64.71% | 1 |
| BookStack | unmarked | Number of queries | 15 | 15 | 100.00% | 15 | 100.00% | 0 |
| BookStack | unmarked | Rows examined | 15 | 12 | 80.00% | 12 | 80.00% | 0 |
| BookStack | unmarked | Peak memory (reported only) | 15 | 14 | 93.33% | 14 | 93.33% | 0 |
| BookStack | marked | Number of queries | 19 | 17 | 89.47% | 16 | 84.21% | 1 |
| BookStack | marked | Rows examined | 19 | 8 | 42.11% | 8 | 42.11% | 1 |
| BookStack | marked | Peak memory (reported only) | 19 | 8 | 42.11% | 8 | 42.11% | 1 |
| Both applications together | all routes | Number of queries | 56 | 54 | 96.43% | 53 | 94.64% | 3 |
| Both applications together | all routes | Rows examined | 56 | 41 | 73.21% | 39 | 69.64% | 3 |
| Both applications together | all routes | Peak memory (reported only) | 56 | 44 | 78.57% | 44 | 78.57% | 3 |
| Both applications together | unmarked | Number of queries | 37 | 37 | 100.00% | 37 | 100.00% | 2 |
| Both applications together | unmarked | Rows examined | 37 | 33 | 89.19% | 31 | 83.78% | 2 |
| Both applications together | unmarked | Peak memory (reported only) | 37 | 36 | 97.30% | 36 | 97.30% | 2 |
| Both applications together | marked | Number of queries | 19 | 17 | 89.47% | 16 | 84.21% | 1 |
| Both applications together | marked | Rows examined | 19 | 8 | 42.11% | 8 | 42.11% | 1 |
| Both applications together | marked | Peak memory (reported only) | 19 | 8 | 42.11% | 8 | 42.11% | 1 |

### 2.4 Rule A and rule B side by side

Only marked routes can differ between the two rules, and only where the largest of the four cold values is not the single cold value. For rows examined, six of the 19 marked BookStack routes in the denominator have a largest value that differs from the single one. Their errors under each rule:

| Route | Quantity | Single cold value | Largest cold value | Error against single | Error against largest | Inside lowest standard, rule A | Rule B |
| --- | --- | ---: | ---: | ---: | ---: | --- | --- |
| `DELETE /books/{bookSlug}/chapter/{chapterSlug}` | rows examined | 1,400,507 | 1,700,461 | 21.42% | 0.00% | no | yes |
| `DELETE /books/{bookSlug}/draft/{pageId}` | rows examined | 1,040,454 | 1,700,455 | 63.44% | 0.00% | no | yes |
| `DELETE /books/{bookSlug}/page/{pageSlug}` | rows examined | 1,040,459 | 1,190,461 | 63.44% | 42.84% | no | no |
| `GET /books/{bookSlug}/chapter/{chapterSlug}` | rows examined | 440,239 | 1,570,230 | 799.84% | 152.28% | no | no |
| `GET /books/{bookSlug}/page/{pageSlug}` | rows examined | 840,237 | 1,570,230 | 371.47% | 152.28% | no | no |
| `POST /settings/recycle-bin/empty` | rows examined | 1,550,466 | 1,700,468 | 9.68% | 0.00% | yes | yes |

Two routes move from outside to inside the lowest standard under rule B (`DELETE /books/{bookSlug}/chapter/{chapterSlug}`, `DELETE /books/{bookSlug}/draft/{pageId}`), because the single cold value was a low one and the largest of the four is the one the estimate matches. The other four do not change side. The effect of rule B on the verdict is two routes of rows examined on BookStack (22 inside against 20 under rule A) and none on the verdict itself.

## 3. Routes with the largest error (top 10 per target, rule B)

Ranked by relative error over the entries (route and quantity) in the denominators of the two quantities that decide the test (queries and rows examined). "Estimate" is the committed point estimate (H1 for rows examined); for a marked route "measured" is the largest of its cold values (rule B).

**Verification app** (44 entries: 22 routes for each of the two quantities)

| # | Route | Quantity | Marked | Estimate | Measured | Relative error | Inside lowest standard | Inside goal |
| ---: | --- | --- | --- | ---: | ---: | ---: | --- | --- |
| 1 | `GET /ok/with-count` | rows examined | no | 1,086,007.8 | 1,800,003 | 39.67% | no | no |
| 2 | `GET /ok/chunk` | rows examined | no | 179,741,997.0 | 200,302,002 | 10.26% | yes | no |
| 3 | `GET /ok/lazy` | rows examined | no | 179,741,997.0 | 200,302,002 | 10.26% | yes | no |
| 4 | `GET /hidden/appends` | queries | no | 200,001 | 200,001 | 0.00% | yes | yes |
| 5 | `GET /hidden/appends` | rows examined | no | 400,003 | 400,003 | 0.00% | yes | yes |
| 6 | `GET /hidden/blade` | queries | no | 200,001 | 200,001 | 0.00% | yes | yes |
| 7 | `GET /hidden/blade` | rows examined | no | 400,003 | 400,003 | 0.00% | yes | yes |
| 8 | `GET /hidden/cache` | queries | no | 200,001 | 200,001 | 0.00% | yes | yes |
| 9 | `GET /hidden/cache` | rows examined | no | 400,003 | 400,003 | 0.00% | yes | yes |
| 10 | `GET /hidden/conditional-binding` | queries | no | 200,001 | 200,001 | 0.00% | yes | yes |

Three entries have a non-zero error; the other 41 have an error of exactly 0, so ranks 4 to 10 are in route order and their order carries no meaning. `GET /ok/chunk` and `GET /ok/lazy` are inside the lowest standard (10.26%) but outside the goal.

**BookStack** (68 entries: 34 routes for each of the two quantities)

| # | Route | Quantity | Marked | Estimate | Measured | Relative error | Inside lowest standard | Inside goal |
| ---: | --- | --- | --- | ---: | ---: | ---: | --- | --- |
| 1 | `POST /books/{bookSlug}/draft/{pageId}` | rows examined | no | 5,365,171.1 | 240,554 | 2130.34% | no | no |
| 2 | `GET /books/{bookSlug}/page/{pageSlug}` | rows examined | yes | 3,961,435.9 | 1,570,230 | 152.28% | no | no |
| 3 | `GET /books/{bookSlug}/chapter/{chapterSlug}` | rows examined | yes | 3,961,433.1 | 1,570,230 | 152.28% | no | no |
| 4 | `GET /books/{bookSlug}/export/zip` | rows examined | yes | 4,201,098.5 | 1,670,196 | 151.53% | no | no |
| 5 | `GET /shelves/{slug}` | rows examined | yes | 701.8 | 250,226 | 99.72% | no | no |
| 6 | `PUT /books/{slug}` | rows examined | no | 5,934,428.3 | 2,990,232 | 98.46% | no | no |
| 7 | `POST /settings/maintenance/regenerate-references` | rows examined | yes | 67,828,842.2 | 257,698,605 | 73.68% | no | no |
| 8 | `PUT /books/{bookSlug}/chapter/{chapterSlug}` | rows examined | yes | 70,230 | 230,232 | 69.50% | no | no |
| 9 | `PUT /books/{bookSlug}/page/{pageSlug}/revisions/{revId}/restore` | rows examined | yes | 70,244 | 230,246 | 69.49% | no | no |
| 10 | `PUT /books/{bookSlug}/page/{pageSlug}` | rows examined | yes | 70,255 | 230,259 | 69.49% | no | no |

The ten largest are all entries of rows examined; the two misses of queries (`GET /shelves/{slug}`, 8.44%, and `POST /settings/maintenance/regenerate-references`, 26.26%) are further down the ranking. Twelve entries of rows examined are outside the lowest standard under rule B (listed in section 2.4 and above; one marked route, `GET /shelves/{slug}`, also misses queries).

## 4. Marked routes: the point and the upper side against the cold values

For each marked BookStack route (20 routes; the verification app has none), the H1 point and the upper side (the H2 value of the marked statements) against the smallest and the largest of the cold values measured at 200,000 rows, and whether the point and the upper side are below, inside or above that range. "Cold values used" is the number of values that exist among the four (one in step 5 and three in step 6).

| Route | Point (H1) | Upper side (H2) | Cold min | Cold max | Cold values used | Point against the cold range | Upper side against the cold range | Range enclosed by point and upper side |
| --- | ---: | ---: | ---: | ---: | --- | --- | --- | --- |
| `DELETE /books/{bookSlug}/chapter/{chapterSlug}` | 1,700,513 | 5,287,558.2 | 1,040,458 | 1,700,461 | 4 of 4 | above | above | no |
| `DELETE /books/{bookSlug}/draft/{pageId}` | 1,700,507 | 5,287,453.5 | 1,040,454 | 1,700,455 | 4 of 4 | above | above | no |
| `DELETE /books/{bookSlug}/page/{pageSlug}` | 1,700,512 | 5,287,359.8 | 1,040,459 | 1,190,461 | 4 of 4 | above | above | no |
| `DELETE /books/{id}` | 1,700,527 | 5,287,670.9 | 1,040,500 | 1,550,501 | 4 of 4 | above | above | no |
| `DELETE /shelves/{slug}` | 1,764,540.0 | 5,351,782.6 | 1,040,451 | 1,700,456 | 4 of 4 | above | above | no |
| `GET /books/{bookSlug}/chapter/{chapterSlug}` | 3,961,433.1 | 3,961,433.1 | 440,239 | 1,570,230 | 4 of 4 | above | above | no |
| `GET /books/{bookSlug}/chapter/{chapterSlug}/references` | 70,215 | 456,334.1 | 70,215 | 70,215 | 4 of 4 | inside | above | yes |
| `GET /books/{bookSlug}/export/zip` | 4,201,098.5 | 4,222,683.8 | 1,670,196 | 1,670,196 | 4 of 4 | above | above | no |
| `GET /books/{bookSlug}/page/{pageSlug}` | 3,961,435.9 | 3,961,435.9 | 840,237 | 1,570,230 | 4 of 4 | above | above | no |
| `GET /books/{bookSlug}/page/{pageSlug}/references` | 80,218 | 575,382.8 | 80,218 | 80,218 | 4 of 4 | inside | above | yes |
| `GET /books/{slug}` | 7,273,953.5 | 7,273,953.5 | n/a | n/a | 0 of 4 | n/a | n/a | n/a |
| `GET /books/{slug}/references` | 70,210 | 548,577.3 | 70,210 | 70,210 | 4 of 4 | inside | above | yes |
| `GET /shelves/{slug}` | 701.8 | 701.8 | 250,226 | 250,226 | 4 of 4 | below | below | no |
| `GET /shelves/{slug}/references` | 70,210 | 545,459.7 | 70,210 | 70,210 | 4 of 4 | inside | above | yes |
| `POST /settings/maintenance/regenerate-references` | 67,828,842.2 | 144,064,875.3 | 257,698,605 | 257,698,605 | 4 of 4 | below | below | no |
| `POST /settings/recycle-bin/empty` | 1,700,491 | 5,287,240.1 | 1,040,467 | 1,700,468 | 4 of 4 | above | above | no |
| `PUT /books/{bookSlug}/chapter/{chapterSlug}` | 70,230 | 395,176.5 | 230,232 | 230,232 | 4 of 4 | below | above | yes |
| `PUT /books/{bookSlug}/page/{pageSlug}` | 70,255 | 415,424.0 | 230,257 | 230,259 | 4 of 4 | below | above | yes |
| `PUT /books/{bookSlug}/page/{pageSlug}/revisions/{revId}/restore` | 70,244 | 415,413.0 | 230,246 | 230,246 | 4 of 4 | below | above | yes |
| `PUT /shelves/{slug}` | 236,409.2 | 1,230,766.6 | 230,232 | 230,232 | 4 of 4 | above | above | no |

Of the 19 marked routes with at least one cold value: the point is inside the cold range for 4, below it for 5, above it for 10. The upper side is inside the range for 0, below it for 2, above it for 17. The range [point, upper side] encloses the whole measured cold range for 7 of 19. `GET /books/{slug}` has no cold value (500 in all four tries) and is not in the table's counts.

The spread of the four cold values is wide for several routes (for example `DELETE /books/{bookSlug}/chapter/{chapterSlug}`: 1,040,458 to 1,700,461), and zero for others (for example the four `references` routes: identical in all four tries).

### Tries used for the three PUT routes

| Route | Run | State | Result | Rows examined |
| --- | --- | --- | --- | ---: |
| `PUT /books/{bookSlug}/chapter/{chapterSlug}` | measurement run 1 | used | ok | 230,232 |
| `PUT /books/{bookSlug}/chapter/{chapterSlug}` | variance run 2 | used | ok | 230,232 |
| `PUT /books/{bookSlug}/chapter/{chapterSlug}` | variance run 2 | used | ok | 230,232 |
| `PUT /books/{bookSlug}/chapter/{chapterSlug}` | variance run 2 | used | ok | 230,232 |
| `PUT /books/{bookSlug}/chapter/{chapterSlug}` | variance run 1 | superseded (not used) | 422 | n/a |
| `PUT /books/{bookSlug}/chapter/{chapterSlug}` | variance run 1 | superseded (not used) | 422 | n/a |
| `PUT /books/{bookSlug}/chapter/{chapterSlug}` | variance run 1 | superseded (not used) | 422 | n/a |
| `PUT /books/{bookSlug}/page/{pageSlug}` | measurement run 1 | used | ok | 230,259 |
| `PUT /books/{bookSlug}/page/{pageSlug}` | variance run 2 | used | ok | 230,257 |
| `PUT /books/{bookSlug}/page/{pageSlug}` | variance run 2 | used | ok | 230,257 |
| `PUT /books/{bookSlug}/page/{pageSlug}` | variance run 2 | used | ok | 230,257 |
| `PUT /books/{bookSlug}/page/{pageSlug}` | variance run 1 | superseded (not used) | 422 | n/a |
| `PUT /books/{bookSlug}/page/{pageSlug}` | variance run 1 | superseded (not used) | 422 | n/a |
| `PUT /books/{bookSlug}/page/{pageSlug}` | variance run 1 | superseded (not used) | 422 | n/a |
| `PUT /books/{bookSlug}/page/{pageSlug}/revisions/{revId}/restore` | measurement run 1 | used | ok | 230,246 |
| `PUT /books/{bookSlug}/page/{pageSlug}/revisions/{revId}/restore` | variance run 1 | used | ok | 230,246 |
| `PUT /books/{bookSlug}/page/{pageSlug}/revisions/{revId}/restore` | variance run 1 | used | ok | 230,246 |
| `PUT /books/{bookSlug}/page/{pageSlug}/revisions/{revId}/restore` | variance run 1 | used | ok | 230,246 |
| `PUT /shelves/{slug}` | measurement run 1 | used | ok | 230,232 |
| `PUT /shelves/{slug}` | variance run 2 | used | ok | 230,232 |
| `PUT /shelves/{slug}` | variance run 2 | used | ok | 230,232 |
| `PUT /shelves/{slug}` | variance run 2 | used | ok | 230,232 |
| `PUT /shelves/{slug}` | variance run 1 | superseded (not used) | 422 | n/a |
| `PUT /shelves/{slug}` | variance run 1 | superseded (not used) | 422 | n/a |
| `PUT /shelves/{slug}` | variance run 1 | superseded (not used) | 422 | n/a |

### Failed cold tries by class

Counted by what the record says (section 0):

| Target | Tries | timeout (600 seconds) | 422 | 500 | exception | other |
| --- | --- | ---: | ---: | ---: | ---: | ---: |
| Verification app | failed, rule B reads them as without a value | 1 | 0 | 1 | 0 | 0 |
| Verification app | failed, replaced by a measurement run again (not used) | 0 | 0 | 0 | 0 | 0 |
| BookStack | failed, rule B reads them as without a value | 0 | 0 | 4 | 0 | 0 |
| BookStack | failed, replaced by a measurement run again (not used) | 0 | 9 | 0 | 0 | 0 |

The 9 tries of class 422 are the first three laps of the three PUT routes (the body had no `name`); they were replaced by the run again and are not used. The 4 tries of class 500 on BookStack are all four tries of `GET /books/{slug}` (MySQL's limit of 65,535 placeholders in one prepared statement, SQLSTATE 1390). On the verification app the timeout is `GET /ng/first-or-create-in-loop` (one request over 600 seconds, unmarked, so it is a route that could not be measured, "600 seconds or more") and the 500 is `POST /ok/bulk` (the same placeholder limit). No marked route had a timeout, so the rule "a timeout makes a marked route not measured" did not apply to any route.

## 5. Routes outside the denominator

Nothing is dropped silently.

- **Verification app (2 of 24 routes with an estimate, not measured):** `GET /ng/first-or-create-in-loop` (the request ran longer than 600 seconds: "600 seconds or more") and `POST /ok/bulk` (HTTP 500, placeholder limit). All three quantities of both are outside their denominators.
- **BookStack (1 of 35):** `GET /books/{slug}` (marked): HTTP 500 in all four tries (placeholder limit), so there is no cold value for any quantity.
- **No estimate:** none of the routes in either denominator lacks an estimate. BookStack's measurement has 63 routes; the 28 without an estimate row (authentication, validation errors and similar) are outside the test as in the first run.
- **Class name against detail.** The tool records a request that ran past the 600-second limit as class `timeout` on the verification app, but as class `exception` (status 500) on BookStack's `POST /books/{bookSlug}/copy`, whose detail reads "the request ran longer than 600 seconds". The comparison counts it as a timeout by its detail. The route has no estimate row (D124), so it is outside every denominator and the verdict does not depend on it; it is recorded here as "600 seconds or more". A mechanical check of every record of class `exception` in the BookStack measurement (10 records, at scales 0 and 200,000) found one inside a denominator (`GET /books/{slug}`, the placeholder limit, not a timeout); no route inside a denominator has a timeout recorded under another class name.

Two routes of BookStack, `POST /books/{bookSlug}/copy` and `GET /import/{id}`, also had an empty-system point that is `not_found` at scale 0 (the last attempt at scale 0 is `not_found`, an empty system). They fit the kind of exception that D105 describes. The denominator is not changed by this: it is counted from the list of 12 routes in D105 (with D123 and D124), as in the first run, and these two stay outside it. At 200,000 rows `POST /books/{bookSlug}/copy` ended at the 600-second limit, and `GET /import/{id}` answered HTTP 500 (an exception reading a property of a missing record); neither is in the shares.

### Two disclosures added after the result

These two notes were added after the comparison and after a check of this file. No result, verdict, table figure, rule or denominator above was changed by them.

**1. How the denominator of the number of queries was read.** D95 says that a route whose route-level formula is `contradicts_static` is left out of the denominator of that quantity. This test used the estimate of version 0.2 for the number of queries, which is the sum over the statement types of the route of the count of each type, for every route. The route-level formula of 14 BookStack routes is `contradicts_static` or `not_predicted`; 13 of them are in the denominator (`GET /books/{slug}` was not measured). These 13 were counted in the denominator of the number of queries. This reading was not written down before the comparison. There is a case for it, because the sum over the statement types is not the route-level formula, and it is the stricter side: it adds routes to the denominator. The other reading leaves those 13 routes out. Both readings, rule B:

| Scope | Queries inside ±5%, as counted in this file | Queries inside ±5%, those 13 routes left out | Pass line (80%) under each reading |
| --- | --- | --- | --- |
| Verification app | 22 of 22 | 22 of 22 | met under both |
| BookStack | 32 of 34 (94.12%) | 20 of 21 (95.24%) | met under both |
| Both applications together | 54 of 56 (96.43%) | 42 of 43 (97.67%) | met under both |

Of the 13 routes, 12 are inside the lowest standard and one is not (`GET /shelves/{slug}`); the other miss of the number of queries, `POST /settings/maintenance/regenerate-references`, is not one of them. The rows examined are not affected, because a route left out of one quantity stays in the other (D95). The verdicts are the same under both readings, because the deciding quantity is the rows examined, which is below the line in the scopes that fail. Which reading is the correct one is left to the project owner's decision. This file keeps the stricter reading.

**2. A defect in one record of the measuring tool.** For `GET /books/{bookSlug}/export/zip`, the record of the three extra laps of the marked routes (`attempt.jsonl`) has the body of each response (the ZIP file, about 1.1 MB each) written as raw bytes in front of the record lines, so the file cannot be read as JSON Lines: in the first lap's file, for example, 2 of 5,850 lines are JSON. The record lines themselves are valid JSON, and the values can be read by picking up only those lines; the rows examined used here (1,670,196 in every try) were read that way. The tool should keep the body of a response out of its record file. This is a candidate for a later fix and nothing was changed in the tool for this test.

## 6. What can be said, and what cannot

Written before the result.

What the test is:

- It checks whether the per-statement fit (H1), made from small-size measurements, predicts the number of queries, the rows examined and the peak memory of a cold request at 200,000 rows. The estimates and the list of marked routes were committed before the measurement they are compared with (section 1).
- The pass line, the tolerances and the denominator are those of the first run (D71, D95, D105) and are not moved by the result. The gate of the development check (37 of 46) was not reached; the test is run by the project owner's decision (D121), not because the gate was passed.

What it does not support:

- **Two applications only.** The small verification app written for this project, and BookStack with a data profile written for the test. Nothing here is a statement about other Laravel applications.
- **Synthetic data.** Skew, hot rows and cache behaviour of a real system are not reproduced.
- **A single size, 200,000 rows.** One size not yet seen; it says nothing about 2,000,000.
- **Few repeats.** Unmarked routes are measured once. Marked routes are measured four times, on one database for the three extra values; the four values are not an estimate of the full spread, and a route near a tolerance could fall on either side on another run.
- **Rule B compares marked routes with the heaviest of four values.** That is a decision of the spec (D107, D122) and it is stricter for a marked route that is light on most runs. Rule A is shown beside it for that reason.
- **The marking depends on EXPLAIN of this database in three states of the statistics.** A route that is not marked is not shown to be stable.
- **The point of rows examined is a fit, not a reading of the plan.** EXPLAIN gives only the marks and the upper side, and the upper side is not part of the pass line.
- **Peak memory is reported only** and does not decide the test.
- **The denominator is what could be estimated and measured.** Routes without an estimate (authentication, exceptions, validation errors) are out of the test; routes that failed at 200,000 rows are out of the denominator and are listed in section 5.
- **The check of rows examined against the server's own count** was done on the verification app only in the first run (`docs/research/extrapolation-test.md`, section 5); it was not repeated here.

## 7. Conclusion

Facts for the second run of test A, with the estimates committed at `62ef51e` before the measurement and the pass line unchanged:

- **Rule B (decides):** verification app **pass** (queries 22 of 22, rows examined 21 of 22 inside the lowest standard); BookStack **fail** (queries 32 of 34, rows examined 22 of 34 = 64.71%, 28 needed); both applications together **fail** (queries 54 of 56 = 96.43%, rows examined 43 of 56 = 76.79%, 45 needed).
- **Rule A (shown):** the same verdicts (BookStack rows examined 20 of 34 = 58.82%; together 41 of 56 = 73.21%).
- **By group, rows examined inside the lowest standard (rule B):** all routes 43 of 56 (76.79%), unmarked 33 of 37 (89.19%), marked 10 of 19 (52.63%). Against the goal: all 41 of 56 (73.21%), unmarked 31 of 37 (83.78%), marked 10 of 19 (52.63%). Queries: all 54 of 56 (96.43%) inside the lowest standard, 53 of 56 (94.64%) inside the goal.
- **Largest errors:** all on BookStack except one on the verification app. The largest is `POST /books/{bookSlug}/draft/{pageId}` (rows examined, estimate 5,365,171 against 240,554 measured, an unmarked route); three marked routes that read a book, chapter or page are over-estimated by about 152%; and the three PUT routes are under-estimated by about 69.5% (estimate about 70,000 against about 230,000 measured). On the verification app the one miss is `GET /ok/with-count` (39.67%).
- **Marked routes:** the point lies above the measured cold range for 10 routes, below it for 5 and inside it for 4; the upper side lies above the range for 17 of 19; the range [point, upper side] encloses the cold range for 7 of 19. Marked routes are where the misses concentrate: 9 of the 12 BookStack misses of rows examined are marked routes (10 of 19 marked routes are inside, 12 of 15 unmarked ones).
- **Not measured:** 3 routes with an estimate (2 on the verification app, 1 on BookStack), none of them by a 600-second cut of a marked route.

What these results do not show is in the list of limits above.
