# Extrapolation test (A) on Bagisto: do the estimates predict 240,000 rows?

Status: **Final.** Sections 1 and 2 were written before any measured value was read and are changed here only where they said that something was still to be filled in (the first measurement in 2.1, the databases that kept their contents in 2.6, and the route of the first trial in 2.6). Sections 3 to 6 are the measurement, the result and its limits. The prediction was committed at 2026-11-24 13:04:39 (`207911e`); the first measurement started at 14:57:57 the same day.

This file is the record of test A of `docs/simulator-spec.md` (sections 7.1, 7.4 and 7.4.6) on the third application, Bagisto. It says what is tested and by which rules, in which order things were done, and what the numbers can and cannot support. It follows `docs/research/extrapolation-test-4.md` (the fourth run on BookStack at 240,000 rows), whose pass line, tolerances and denominator rules it uses without change. The preparation of the application is in `docs/research/bagisto-prep/README.md`; the estimate and the lists committed before the measurement are in `docs/research/extrapolation-data-bagisto/prediction/`.

What is checked is a **check against a size reproduced in a test setup**: an estimate compared with a measurement made on generated data. Nothing here is observed on a real deployment, and this file does not claim any precision on one.

**In brief (what is already fixed before any measurement):**

1. A is run at **240,000 rows**, which is 8 times the largest size the statements were captured at (30,000 rows). No build, estimate or measurement of this application at that count existed before this test (D248).
2. The verdict is **rule B, reading R1**; rule A and reading R2 are shown beside it and do not decide.
3. **"Rule B passes" and "condition 2 of the spec, part A, is met" are two different statements** (D250). The second needs the count on all 57 routes with a complete estimate and the count on the 19 routes whose data seems to have arrived to meet the same line (section 1.4).
4. The static analysis reaches few routes of this application (15 of 543). It was not fitted to Bagisto (section 1.5).
5. **The result.** Rule B, reading R1, **passes** on the 57 routes with a complete estimate: queries 54 of 56 inside ±5%, rows examined 52 of 56 inside ±20% (45 needed). The four numbers short of condition 1a are 0 on the 57, and also on the 19 routes whose data seems to have arrived, so **condition 2, part A, is met**. Two lines of the count on the 19 have **no spare** (rows examined 15 of 18; the unmarked routes' goal on queries 5 of 6). Rule A and reading R2 give the same verdict. Six routes are outside the goal; none has an identified cause. Part B of condition 2 (the lab backtest on Bagisto) has not been done (sections 4 and 5).
6. This test is the first in which the `offset_chunk_fit` mark could be tried. The estimate has **0 routes with that mark**, so the effect of the mark cannot be confirmed in this test (section 2.4).
7. The repeat of the marked routes was run on a **third database**, built apart from the cold measurement's database, and on **40** routes, not 41. Two earlier repeats stopped and are void (section 2.6). This differs from the fourth run on BookStack, whose repeat ran on the same database as its cold measurement.

## 1. What was tested

### 1.1 The three quantities and the lines

For each route, three quantities are estimated at N = 240,000 and the same cold request is then measured at N = 240,000, as in the fourth run.

- **Number of queries:** the sum over the statement types of the route of the count formula of each type.
- **Rows examined:** the sum over the statement types of the per-execution rows (a per-statement fit, H1, with the tangent rule above the capture's largest size) times the count.
- **Peak memory:** reported; it does not decide the test.

| Quantity | Lowest standard | Goal |
| --- | --- | --- |
| Number of queries | ±5% | ±2% |
| Rows examined | ±20% | ±10% |
| Peak memory (reported, does not decide) | ±30% | ±30% |

Relative error is `|estimate − measured| / measured`; when the measured value is 0, the estimate counts as a match if it is below 0.5. Where a route is outside a line, its multiple (measured ÷ predicted) is also given.

### 1.2 The rules this test applies (spec 7.4.6, D247)

- **Order.** Condition 2 of the spec asks for A and B on the third application. A is run first; B is decided separately.
- **Routes.** The routes measured are the 120 routes of the measured list (section 1.6). The routes counted in the denominator are those that were captured with a complete estimate (section 1.7).
- **Count.** 240,000 rows, the capture's largest size times 8, the same multiple as the fourth run on BookStack.
- **Pass line.** The line of condition 1 of the spec for A: at least 80% of the unmarked routes inside the goal, and at least 80% of all routes inside the lowest standard.
- **Pass.** Both quantities at 80% or more of the denominator inside the lowest standard, and no undecidable condition. **Undecidable:** a denominator of fewer than 5 routes, or more than 20% of the routes that have an estimate not measured.
- **Rule B decides, rule A is shown** (spec 7.1, D212). For an unmarked route the one cold value is compared. For a marked route (one whose plan may change with size) the largest of its four cold values is compared under rule B (the one cold value of the cold measurement and three more of the repeat), and the first cold value under rule A.
- **R1 gives the verdict** (D212): a route whose estimate is not `complete` is outside the denominator. R2, shown beside it, counts such a route as a route with an estimate. R2 does not decide.
- **Undecidable counts as "not met".** If the count is undecidable, condition 2 part A is counted as not met and is not treated as a pass. No route is added afterwards to make the denominator larger. The next step is the maintainer's.
- **Marks.** The rules of the day are used as they are: `plan_changes_at_scale`, `plan_unstable` and `offset_chunk_fit`.
- **Time-outs (D255).** A trial whose error output holds the measuring tool's stop message for the 600-second limit (`the request ran longer than 600 seconds`) counts as a time-out, even when the record calls it "exception 500". A marked route with one time-out in any of its trials is counted as not measured. The same reading is applied to the cold measurement. A script finds these trials; nobody picks them by eye. The routes and trials counted as time-outs are listed in section 4.6.
- **A cold failure that the repeat answers with ok (D256).** A marked route whose cold attempt failed (500 or the like) but whose repeat gave ok is counted as not measured. The cold measurement keeps no error output for each trial, so a time-out in it cannot be ruled out. The count as recorded is shown beside it and does not decide. The seven routes this can reach were listed from the cold measurement's own record before any repeat value was read (section 2.6, item 3).
- **Use of the repeat values (D257).** The values of the repeat are used. A marked route is compared by the largest of its cold value from the cold measurement and the three cold values of the repeat; an unmarked route by its one cold value. Which databases the values come from is in section 2.6.
- **Not changed.** The rule for the estimate, the marks, the calibration and the pass line are not changed. The static analysis is not fitted to Bagisto (D245).

### 1.3 Two ways of counting, and why they are kept apart (D250)

The rule B verdict is given on the **57** routes whose estimate is complete. What it looks at is the lowest standard for all routes (at least 80%); the goal line for the unmarked routes is not part of the rule B verdict.

So **"rule B passes" is not "condition 2 part A is met".** Condition 2 part A is counted as met only when both of the following hold:

1. in the count on the 57 routes, the four "numbers short" of condition 1a (D153) are all 0: the goal for the unmarked routes on the two quantities, and the lowest standard for all routes on the two quantities. The goal line for the unmarked routes is included here;
2. the count on the **19** routes whose data seems to have arrived (section 1.4) also meets the same two lines.

This report and any later text keep the two statements apart.

### 1.4 The count on the 19 routes whose data seems to have arrived (D250, D252)

In the capture, 38 of the 57 routes whose estimate can complete carry the capture's own note that the data may not have reached the route: the size of the answer and the rows the tables returned to the application are the same at every row count (rows returned, not rows read). A flat count is not evidence that such a route does not grow, and a pass of the count on 57 could rest on them. The other 19 routes are listed, as a second count, in `docs/research/extrapolation-data-bagisto/prediction/arrived-list/`.

- **How the list was made.** Mechanically from the capture's records, with no judgment in it: of the 57 routes, the 38 with the capture's note are taken out and 19 are left; the 6 routes whose estimate cannot complete (`partial`) are in neither list. The list was fixed before the estimate was made and is part of the prediction commit.
- **What is in it.** 13 of the 19 are marked routes and 6 are not (`GET /`, `GET /api/categories/attributes`, `GET /api/categories/attributes/{attribute_id}/options`, `GET /api/core/countries`, `GET /api/customer/addresses/allowed-countries`, `GET /api/customer/addresses/allowed-states`). The goal line of this count is decided on the unmarked routes, so it is decided on at most 6 routes.
- **How it is counted.** The unchanged `judge_a.py` is run on copies of the estimate and the marked list narrowed to the 19 routes (`count_listed.py`).
- **Both undecidable rules apply to it, as they do to the 57** (D252; D250 had written only the first). A quantity is undecidable if its denominator has fewer than 5 routes, or if more than 20% of the routes with an estimate could not be measured. On 19 routes the second rule is reached at **4** routes not measured; on 57 routes it is reached at **12**. An undecidable count does not meet the line.
- **No lower bound on the unmarked routes.** No minimum number of unmarked routes is set (D250); the number of unmarked routes in each count is written next to the count, every time.

### 1.5 How little the static analysis reaches here (D245)

The static analysis was not fitted to Bagisto, because fitting it after looking at the application would weaken the condition that the application was not seen before. The number that shows its effect is the count of routes in which it found at least one place that reads the database:

| Application | Routes with such a place | Routes in the analysis |
| --- | ---: | ---: |
| Bagisto | 15 | 543 |
| BookStack | 264 | 341 |

(Read again from the two analysis files; the BookStack file was taken with an earlier output schema, 0.1 against 0.2, on 2026-11-01.) The three gaps that stop most Bagisto routes (the repository layer, the view names of packages, the vendor helper functions and response types) are left as work after version 1.0. The estimate in this test therefore rests on the runtime capture and the EXPLAIN, and hardly at all on the static side.

### 1.6 How the 120 routes were chosen (D246)

The measured list is committed as `b6e7042`, before the capture was taken again and before any result of it. The rule and the files are in `docs/research/bagisto-prep/` (`select-routes.py`, `excluded.tsv`, `allocation.tsv`, `drawn.tsv`, `route-set-measure.txt`).

- **Population.** The 543 routes of the static analysis (a route is a URI with the method it answers).
- **Left out before the draw, and only these: 48.** Development-package routes 5, installer routes 5, payment-gateway return routes 13 (the page a gateway sends the buyer back to), forms of the login, register and contact kind 17, and pages or calls that show a visitor's own empty cart, compare list or checkout step 8. Each is in `excluded.tsv` with its reason. The rule first left out all 26 payment-gateway routes; that was a misreading and was corrected to the 13 return routes before the capture and before any result of it. The earlier commit is kept.
- **Groups and slots.** The storefront and customer side has 121 routes after the leaving out and 80 slots; the admin area has 374 and 40 slots; 120 in all. The storefront side is given more because the load of a busy season comes mostly from the buyers. Neither group had fewer routes than slots, so nothing was taken whole.
- **Draw.** Within a group the slots are shared among the HTTP methods in proportion to their number of routes, by largest remainder; within a method the routes are ordered by the SHA-256 of `20261124:<METHODS>:<URI>` and the first *k* are taken. The seed is 20261124. Running it again gives the same files, and shuffling the input does not change them.
- **Not applied.** The kinds of route that `reach-list.md` mentions but the rule does not name (routes that answer with a redirect or a file, pages whose rows come only from an ajax datagrid call) were **not** left out.

### 1.7 What the denominator is made of (D250)

The denominator is decided by the routes that have a complete estimate, not by how many routes are measured. A route measured without an estimate is listed and not counted. The 120 routes are measured as they are (D249, D250); the list is not narrowed to the routes the estimate covers.

| What | Routes |
| --- | ---: |
| Routes in the measured list | 120 |
| Routes in the capture record | 63 |
| **With a complete estimate (the denominator of the count on 57)** | **57** |
| of which marked by the plan (`plan_changes_at_scale` or `plan_unstable`) | 35 |
| of which not marked | 22 |
| of the 57, the 19 whose data seems to have arrived (section 1.4) | 19 |
| of those 19, not marked | 6 |
| Captured with status `partial`, so no complete estimate | 6 |
| of those 6: growth verdict `insufficient_points` (two sizes only, so no verdict could be made) | 5 |
| of those 6: growth verdict `measured_ON` (the cart-rules route) | 1 |

- **How 63 is made.** The 63 routes of the capture record are the 57 with a complete estimate (`measured_O1` 44, `no_info` 13) and the 6 `partial` routes (`measured_ON` 1, and 5 `insufficient_points`). The 5 `insufficient_points` routes are the five captured at two sizes only; they are among the 6, not in addition to them.
- **The 6 partial routes.** Five were captured at two of the four sizes only; the sixth, `GET /admin/marketing/promotions/cart-rules/edit/{id}`, answered HTTP 500 at 30,000 rows. As in the third run on BookStack (D212), **R1 decides** (such a route is outside the denominator and outside the undecidable count) and **R2 is shown beside it**. The sixth route is the one route that grew with the size in the capture (verdict `O(N)`; at 10,000 rows its answer was about 989 MB and its peak memory about 4.3 GB). R1 leaves out this heavy route, so the section that sets R1 beside R2 says that R1 is not a count with the hard routes left in.
- **Where the count becomes undecidable.** On 57 routes, 12 or more routes with an estimate not measured make a quantity undecidable; on 19 routes, 4 or more.
- **Marked routes and unmarked routes.** The marks are those of test A (the EXPLAIN at 240,000 rows), not the capture's note of section 1.4. The two are different things that share a word. The number of unmarked routes (22 of 57; 6 of 19) is the denominator of the goal line and is written beside every count.

## 2. The prediction and the order that makes the test valid

### 2.1 What was committed, and when

| time (2026-11-24, host clock, JST) | commit | what |
| --- | --- | --- |
| 11:29:46 | `98fe86d` | the rules of this test were added to the spec (7.4.6) |
| 11:30:05 | `6986d53` | the ruling to run A at 240,000 rows was recorded (D247) |
| 11:45:32 | `571564c` | the check that no 240,000-row Bagisto run exists was recorded (D248) |
| 11:59:40, 12:02:51 | `b73ed08`, `eb0f457` | the ruling on the denominator and the reached-route count (D250) |
| 12:28:30 | `0824213` | the dry run at 30,000 rows, a size already seen (section 2.5) |
| 12:34:52 | `d99180e` | the memory cap and the run layout (D251) |
| 12:36:17 to 12:51:42 | (not a commit) | the 240,000-row database was built and asked for plans only (section 2.3) |
| 12:56:00 | `c7359ac` | both undecidable rules applied to the count on 19 (D252) |
| **13:04:39** | **`207911e`** | **the estimate, the marked list (with the `offset_chunk_fit` list inside it) and the list of 19 were committed** |
| 13:06:47 | `6d62a57` | a citation in D252 was corrected (it names D153 where D212 was written); no rule changed |
| 2026-11-24 13:09:05 | (not a commit) | the first start of the cold measurement stopped after five seconds, with no request made (section 3.2) |
| **14:57:57** | (not a commit) | **the cold measurement started (ended 17:57:56); the repeat ran in three runs, the third from 2026-11-25 00:50:08 to 06:15:45 (section 3.1)** |
| 2026-11-25 06:21 to 06:28 | (not a commit) | the verdict was made by two readers who compared their outputs (section 4.1) |

The rulings that set the denominator, the 19-route count, and the tools' layout are all before `207911e`, which is the order the test needs. From `207911e` on, the estimate, the marks, the rules and the list of routes are not changed.

The two files the prediction gate reads are `poc/lab/predictions/extrapolation-bagisto-a-estimate.json` (SHA-256 `b0bb84a55f33eeb42193517c03fd68ca4aa2554930fc4130d1255d96660ad20e`) and `poc/lab/predictions/extrapolation-bagisto-a-marked.json` (SHA-256 `f8d432f986529cfd7fab2efe0d4859af12d4fb4fe9190d5f0e0b95f9d64d3c0e`). The measuring tool, the records and the order check against these two files are given in section 3.

### 2.2 What the estimate was made from

- **Capture.** The 120 routes were captured at 100, 1,000, 10,000 and 30,000 rows (the capture's own record: `capture.json` SHA-256 `928b311eb47da3a58687236d633c2f376920b82a508227e91d587398c6e4a5a4`, `tasks.json` SHA-256 `c996986b57df28dd20d350037ee406c9ebf0029f503ffaa4e3cce67f5daab814`). Sixty-three routes entered the capture record. **The capture's largest size is 30,000 rows and the test size is 240,000 rows: 8 times.** Beyond the largest captured size the tangent rule of H1 is used.
- **Database.** The capture's 123 tables grow together to N rows; the other tables are as the profile builds them (`docs/research/bagisto-prep/n-tables.txt`). One database serves all routes at each size.
- **EXPLAIN** at 240,000 rows, on a database built for it, with the small side of the plan comparison at the tool's default of 10,000 rows (decided before the EXPLAIN). The only things that reached the 240,000-row database before the commit were the tool's build, row counts, `ANALYZE TABLE` and the `EXPLAIN FORMAT=JSON` statements its gate lets through. **No HTTP request was made to it.**
- **Estimates.** 57 routes have a complete estimate; the estimate and the marked list were each made twice and the two outputs were identical byte for byte.
- **Marks.** 432 statement types in the EXPLAIN; `plan_unstable` 26, `plan_changes_at_scale` 55, `explain_unavailable` 0. Forty-one of the 63 routes in the EXPLAIN are marked; of the 57 with a complete estimate, 35 are marked and 22 are not.

### 2.3 The build, and what was asked of the 240,000-row database

The database of 240,000 rows was built in 800.82 seconds (migrations 17.57 s, filling 774.59 s; 32,391,928 rows by the server's statistics; 136 tables generated, 16 skipped, 0 failed). Its size is 5.86 GB (data 4.34 GB, index 1.52 GB, read from the server's table statistics right after `ANALYZE TABLE`). The build had no memory cap; the cap belongs to the measuring step. The database was stopped cleanly (its log ends with `Shutdown complete`, exit 0). No volume was removed: the 47 volumes of earlier runs are all still there, with the one new volume.

### 2.4 The `offset_chunk_fit` mark: 0 routes (D250, item 3)

The mark of this test adds to the other marks a score for the routes that read a table in chunks with an offset: a route is marked if its statements with an offset clause, run twice or more and captured at four sizes or more, hold a score of at least 0.1 (the share of the route's rows times the error of the formula at the last captured size, when the formula is made from the sizes before it). The threshold 0.10 is the goal for rows in section 7.4 of the spec; it was not decided from counts already seen.

**Said with the four points, in one sentence:** the shape of the mark was chosen after the outliers were known, the limit "only statements run twice or more" was also added after its result was seen, the count "2 marked, 2 hits, 0 false alarms" on BookStack at 240,000 rows is a fit to records already seen and not a test, and the effect of the mark can be tested only on Bagisto.

In the committed estimate, **6 routes have statements with an offset clause and 0 of them carry the mark** (`marked_count` 0). The consequence, written down before any measurement:

- The mark's hits, misses and false alarms are counted separately from the other marks, as D250 requires. The way they are counted was fixed in D253 before any measurement was read; it needs no reading of causes and is the implementation's own decision inside D250 item 3, not the maintainer's ruling. "Outside the goal" and "inside the goal" are not newly defined here: they are the per-route judgement of the tool under rule B and R1 (`judge_a`, `condition_3_misses`). The marks are those of the prediction commit and are not set again after the measurement.
  - **Hit**: a route with the mark that is outside the goal. **False alarm**: a route with the mark that is inside the goal.
  - **Miss**, two numbers kept apart. (a) **The main count**: of the 57 routes whose estimate is complete, a route that is outside the goal and does not carry the `offset_chunk_fit` mark (the same rule as for the other marks, `plan_changes_at_scale` and `plan_unstable`). (b) **The number set beside it**: those of (a) that are among the 6 routes with an offset statement. (b) is a part of (a) and is not added to it.
  - A route that could not be measured is counted as not measured and is in none of hit, false alarm or miss (D250, D251).
  - With 0 marked routes, hits are 0 and false alarms are 0; (a) and (b) are counted from the measurement.
- **With 0 marked routes, this text will say that the effect of the mark could not be confirmed in this test** (D250 item 3, D253). It will not say that the mark was "tested for the first time". Having no marked route shows neither that the mark works nor that it does not.
- Not marking is not a statement that the 6 routes are safe (D245: the mark leans towards staying silent, and it cannot see a bend above the captured range).

### 2.5 How the test was run, and what was decided about it (D249 to D252)

These are decisions about layout and tools made before the estimate and the prediction, before any result. Items 1 to 4, and the way the cap is applied and the run is laid out in item 5, are the implementation's own decisions inside the maintainer's rules (D249, D251); the value and the object of the memory cap are the maintainer's (D250). None changes the estimate rule, the marks, the calibration, the pass line or the list of routes.

1. **`--n-tables` is passed to the measuring entrance** (`75ac067`). The measurement and the repeat build their database in the same shared-table layout the capture and the EXPLAIN use (the 123 tables grown together). Without the option the arguments passed inside are exactly as before, which keeps the BookStack layout unchanged.
2. **`state_changing` was added to the Bagisto profile** (`a25d310`): five routes that change tables other routes read, listed in the order they are measured, so that "routes that change the state of a table are measured last" works on this application. They were chosen by reading the code of the controllers of all 120 routes, and a route that was doubtful was placed on the "measured last" side. The reading was made before and without any measurement result (the only measured record used was the 30,000-row capture).
3. **The measurement database is not analysed right after its build**, as in the fourth run on BookStack; the database for the EXPLAIN is analysed. The repeat analyses the tables that the state-changing routes write between laps, as the earlier repeats do. The fact that the estimate side is analysed and the measurement side is not is stated here, as it was for BookStack.
4. **The 120 routes are measured as they are** (D249, D250): the measured list is not narrowed to the routes with an estimate.
5. **Memory cap and run layout (D250, D251).**
   - **Value and object (D250).** The measured application's container gets a memory limit of 6 GB; the database's container gets none. The reason is that `GET /admin/customers/view/{id}` is expected to need about 7.7 GB of PHP memory at 240,000 rows, enough to take the machine down. A route killed by the limit is **counted as not measured**; it is not repeated and nothing is changed. If the database or another container falls, the work stops and is reported.
   - **How it is applied (D251).** A copy of the stack's compose file with the two limit lines added (`poc/runtime/compose/measure.compose.cap.yaml`, SHA-256 `afcd82766628c0ccaaf50c554f5e056eeff66d65b9af2e7885439b81bcafa4f1`, committed as `cfab5ab` at 12:37:14, before the prediction commit) is named by `PEAK_COMPOSE_FILE` for both the measurement and the repeat. The tool itself is not changed.
   - **Request time limit: 600 seconds**, as in D126b and the fourth run, not the 300 seconds of the dry run.
   - **In sequence, not side by side.** The cold measurement of all routes ends before the repeat of the marked routes starts. The fourth run on BookStack ran the two at the same time. Two application containers with 6 GB each, two databases and a 15.9 GB machine would not fit. The cost is a longer total time; times are not compared in this test, and the counts of queries and rows are not times.
   - **A watch is kept** over both stages: every 5 seconds, the application container's `memory.events`, limit and `memory.current`, and the database containers' state, health, `OOMKilled` and restart count.
   - **If the build is killed by the cap**, that is unexpected: the limit is not loosened, and the work stops and is reported.
6. **A dry run at 30,000 rows** (`0824213`, "not a test"; a size already seen) was made with the same tool and procedure on a few routes, to check that the order of measurement, the login gate and the verdict script work on this application. It judged nothing about the quality of the estimate. It was checked before the 240,000-row database was built.

### 2.6 How the repeat was run: three databases, 40 routes (D254 to D260)

This section is about how the measuring was laid out. It holds no measured value. Times are the host clock (JST), 2026-11-24 unless a date is written; the maintainer's rulings carry the maintainer's own clock, which is not in step with the host clock, and the entries say so.

**1. The repeat ran on a third database, not on the cold measurement's database.**

- The test brief says the repeat runs on the same database as the cold measurement, and the fourth run on BookStack did so. Here the maintainer ruled (D254, D259) that the repeat runs on a newly built database. It was built by the same procedure and with the same count as the others (240,000 rows, the 123 shared tables grown together, the generator, the Bagisto profile), in its own compose project and its own volume.
- The cold measurement ran in project `peak-bagisto-a240k-ms`. Its database was not reused by any repeat.
- Three databases were built for the repeat in all. Only the third is used:

| | Compose project | Build started | Build time (tool) | Digest line written | Digest of the build | What was done | Status |
| --- | --- | --- | --- | --- | --- | --- | --- |
| First | `peak-bga240k-var-a240k-db1` | 17:58:11 | 895 s | 18:13:44 | `82595976…4797` | 6 trials of lap 1 finished (exit 0). The 7th, `GET /admin/customers/view/{id}`, was killed at the 6 GB cap (exit 137, `OOMKilled`) at 18:29:15 after 106 s; the tool stops the whole run on one failure (exit 15, 18:29:20) | **Void.** Kept, not used |
| Second | `peak-bga240k-var-a240kr-db1` | 18:41:02 | 933 s | 18:57:13 | `5126f25c…138a` | 40 trials of lap 1 and 9 of lap 2 finished (exit 0). The 50th (`cart-rules` edit) was running at the stop and was cut (exit 137, 74 s). Stopped at a work break: application container stopped 20:57:37; the run ended 20:57:39 (exit 15); the database was shut down cleanly 20:57:44 to 20:57:46 | **Void.** Kept, not used |
| Third | `peak-bga240k-var-a240k3-db1` | 2026-11-25 00:50:23 | 947 s | 2026-11-25 01:06:47 | `c413377d…99ba5` | 40 routes, 3 laps, 120 trials (40 per lap), all with exit 0; started 2026-11-25 00:50:08, ended 06:15:45, repeat exit 0 (item 1, record of the third run) | **Used** |

- The values of the first run (6 trials) and of the second run (49 trials, and the cut 50th) are **not used for the verdict, not mixed with the third run's values, not compared with them, and nobody chose between them**. The values of the second run were never read by anyone. Both are kept on disk; their containers, volumes and networks were not removed.
- "Build time (tool)" is the time the build step itself took, from the start of the database build to its end, as the tool records it. "Digest line written" is the host-clock time of the digest line in the run's log; the tool writes that line after the build step ended, once it has taken a checksum of every table and a copy of the statistics, so it falls later than the start plus the build time by a few tens of seconds. The two columns are not meant to agree.
- Both void runs and the third use the same tool as the prediction (`207911e`), the same 6 GB cap file (SHA-256 `afcd82766628c0ccaaf50c554f5e056eeff66d65b9af2e7885439b81bcafa4f1`), the same 600-second request limit and the same `--routes` order (the first run with all 41 marked routes; the second and third with the 40). The gate (`PREDICTION OK`, `207911e`, committed 13:04:39) was run again before each start, and again inside each run.
- This is the whole of the difference from the fourth run on BookStack in how the repeat was laid out: a newly built database instead of the same one, and 40 routes instead of 41 (item 2). The rule that decides is the same.
- **Record of the third run** (host clock, 2026-11-25; taken from the run's order record, its log and the container states; no measured value was read for this record):
  - Started 00:50:08. The build began 00:50:23 and the digest line was written 01:06:47 (build time 947 s by the tool). The gate of item 4 read the build at 01:06:48. The first trial began 01:06:48.
  - The repeat ended 06:15:45 with exit **0** (the run's log: "finished with exit 0"). **120** trials ran (3 laps of 40), and the exit recorded in each of the 120 trials is 0. The trial order is monotone: no trial began before the previous one had ended. The last trial ended 06:15:43.
  - The 40 routes were the 40 of item 3 (list SHA-256 `09a4ada0…`), the marked list unchanged (SHA-256 `f8d432f9…3c0e`), the tool `207911e`, the 6 GB cap file `afcd8276…a4f1`.
  - Stop: the application container was stopped with `docker stop -t 30` and the database with `docker stop -t 180`; no kill command was used. The application container exited with code **137** (the signal that ended it after the 30-second grace; `OOMKilled` is false, so it was not the cap). The database exited with code **0**, and its log ends with a normal shutdown ("Shutdown complete"). Application container finished 06:16:25, database 06:16:27. No project container is running now.
  - Volumes: **51 before the third build** (`volumes_before` equals the list taken when the second run was stopped; none missing) and **52 after the run**. The only difference is one added volume, `peak-bga240k-var-a240k3-db1_measure-dbdata`; **none was removed**, and no volume removal, prune, `down -v` or `--purge` was used.

**2. Why the first run, then the second, stopped.** Both stopped for a reason outside the measured routes' values: the first because the application container hit the 6 GB cap on `GET /admin/customers/view/{id}` (item 3); the second because of a stop at a work break (the maintainer's order to bring the work to a safe halt). The second was not stopped by any route failing.

**3. The one route taken out: `GET /admin/customers/view/{id}` (D254).**

- **Why.** In the cold measurement the route was killed at the 6 GB cap (15:46:36; `memory.events` `oom_kill` 1) and its record is a single attempt-2 row of class `runner_failed`, verdict `not_measured`, so it is counted as not measured (the cap and its consequence were set before the prediction; D250, D251). In the first repeat the same route was killed again and, because the tool stops the whole run on one failure, no route after it in the order ran. It was therefore left out of the repeat's input.
- **How.** The 40 routes are the 41 marked routes of the prediction commit with this one removed, taken from the committed marked list by a script that asserts the count (41 to 40; the route present exactly once). The order is the committed order. The committed marked list is not changed. The comma-joined list of the 40 has SHA-256 `09a4ada06e2e268653ef352a276e5d9862b61f1fb523664f51bbbcce2ea57ff6`.
- **What is and is not said.** The counting rule does not change: the route is already counted as not measured in the cold measurement. Whether the pass counts themselves would be the same if the route had been included is **not shown**; the maintainer corrected a first wording on this (2026-11-24 18:45 on the maintainer's clock). It is written here as an expectation, not as a result: leaving the route out is expected not to move the counts, because its cold measurement is already "not measured", but this was not checked.
- **If another route had hit the cap**, the tool would have stopped and the work would have been reported; no further route would have been taken out (D254, item 5). **No other route hit the cap in the third run:** the run ended with exit 0, all 120 trials recorded exit 0, the application container was not out-of-memory killed (`OOMKilled` false; its exit 137 is the result of the planned stop at the end, item 1), and no route was taken out beyond the one above.
- **The seven routes that D256 can reach** (marked routes whose cold attempt failed; listed from the cold measurement's record before any repeat value was read, `docs/research/extrapolation-data-bagisto/prediction/arrived-list/cold-fail-marked.tsv`, SHA-256 `7a8f33475f0ed548e04c38e1846f4f1a23af02d44b6c1cb974a500949e678805`): `DELETE /api/customer/wishlist/{id}`, `GET /admin/catalog/products/edit/{id}`, `GET /admin/customers/view/{id}` (not in the repeat's input; not measured either way), `GET /admin/marketing/promotions/cart-rules/edit/{id}`, `GET /api/customer/wishlist`, `GET /api/products/{id}/related`, `GET /api/products/{id}/up-sell`. Six of them are in the repeat's 40.

**4. The three tables whose build differs between builds, and the gate for the third database (D257, D260).**

- **What differs.** Between builds of this test's database, three tables differ in their checksum, and only in a time column: `attributes` (1 of 30 rows: the row `manage_stock` that the application's own migration writes with the application's clock), `product_price_indices` (720,000 rows) and `product_inventory_indices` (240,000 rows), which the profile's correction statements fill with `NOW()` (`poc/runtime/profiles/bagisto.php`, lines 203 to 210). The row counts of all tables are equal. This is not a new kind of difference: the earlier capture and debugging builds at 100, 1,000, 10,000 and 30,000 rows and the first repeat's database differ from one another in the same way; the `attributes` checksum differed in each of these five builds, while the hash without the time columns was the same in all five.
- **The generator uses neither the clock nor random numbers** (`poc/runtime/src/Data/ValueFactory.php`, line 10, and the fixed timestamp at lines 154 to 156). Between the first repeat database and each of the two later ones, the other 149 of the 152 tables matched in checksum as well as in row count.
- **No statement type reads these columns in a condition or an order.** Of the statement types in the estimate (137 by the implementer's count, 146 by a reviewer who counted by non-overlapping strings; the finding is 0 in both), the types that read these three tables (5, 1 and 7 types) use none of the time columns in a `where`, a join or an `order by`. One `product_price_indices` type uses `created_at`, which is the column of `products` (a fixed value from the generator); the `ORDER BY` of the product list is on `min_price`, a fixed value from the correction (`10 + MOD(p.id, 90)`), not a time. The time columns have no index. **Limit:** the types are those of the capture the estimate was made from; the statements run at 240,000 rows were not searched again.
- **The gate for the third database (D260), fixed before the third build.** Both of these had to hold, else the run stops and its values are not used: (a) row counts equal in all tables, and the tables whose checksum differs only among those three; (b) the hash of the three tables without their time columns equal to the first database's.
- **Result of the gate** (record `digest_gate_3.txt`, read at 2026-11-25 01:06:48): (a) 152 tables, row counts equal in all 152; checksum differs in exactly `attributes`, `product_inventory_indices`, `product_price_indices`: **pass**. (b) without the time columns, `attributes` 30 rows / hash 2278984126, `product_price_indices` 720,000 / 89298733, `product_inventory_indices` 240,000 / 973885346: all three **equal** to the first database's values. The run went on.
- **The gate's reading overlapped the first trial of lap 1, and only that trial.** The three queries ran from 01:06:48.384 to 01:06:49.565 (query times 0.12, 0.74 and 0.27 s: about 1.1 s; the span between the first query's start and the last one's end is 1.18 s). The record of the 120 trials (order, phase, lap, start, end, exit; the start and end are kept to the whole second) shows the **first trial of lap 1 (order 1) began 01:06:48 and ended 01:17:09** (exit 0). The reading began in the same whole second as that trial's start, so the record cannot tell whether it began just before or just after the trial began (the statistics copy runs between the digest line and the first trial); it ended 01:06:49, about ten minutes before the trial's end. The **other 119 trials do not overlap it**: the second trial began 01:17:10, after the reading, and every later trial began later still. The count was made twice (by the one who ran it and again by a reviewer from the same record) and agrees. This is the overlap that D260 accepted. The route of that first trial is `GET /admin/catalog/products/edit/{id}`: the folder of the trial in the repeat's records is named `plain_lap1/01_GET__admin_catalog_products_edit__id_`, a name the time-out script printed in the list of the files it read (section 4.6). The route is known from that name; no measured value was read for this record.
- **Why the overlap does not reach the verdict (D260).** The verdict rests on counts of queries and of rows, not on time (spec 7.4, D95). A delay can reach only the 600-second limit, and a time-out puts a route on the "not measured" side. The route of that trial is one of the seven of item 3 (see above). Its three trials (numbers 1, 41 and 81) are among the 18 time-outs of section 4.6, so it is counted as not measured whatever the repeat gave; its estimate is `partial`, so it is outside the denominator under R1 as well. The gate could not be put between the build and the first trial because the tool goes from the build into the laps and may not be stopped there. The other reading (table-by-table only, with the column hash after the run) was set aside: it would have let a build with an unknown difference run before the difference was known.
- **Overlap in the second run, for the record only (the second run is void).** While the second run was in its first trial (`GET /admin/catalog/products/edit/{id}`, 18:57:13 to 19:07:36), a read-only look into the cause of the same three-table difference started other, stopped databases in two windows, 19:00:57 to 19:02:56 and 19:05:01 to 19:05:44 (1 min 59 s and 43 s, 2 min 42 s in all). The queries were `SELECT` and `SHOW` only; the run's own output and database were not touched. Both windows lie inside that first trial; the second trial began at 19:07:36. Because the second run is void, this is listed and nothing more is made of it. Since then the rule for this machine has been that while a repeat runs, no other database is started on it and no heavy read is made.

**5. Which databases still hold their contents.**

- **The cold measurement's database is gone.** The measuring tool drops the measured database at the end of each point (`poc/runtime/src/Measure/Driver.php`, lines 106 to 113, the `finally` with `dropQuietly`; `poc/runtime/src/Guard/MeasureDb.php`, lines 51 to 55, `DROP DATABASE IF EXISTS`). `--keep-stack` keeps the containers and the volume only. The volume of project `peak-bagisto-a240k-ms` is still there, with **0** user databases in it. So "keep the measured database until the maintainer accepts it", which the brief and D241 (b) ask for, was **not met** for the cold measurement. The maintainer accepts this (D257). Fixing the tool is a separate piece of work to be done before the measurement of the next test; it was not touched in this test. A line in the dry run's notes already said that the volume of a cold-only stack holds no database at the end; the check of the dry run missed the conflict with "keep the measured database".
- **Nobody recorded a digest of the cold measurement's database** (the measuring tool does not produce one), so "the repeat's database holds the same content as the cold measurement's" is consistent with everything seen but **not shown**.
- **After the run: which of the repeat databases kept their contents.** The tool acts on them in the same way. The first repeat database held its contents when it was looked at on 2026-11-24 at 19:01. The second was not looked at after its stop. **Looked at after the third run** (2026-11-25, 06:22 to 06:23, after the run had ended; nothing was running on the machine): each of the three repeat databases was started once, asked for its database name, its number of tables and (for two of them) the sum of the server's approximate row counts, and stopped cleanly. No measured value and no row was read, and nothing was written. **All three kept their contents**: 152 tables each, the same number as in the digest comparison of item 4; about 32,255,373 rows by the server's statistics for the first and about 32,420,565 for the third (the second was read for its tables and their number only, as the ruling on the second run asks). The logs of the three show no crash recovery and end in a normal shutdown. The volumes were 52 before and 52 after, none missing. The database of the cold measurement, by contrast, did not keep its contents (the first bullet of this item).

## 3. Measurement

The records of the cold measurement and of the repeat are in `docs/research/extrapolation-data-bagisto/measured-240000/`, and the outputs of the verdict (section 4.1) are in `docs/research/extrapolation-data-bagisto/compare/` (both committed as `16540d5`). Times are the host's clock (JST).

### 3.1 The runs

| Step | Start | End | Exit | Used |
| --- | --- | --- | ---: | --- |
| Cold, once, every route: first start | 2026-11-24 13:09 | 2026-11-24 13:09:05 | 11 | **no** (no request was made) |
| Cold, once, every route | 2026-11-24 14:57:57 | 2026-11-24 17:57:56 | 0 | yes |
| Repeat of the marked routes, first run (41 routes) | 2026-11-24 17:57:56 | 2026-11-24 18:29:20 | 15 | **no** (void, section 2.6) |
| Repeat, second run (40 routes) | 2026-11-24 18:40:47 | 2026-11-24 20:57:39 | 15 | **no** (void, section 2.6) |
| Repeat, third run (40 routes, 3 laps, 120 trials) | 2026-11-25 00:50:08 | 2026-11-25 06:15:45 | 0 | yes |

The cold measurement and the repeat were run in sequence, not side by side (section 2.5, item 5).

### 3.2 The cold measurement

- **The tool and its settings.** The tool is the tree of the prediction commit `207911e` with no difference (the working tree and the commit were compared before the run). Settings: size 240,000 rows; the 123 shared tables grown together (`--n-tables`); the Bagisto profile and the generator; the 120 routes of the measured list; request limit 600 seconds; warm-up off; the 6 GB cap file of section 2.5, item 5. The tool also measures an empty system (size 0) as a first point, which took 42 seconds; it is not used by the verdict.
- **The first start stopped after five seconds.** It ended with exit 11 because the container runtime had no free network address range left (all predefined address pools were taken by the networks of stopped stacks). No database or application container had been made and **no request was sent**; the volumes were the same before and after (48). Nothing was changed after the prediction commit. The work stopped there. Five stopped stacks of earlier dry runs and debugging runs were then taken down (containers and networks only, `docker compose down` without `-v`; the volumes were 48 before and 48 after, the networks 32 before and 27 after), and the measurement was started again.
- **The run.** 14:57:57 to 17:57:56 (the tool's own summary: 10,782.4 seconds); exit 0. The 240,000-row database was built in 834.2 seconds and the routes were measured in 9,072.6 seconds. The route order is the one of the static analysis's route list with the five routes of `state_changing` last (section 2.5, item 2). The output is 371 attempt records and 120 route records. The tool tried **112** routes and did not try **8**: a parameter in the URI that the profile could not fill.
- **One route was killed at the cap.** `GET /admin/customers/view/{id}` (the 12th in the order) was killed when the application container reached the 6 GB limit (15:46:36; `memory.events` `oom_kill` 1, the database container unharmed, no restart). The run went on. Its record is a single attempt-2 row of class `runner_failed` and verdict `not_measured`, as the dry run showed it would be. By the rule set before the prediction (D250, D251) it counts as **not measured**. The count of kills stayed at 1 to the end of the cold measurement.
- **No per-trial error output.** The tool keeps no error output for each trial of the cold measurement. The one cold value of a route is read from its record only. This matters for the time-out rule (section 4.6).
- **The database of the cold measurement is gone** (section 2.6, item 5).

### 3.3 The repeat

How the repeat was laid out, why two runs are void, and which route was taken out is in section 2.6. The values used are those of the third run: 40 routes, 3 laps, 120 trials, every trial with exit 0, on a database built apart from the cold measurement's.

### 3.4 The order check

- The gate that compares the two prediction files with the commit `207911e` (committed 13:04:39) was run before the first start and again inside each run, and every time it answered `PREDICTION OK`. The two files read for the verdict are byte-equal to the committed ones (estimate SHA-256 `b0bb84a55f33eeb42193517c03fd68ca4aa2554930fc4130d1255d96660ad20e`, marked list SHA-256 `f8d432f986529cfd7fab2efe0d4859af12d4fb4fe9190d5f0e0b95f9d64d3c0e`). Both readers of section 4.1 checked the committed files against `207911e` (no difference), and the second reader also hashed the inputs before and after the run (the same).
- The first measurement (14:57:57) is later than the commit (13:04:39). The only earlier start (13:09) made no request.
- Inputs of the verdict (SHA-256): the cold measurement's `attempts.jsonl` `976aae9d1e0ed26d6701c72877059753aa7df5c0a0d43117236d83b4fbc0223c`; the repeat's list of routes (`routes_repeated_3.txt`) `aaba5cf294e0f1b582baafbd9d570433ccb8cd88495d465fc5568a2960df1583`; the repeat's records as the hash of the list of the hashes of all `attempt.jsonl`, `stderr.log` and `trial.json` files (360 files) `561e345929a9b50e1c0a36ac6595f4d1043f4d9d76d0536651d5ccb2a7261748`.

### 3.5 What the records show about the 120 routes, before any comparison

| What | Routes |
| --- | ---: |
| Measured list | 120 |
| With a complete estimate (the 57) | 57 |
| of which measured | 56 |
| of which not measured (`GET /admin/customers/view/{id}`) | 1 |
| With a `partial` estimate | 6 |
| With no estimate row, measured at 240,000 rows, and the cold try failed | 49 |
| With no estimate row and no record at 240,000 rows (a parameter that could not be filled) | 8 |

The 49 cold failures, by class as recorded: HTTP 422 17, not found 14, HTTP 500 9, unauthenticated 3, forbidden 3, client error 2, external call blocked 1. The 8 routes without a record are `POST /admin/appearance/themes/{code}/sections`, `GET /cache/{template}/{filename}`, `GET /customer/resend/verification/{email}`, `GET /customer/social-login/{provider}`, `GET /customer/social-login/{provider}/callback`, `GET /customer/verify-account/{token}`, `GET /downloadable/download-sample/{type}/{id}` and `GET /subscription/{token}`. These 57 routes without an estimate row are in no count.

## 4. Result

The figures are those of the verdict tool of the two readers (section 4.1). The tool runs the comparison script `compare_test2.py` (SHA-256 `95da6a9b77bdc5c43a1455c9c1ac9b4f11cb0e966aa1cfeac4e42220e382bb8b`, the same file as in the fourth run on BookStack) on the two committed prediction files and the records. The tables below are copied from its output; no figure was rounded again by hand.

### 4.1 How the verdict was made

Two readers rebuilt the verdict from the raw records and the prediction files, each in a place of their own. The second reader never opened the first reader's place; the first opened the second's only after writing and timestamping their own. Both ran the committed scripts, not changed for this run: `judge_a.py` (SHA-256 `48eed964f661982d3f96e75f6c4b9919f17789589e256d4da723fdd2dbe36766`) for the count on the 57; `count_listed.py` (`99f288c6fb8aec2f3d09e1aff39500dac616eca7a8ee01efc04909f8b8d6617c`) for the count on the 19; `timeout_scan.py` (`4927e6a57f02ae51160c7f82e2cd7ce69ea37e6b55c6ed5d5e94aab816c5a4f2`) for the time-outs of D255; and `cold_fail_marked.py` (`da668a1107bf190adf04e85307872753f7a885c037d302c556c77bb701cb8dcf`) for D256. The scripts are in `docs/research/extrapolation-data-bagisto/prediction/arrived-list/` and `docs/research/lab-data/dryrun-286/judge/`.

- **They agree on every figure of sections 4.2 to 4.7.** The verdict file for the 57 (`a-verdict.json`, SHA-256 `4cc77ed01d78ecfb7c38819bbf7576d0ca2ac9f8b34ef74cde59650089f3666d`), the file for the 19 (`count-listed.json`, `08ed6e0d0ba2752115cfa015ea5d5710d7a5474eab33e0413f72258e1f502013`), the list of time-out trials (`timeout-trials.tsv`, `c3b1c7ece0437e90a2971420bcca4aacc26bb7c913837ebabbdd6a2a1c4d6596`) and the list of D256 (`cold-fail-marked.tsv`, `7a8f33475f0ed548e04c38e1846f4f1a23af02d44b6c1cb974a500949e678805`, the file committed before any repeat value was read) are **byte-equal** between the two readers. No disagreement was left to close, and no rule had a gap.
- **Three readings of the records give the same numbers.** (a) The records after the time-out scan of D255 and the rewrite of D256 (this one decides). (b) The records after the time-out scan only. (c) The records as the tool wrote them (run by the second reader only). The count on the 57 and the count on the 19 are the same in all of them, for the reason in section 4.6.
- **R2.** One reader ran a copy of the comparison script that counts the six `partial` routes as routes with an estimate, in the form of the earlier runs' R2 patch (`compare_test2_R2.py`, SHA-256 `905a6a7758ba202e0234ea7244d2757f0620fc8c56e254ba96e40a0223a6968a`), for both rules. The other reader built the R2 figures from the tool's records by a table script of their own, for rule B. Both give the same numbers. The rule A figures of R2 are those of the first reader's copy only.
- **The two readers were not fully blind to the project.** Before this reading, the first reader had seen, in a review of the repeat's plan, the counts of the cold measurement's routes by outcome class, the record class of two routes, the number of ok trials among the six trials of the first void repeat, and the highest PHP memory among the 40 marked routes in the cold measurement (2.7 GB). The second reader had read the same review, which also says that the first void repeat's first route stopped at the 600-second limit. Neither had compared an estimate with a measured value before running the scripts, and none of this was used in a count. **Neither opened the records of the two void repeats.**
- The two readers hashed the repeat's records in different ways and got different values first; with the method of section 3.4 they agree.

### 4.2 Verdict (rule B decides, rule A shown, R1 and R2)

| Scope | Rule B (decides), R1 | Rule A (shown), R1 | Rule B, R2 | Rule A, R2 |
| --- | --- | --- | --- | --- |
| The 57 routes with a complete estimate | **pass** | pass | pass | pass (one reader) |
| The 19 routes whose data seems to have arrived (section 4.4) | pass (line met) | pass | not applicable | not applicable |

Rule B, R1, on the 57 (the denominator is the 56 that were measured):

- Queries: **54 of 56** inside ±5% (96.43%; 45 needed). Rows examined: **52 of 56** inside ±20% (92.86%; 45 needed).
- Inside the goal: queries 54 of 56 (±2%); rows examined 51 of 56 (91.07%; ±10%).
- Rule A gives the same shares as rule B, and puts the same routes inside and outside every line. (Section 4.5: `GET /api/categories` is the one route whose four cold values differ, and its largest value is also its first.)
- Peak memory (reported; does not decide): 22 of 22 inside ±30% (unmarked 4 of 4; marked 18 of 18). Only 23 of the 63 routes in the estimate have an estimate of peak memory, and 22 of those 23 were measured.

**Undecidable: no.** Both quantities have a denominator of 56 (not below 5), and 1 of the 57 routes with an estimate was not measured (1.75%; the limit is more than 20%). The grounds are these numbers.

**R1 and R2.** R2 counts the six `partial` routes as routes with an estimate. All six produced no value (section 4.6), so under R2 they are six more routes not measured: 63 routes with an estimate, 56 in the denominator (unchanged), **7 not measured (11.11%)**, below 20%. The shares inside the lines are the same as under R1 (queries 54 of 56, rows examined 52 of 56), the verdict is pass under both rules, and the count is not undecidable. **R1 leaves out the six routes whose capture did not complete.** One of them, `GET /admin/marketing/promotions/cart-rules/edit/{id}`, is the only route whose capture verdict was `O(N)`; it answered HTTP 500 at 30,000 rows in the capture and was cut at 600 seconds in the repeat. The other five did not complete in the capture either, and the capture did not show them to be heavy; they are `DELETE /api/customer/wishlist/{id}`, `GET /admin/catalog/products/edit/{id}`, `GET /api/customer/wishlist`, `GET /api/products/{id}/related` and `GET /api/products/{id}/up-sell`, and they answered HTTP 500 in the cold measurement. In the repeat, all six were cut at 600 seconds in all three laps. The pass of R1 is a count with these six outside it. R2 does not put them back in the denominator, because a route without a value cannot enter it; it shows that they were not hidden.

### 4.3 Share within tolerance, by group (rule B, R1)

| Group | Quantity | Denominator | Inside lowest standard | Inside goal |
| --- | --- | ---: | ---: | ---: |
| all | queries | 56 | 54 (96.43%) | 54 (96.43%) |
| all | rows examined | 56 | 52 (92.86%) | 51 (91.07%) |
| unmarked | queries | 22 | 21 (95.45%) | 21 (95.45%) |
| unmarked | rows examined | 22 | 22 (100.00%) | 22 (100.00%) |
| marked | queries | 34 | 33 (97.06%) | 33 (97.06%) |
| marked | rows examined | 34 | 30 (88.24%) | 29 (85.29%) |
| all | peak memory (reported only) | 22 | 22 (100.00%) | 22 (100.00%) |

The 57 routes are 22 unmarked and 35 marked; 34 of the marked are in the denominator (1 not measured). All 34 marked routes have four cold values: the one of the cold measurement and the three of the repeat. For 33 of the 34 the four values are equal on queries and on rows examined; the one that is not is `GET /api/categories` (section 4.5).

**What version 1.0 asks of A (spec section 0, condition 1a), in these figures (the four numbers of D153).** Unmarked routes inside the goal: queries 21 of 22 and rows examined 22 of 22 (18 needed); all routes inside the lowest standard: queries 54 of 56 and rows examined 52 of 56 (45 needed). **All four numbers short are 0.**

### 4.4 The count on the 19 routes whose data seems to have arrived, and whether condition 2 part A is met

The count of section 1.4, with the two undecidable rules applied to it (D252): the same unchanged script on the estimate and the marked list narrowed to the 19 routes (13 marked, 6 unmarked).

| Line | 57 routes: inside / denominator | needed | spare | 19 routes: inside / denominator | needed | spare |
| --- | --- | ---: | ---: | --- | ---: | ---: |
| Goal, unmarked, queries (±2%) | 21 / 22 | 18 | 3 | **5 / 6** | 5 | **0** |
| Goal, unmarked, rows examined (±10%) | 22 / 22 | 18 | 4 | 6 / 6 | 5 | 1 |
| Lowest standard, all, queries (±5%) | 54 / 56 | 45 | 9 | 16 / 18 | 15 | 1 |
| Lowest standard, all, rows examined (±20%) | 52 / 56 | 45 | 7 | **15 / 18** | 15 | **0** |

- On the 19, the denominator is 18 for both quantities: `GET /admin/customers/view/{id}` is among the 19 and was not measured (1 of 19 = 5.3%; the limit is more than 20%, which is 4 routes). The denominator is not below 5. **Undecidable: no, for both counts.**
- **Both counts meet the line: the four numbers short are 0 on the 57 and 0 on the 19. Condition 2, part A, is met.** This is a statement apart from "rule B passes": rule B passes on the 57 alone; part A is met because the count on the 19 also meets the same line.
- **Two of the lines on the 19 have no spare.** Rows examined 15 of 18 needs 15; the unmarked routes' goal on queries 5 of 6 needs 5. **One more route outside the line in either of them would have left the line short by one, and part A would not be met.** The margin of the pass of part A is zero on those two lines.
- **Where the misses are.** Of the 57-route count's lowest-standard misses, all of the two on queries and three of the four on rows examined are among the 19: the 19 hold 16 of 18 on queries and 15 of 18 on rows examined; the other 38 routes (the ones with the capture's note that the data may not have reached the route) hold 38 of 38 on queries and 37 of 38 on rows examined. Five of the six routes outside the goal are among the 19 (5 of 18 measured), and one among the 38 (1 of 38). This says where the misses fall, not why. It is also what section 1.4 gave as the reason for the second count: a count on the 57 rests mostly on routes whose flat counts are not evidence of growth.

### 4.5 Routes outside the goal

Routes with a quantity outside the goal under rule B (R1): **6** (5 marked, 1 unmarked). The multiple is measured ÷ predicted; for a marked route the measured value is the largest of its four cold values (rule B), which is also its first cold value (rule A). `GET /api/categories/tree` is outside on two quantities and is one route. Of the 6, five are among the 19 of section 4.4; `GET /customer/account/rma/get-order-items/{orderId}` is not.

| Route | Marked | Quantity | Predicted | Measured | Multiple | Relative error |
| --- | --- | --- | ---: | ---: | ---: | ---: |
| `GET /api/categories/tree` | yes | queries | 515.05 | 4 | 0.008 | 12776.22% |
| `GET /api/categories/tree` | yes | rows examined | 15,760,555 | 480,011 | 0.030 | 3183.37% |
| `GET /customer/account/rma/get-order-items/{orderId}` | yes | rows examined | 37 | 9 | 0.243 | 311.11% |
| `POST /customer/account/orders/cancel/{id}` | yes | rows examined | 2,051,731 | 960,006 | 0.468 | 113.72% |
| `POST /admin/sales/invoices/create/{order_id}` | yes | rows examined | 3,251,774 | 2,160,028 | 0.664 | 50.54% |
| `GET /api/categories/attributes/{attribute_id}/options` | no | queries | 30.97 | 23 | 0.743 | 34.64% |
| `GET /api/categories` | yes | rows examined | 960,224 | 1,200,029 | 1.250 | 19.98% |

- **Outside the lowest standard: five routes**: `GET /api/categories/tree` (queries and rows examined), `POST /customer/account/orders/cancel/{id}`, `POST /admin/sales/invoices/create/{order_id}`, `GET /customer/account/rma/get-order-items/{orderId}` (rows examined) and `GET /api/categories/attributes/{attribute_id}/options` (queries). They are the 2 misses on queries (54 of 56) and the 4 on rows examined (52 of 56) of section 4.3.
- **Outside the goal and inside the lowest standard: one route**, `GET /api/categories` (rows examined 19.98%, inside ±20% by 0.02 point).
- **Five of the six are estimated higher than measured** (multiple below 1), by factors from 1.3 (`GET /api/categories/attributes/{attribute_id}/options`, queries) to 129 (`GET /api/categories/tree`, queries). The sixth, `GET /api/categories`, is measured higher, by a factor of 1.25.
- **`GET /api/categories`: the heavy value is one cold value.** The cold measurement gave 1,200,029 rows examined; the three laps of the repeat gave 960,049 each, which is 0.02% from the estimate (960,224). Rule B takes the largest of the four, which is the cold measurement's. The cold measurement and the repeat ran on different databases (section 2.6). The cause of the difference was not examined.
- **For the other five, the four cold values are equal** on both quantities. The route that is furthest out, `GET /api/categories/tree`, was measured at 4 queries and 480,011 rows in all four values, against 515 and 15.8 million estimated.

### 4.6 Routes not measured, and why

Nothing is dropped silently. These routes are in no denominator and are not misses.

**1. The one route with a complete estimate that was not measured:** `GET /admin/customers/view/{id}` (marked). Its cold try was killed at the 6 GB cap (section 3.2), it is not in the repeat's input (section 2.6, item 3), and it is counted as not measured (1 of 57 = 1.75%). It is in the list of 19, so it is also 1 of 19.

**2. Time-outs (D255), found by the script.** The script reads the error output of every trial and counts as a time-out a trial whose error output holds the stop message for the 600-second limit. It found **18 trials on 6 routes**, all in the third repeat; none in the cold measurement. Each trial made one attempt and none was ok; the record calls each "exception 500". Each of the six routes has one such trial in each of the three laps. The trial numbers are those of the order record.

| Route | Trials (laps 1, 2, 3) | Seconds |
| --- | --- | --- |
| `DELETE /api/customer/wishlist/{id}` | 25, 65, 105 | 620, 620, 620 |
| `GET /admin/catalog/products/edit/{id}` | 1, 41, 81 | 621, 620, 620 |
| `GET /admin/marketing/promotions/cart-rules/edit/{id}` | 10, 50, 90 | 621, 622, 620 |
| `GET /api/customer/wishlist` | 24, 64, 104 | 619, 620, 619 |
| `GET /api/products/{id}/related` | 28, 68, 108 | 619, 620, 619 |
| `GET /api/products/{id}/up-sell` | 29, 69, 109 | 620, 620, 619 |

- **The cold measurement is not "free of time-outs"; it is not observable.** None of the 371 attempt records holds the stop message in its detail, and no line of the run's two logs holds it, but the tool keeps no error output for each trial of the cold measurement, so the script could not read what it reads for the repeat. Nothing more can be said than that.
- **Trials 108 and 109** (the third lap of `related` and `up-sell`, 619 seconds each) are time-outs by the script, like the other sixteen.
- **Side check, not a rule and not counted.** The three laps of `GET /api/products` took 845, 803 and 799 seconds, more than 600. Each trial made two attempts, both ok with HTTP 200, and the error output holds no stop message: each request stayed inside the limit. By the rule of D255 they are not time-outs, and they have no effect on any count.
- All six time-out routes are marked, have a `partial` estimate and were HTTP 500 in the cold measurement. A marked route with one time-out in any trial is counted as not measured (D255), and they are outside the denominator under R1 in any case.

**3. A cold failure that the repeat answers with ok (D256).** The seven marked routes whose cold try failed were listed before any repeat value was read: the six above and `GET /admin/customers/view/{id}`. The counts as written (the repeat's records as they are) and the counts of D256 (the cold failure turned into "not measured" where the repeat gave ok) are **the same** and give the same verdict. The reason is in the records: for six of the seven, the repeat has **no ok trial** (all 18 are time-outs), and `GET /admin/customers/view/{id}` is not in the repeat. So D256 turned no route from measured to not measured, and the rewrite of 18 records changed no count. (The rewritten records are counted by the script as time-outs, because the script reads the time-out text in the detail first; both are "not ok", and the number is the same.)

**4. The six `partial` routes (D250, item 5; D212).** The same six routes as in item 2. R1 decides: they are outside the denominator. R2 is shown (section 4.2). `GET /admin/marketing/promotions/cart-rules/edit/{id}` is the one that grew with the size in the capture (verdict `O(N)`); here it is HTTP 500 in the cold measurement and 600-second cuts in the repeat. The six are in neither the count on the 57 nor the count on the 19.

**5. A note on the counts of the repeat without its values.** At one point the maintainer was asked how the verdict would count if the repeat's values were not used. The question showed one reading only (the 35 marked routes among the 57 counted as not measured, and so an undecidable count); the scripts also allow a reading in which a marked route is counted by its one cold value. The maintainer ruled to use the repeat's values (D257), and D257 records both readings. Neither is used here and neither is a result.

**6. The 57 routes without an estimate row** (section 3.5): 49 measured and failed cold, 8 without a record. In no count.

### 4.7 The `offset_chunk_fit` mark: hits, misses and false alarms, apart from the other marks

| What | Number | Routes |
| --- | ---: | --- |
| Routes marked `offset_chunk_fit` | 0 | |
| Hits (marked and outside the goal) | 0 | |
| False alarms (marked and inside the goal) | 0 | |
| Marked and not measured | 0 | |
| Routes with a statement with an offset clause | 6 | `GET /admin/customers/search`, `GET /api/categories`, `GET /api/categories/attributes/{attribute_id}/options`, `GET /api/product/{id}/reviews`, `GET /api/products`, `GET /customer/account/reviews` |
| Misses, (a) the main count: outside the goal, among the 56 measured routes with an estimate, without the mark | 6 | `GET /api/categories`, `GET /api/categories/attributes/{attribute_id}/options`, `GET /api/categories/tree`, `GET /customer/account/rma/get-order-items/{orderId}`, `POST /admin/sales/invoices/create/{order_id}`, `POST /customer/account/orders/cancel/{id}` |
| Misses, (b) set beside it: of (a), the routes with an offset statement (a part of (a), not added to it) | 2 | `GET /api/categories`, `GET /api/categories/attributes/{attribute_id}/options` |

The way these are counted is the one fixed before any measurement (D253). The list of (a) is the same as the list of section 4.5.

**This test could not confirm the effect of the `offset_chunk_fit` mark.** The mark was on no route, so there is no hit and no false alarm. No mark shows neither that the mark works nor that it does not. This text does not say that the mark was tested here for the first time. The two routes of (b) are routes the mark would have had to catch if it were to claim them: `GET /api/categories` (rows examined, marked by the plan marks) and `GET /api/categories/attributes/{attribute_id}/options` (queries, with no mark of any kind). The other four routes with an offset statement are inside the goal on both quantities.

### 4.8 Causes of the routes outside the goal (read from the records, nothing repaired)

**No cause was examined in this test** for any of the six routes; only the counts of the records were read. For condition 3 all six are counted as causes not identified. What the records show without looking further:

- The marks of this section are those of test A at the prediction commit (the plan marks), not the marks of the lab. Of the 34 marked routes in the denominator, 5 are outside the goal and **29 are inside it on both quantities**. D243 asks for this to be written beside any count of marks. D243 also asks for a second note: for a route outside the goal, the statement that carries the mark may not be the statement that gave the miss. That was **not examined here**, so it is not known for any of the five.
- One route outside the goal has no mark: `GET /api/categories/attributes/{attribute_id}/options` (queries 23 measured against 30.97 estimated). It has an offset statement, and the offset mark did not mark it (section 4.7).
- Five of the six estimates are above the measured value (section 4.5). Whether that comes from the capture, the fit or the tangent rule above 30,000 rows was not looked into.

## 5. What is left of conditions 1 to 3 of section 0 of the spec

Counted as D153 counts them, rule B, R1. The count is made after the result was seen; the rules, the pass line and the verdicts above are not changed by it.

- **Condition 1a (the extrapolation test).** For Bagisto, the four numbers short are 0 on the 57 routes and on the 19 routes (section 4.4). The test is a third application, so it counts toward condition 2; the BookStack and verification-app counts of the fourth run are unchanged by this file.
- **Condition 1b (the lab backtest).** Unchanged by this file: a test A does not count for 1b (the state is in `docs/research/extrapolation-test-4.md`, section 6).
- **Condition 2 (a third application, Bagisto).** **Part A is met** (section 4.4), with no spare on two lines of the count on the 19. **Part B (the lab backtest on Bagisto) has not been done.** Condition 2 asks for the same level as condition 1 on the third application, so it is **not met as a whole**; the number short for part B is not 0.
- **Condition 3 (the remaining misses; provisional until conditions 1 and 2 are met).** Routes with a quantity outside the goal under rule B: **Bagisto 6** (5 marked, 1 unmarked: `GET /api/categories/attributes/{attribute_id}/options`); BookStack, fourth run, 10 (8 marked, 2 unmarked); the verification app 0. No cause was identified for any of the six, so all six are counted as causes not identified. Beside the count, as D243 asks: 29 of the 34 marked routes in the denominator were inside the goal (empty shots of the marks), and the statement that carries a mark may not be the one that gave the miss (not examined). The routes not measured are counted apart and are not misses: with an estimate, `GET /admin/customers/view/{id}`; without a complete estimate, the six of section 4.6, item 2.
- **Conditions 4 and 5** are not touched by this test.

## 6. What can be said, and what cannot

What the test is:

- It checks whether the per-statement fit (H1) with the rules of this round, made from small-size captures, predicts the number of queries, the rows examined and the peak memory of a cold request at 240,000 rows on a third application. The estimate and the marked list were committed before the measurement (section 2).
- **The capture's largest size is 30,000 rows and the test size is 240,000 rows: 8 times.** Agreement or disagreement says nothing about a size beyond 240,000 rows or between the sizes captured.
- **Rule B passes on the 57 routes, and condition 2, part A, is met.** These are two statements and both are true (section 1.3).

What it does not support:

- **A thin margin where part A is decided.** The count on the 19 has no spare on two of its four lines (rows examined 15 of 18; the unmarked goal on queries 5 of 6). One route more outside a line, in either, and part A would not have been met. The count on the 57 has a spare of 3 to 9 routes on its four lines, but 38 of its 56 routes are the ones whose flat counts are not evidence of growth (section 1.4), and the misses are concentrated in the other 18 (section 4.4). The pass on the 57 is real by the rule; it should not be read as a wide one.
- **The routes that were hardest are not in the count.** R1 leaves out six `partial` routes that were cut at the request limit or answered HTTP 500 (section 4.2). R2 shows them as routes not measured, and the pass stands; but the tool gave no estimate and no value for them. One further route with a complete estimate, `GET /admin/customers/view/{id}`, was killed at the memory cap in the cold measurement and is not in the repeat. **Whether the counts would be the same if that route had been in the repeat is not shown** (the maintainer's correction of 2026-11-24 18:45 on the maintainer's clock): leaving it out is expected not to move them, because its cold measurement is already "not measured", but this was not checked.
- **The repeat ran on another database from the cold measurement** (section 2.6), built the same way; the cold measurement's own database was not kept, and the two were never compared by content. This differs from the BookStack run, where the repeat used the cold measurement's database. The time columns of three tables differ between builds, and no measured statement type reads them in a condition or an order, within the limit written in section 2.6, item 4. Two earlier repeats stopped and are void; their measured values were not read (of the first, only the outcome of its trials had been seen, section 4.1), and nobody chose between runs.
- **The gate's reading overlapped one trial.** The gate that compares the databases was read in the same second the first trial of the third repeat began (section 2.6, item 4). Only that trial overlaps it, and it is a route counted as not measured whatever its value.
- **The cold measurement has no per-trial error output**, so a time-out in it cannot be ruled out or found (section 4.6). The rule was applied to the repeat by script; for the cold measurement the rule has nothing to read.
- **Only the routes that were captured and estimated are in the denominator**, 57 of the 120. Sixty-three routes have an estimate row and 57 do not: for 49 of them the cold try failed and for 8 no value was possible. Nothing is said about those 57.
- **The offset mark could not be tested** (section 4.7): it was on no route.
- **The static analysis contributes little on this application** (15 of 543 routes; section 1.5), so the estimate rests on the runtime capture and the EXPLAIN.
- **Generated data and a simple catalogue.** The rows are made by the generator and the application's profile; skew, hot rows and the cache behaviour of a real shop are not reproduced (`docs/research/bagisto-prep/README.md`).
- **The estimate and the measurement databases are not in the same statistics state** (section 2.5, item 3), as for BookStack.
- **One application, one size, once.** 240,000 rows is used up by this run on Bagisto; it cannot be used again as a test of anything repaired afterwards (spec 7.5).
- **Peak memory is reported and does not decide the test:** 22 of 22 inside ±30%, but only 23 of the 63 routes in the estimate have a peak-memory estimate.
- **The test does not show why** the estimate is far from the measured value on the six routes outside the goal (section 4.8). The causes are the next piece of work for condition 3, and nothing in this file has been repaired.

**This file does not claim any precision on a real deployment.**
