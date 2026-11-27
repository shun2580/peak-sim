# What was checked, and what was not: a summary of the verification of the estimates

Status: **a summary, written when the verification was closed on 2026-11-27.** Version 1.0 is **not claimed**. The maintainer ruled on 2026-11-27 that the verification stops here and states the range it covered and its limits, rather than loosening a condition of section 0 of `docs/simulator-spec.md` to reach 1.0. Nothing is measured, repaired or re-scored in this file. Every figure is copied from the record named beside it (the figures of each record are themselves copied from the output of a tool); none is rounded again.

What was checked is **an estimate compared with a measurement made on generated data, on one machine, with the application's own limits set as the record of each test says.** Nothing here was observed on a real deployment, and this file does not claim any precision on one.

## 0. In short

- Two kinds of test were run. **A** compares the estimate of the number of queries, the rows examined and the peak memory of a cold request with a measurement, at a row count that the estimate was not fitted on. **B** compares the predicted database utilization of a busy period reproduced in a lab with the utilization measured there.
- Three applications were used: a small verification app written for this project, BookStack, and Bagisto. B was run on BookStack and, once, on Bagisto.
- **Latest results.** A passed on all three applications at 240,000 rows: the verification app 21 of 21 routes inside the lowest standard on both quantities; BookStack queries 34 of 34 and rows examined 29 of 34 (28 needed, a margin of one route); Bagisto queries 54 of 56 and rows examined 52 of 56. B passed at the goal on BookStack at 180,000 rows (sixth round). **B on Bagisto could not be decided**: the application's container ran out of memory at the 7th step of the first scenario, before the database came near its limit.
- **Before those passes there were failures, and they were not erased.** A failed on BookStack three times (rows examined 15 of 23, 22 of 34, 24 of 33) before it passed. B did not pass in its first round, was undecidable in its third, and missed the goal in its second and fifth. Section 2 lists every round and what was changed after it.
- **What is not shown.** Bagisto's B was not measured, so the second half of condition 2 is not met. 15 of the 16 routes that remained outside the goal have no cause that was identified, and 3 of the 16 had no mark at prediction time (section 4.3), so condition 3 is not met. Some of the passes are thin (section 4.2). The lab has made-up parts of its own (section 5).
- Section 6 says how a reader outside the project can check with `git` that each prediction was committed before the measurement it is compared with.

## 1. What was checked, and how

### 1.1 The two tests

| | A: extrapolation test | B: lab backtest |
| --- | --- | --- |
| Question | Does an estimate made from small captures predict the cost of a cold request at a much larger row count? | Does the prediction of database utilization, made before any load is applied, match the utilization measured in a lab at several request rates? |
| What is compared | Number of queries (lowest standard ±5%, goal ±2%), rows examined (±20%, ±10%), peak memory (±30%; reported, does not decide) | Four items, scored on the point of the prediction: (1) the mean absolute difference of utilization is smaller than the naive estimate's; (2) that mean is 15 points or less (goal: 5); (3) "over 80% or not" agrees at 80% or more of the valid steps; (4) the predicted limit requests per second (the rate at which utilization reaches 80%) is within ±20% (goal: ±10%) of the measured one in 80% or more of the scenarios |
| Pass | Both quantities at 80% or more of the denominator inside the lowest standard (for each application) | All four items at the lowest standard; "met at the goal" needs the goal of each item |
| "Undecidable" | Fewer than 5 routes in a denominator, or more than 20% of the routes with an estimate not measured | Fewer than 10 usable steps, or a limit rate measured for fewer than 4 scenarios |
| Data | Generated; each route's table grown for that route alone | Generated; eight tables grown together; BookStack (and Bagisto) really run under load |

"Undecidable" is not a pass and not a fail. The naive estimate in B scales one calibration run in proportion to the rate, with no growth of the data and no mix of routes.

### 1.2 The way each test was run

These rules were fixed before the first result of each test was seen (sections 7.1 to 7.4 of `docs/simulator-spec.md`; each round's file repeats them):

1. **The prediction is committed first.** The estimates, the list of marked routes (for A) and the prediction file (for B) are committed before the first request is sent to a database of the test's row count. From the third round of B on, the measuring entries refuse to start unless the prediction file is in `HEAD` and has no difference in the working tree.
2. **A row count not seen before, used once.** The test is run at a count that appears in no earlier record as a scale, and each count is used up by its test. A count is not reused to check something repaired afterwards.
3. **A failure is not repaired in the record.** The pass line, the tolerances, the estimates, the predictions and the verdict of a round are not changed after the result is seen. A repair is made after the record is closed, and the next round uses a new row count.
4. **The verdict is rebuilt from the raw records by two readers who do not see each other's work.** In B from the third round on, in A from the fourth run on, and on Bagisto, two readers rebuilt the verdict from the raw records, usually one running the committed verdict scripts and the other writing their own count, and they compared (the second round of B used a second, independent calculation from the same records). Where the two differed, the record says so and says how the difference was closed (for example, test A in its fourth run: 22 against 21 routes of the verification app in a denominator, closed by the written rule). The two readers of test A on Bagisto were not fully blind to the project, and that record says what they had seen.
5. **Two readings of the rules are kept in the open.** Rule B (a marked route is compared with the largest of its four cold values) decides test A; rule A (every route compared with its first cold value) is shown beside it. R1 (a route whose estimate is not complete is outside the denominator) gives the verdict; R2 is shown beside it.

### 1.3 The applications

- **Verification app** (24 routes): a small Laravel application written for this project, with routes that are known to be fine, to be slow, or to hide their cost.
- **BookStack** (36 routes in the estimate of the latest runs): an open-source application, with a data profile written for the tests.
- **Bagisto** (120 routes measured; 63 with an estimate, 57 of them complete): an open-source application not used while the estimate was being repaired. Static analysis found where the data is read for 15 of its 543 routes (for BookStack, 264 of 341 routes), so Bagisto's estimate leans mostly on the statements captured at run time and on `EXPLAIN` (`extrapolation-test-bagisto.md`, section 1).

## 2. Results

### 2.1 Test A, every run

"Multiple" is the test row count over the largest row count the statements were captured at. Counts are inside the lowest standard, rule B, rule R1 where R1 applies. The verification app is not a test of an application anyone else would run; it is shown because it was in every run.

| Run | Rows (multiple) | Prediction commit | Verification app | BookStack | What was changed afterwards |
| --- | ---: | --- | --- | --- | --- |
| 1 (version 0.1) | 100,000 (10×) | `305c928` | **pass**: queries 23 of 23, rows 22 of 23 (95.65%) | **fail**: queries 12 of 12, rows examined **15 of 23 (65.22%)**; 19 needed | The point estimate of rows examined became a fit per statement (H1); EXPLAIN was added to mark routes whose plan may change and to give an upper side (version 0.2). |
| 2 | 200,000 (20×) | `62ef51e` | **pass**: queries 22 of 22, rows 21 of 22 | **fail**: queries 32 of 34, rows **22 of 34 (64.71%)**; 28 needed | Above the capture's largest size a fit whose exponent is above 1 is carried on along its tangent (D174); the capture is made on databases analyzed after the build, with 30,000 rows added to its sizes (D179). |
| 3 | 300,000 (10×) | `206d114` | **pass**: 21 of 21 and 21 of 21 | **fail**: queries 32 of 33, rows **24 of 33 (72.73%)**; 27 needed, 3 short | The point of a marked statement follows the rules P1 and P2 (D225); the BookStack statements were captured again on databases built without the application's own rebuild of `references`, with the state-changing routes measured last (D229, D230). |
| 4 | 240,000 (8×) | `f52f692` | **pass**: 21 of 21 and 21 of 21 | **pass**: queries 34 of 34, rows **29 of 34 (85.29%)**; 28 needed; rule A, shown beside it, **fails** (26 of 34) | Nothing was changed; 240,000 is used up. |

**Bagisto** (the third application, not seen while the estimate was being repaired), 240,000 rows (8×), prediction commit `207911e`: **pass**, rule B, R1. On the 57 routes with a complete estimate, queries 54 of 56 and rows examined 52 of 56 (45 needed for each). On the 19 routes whose data seems to have arrived (section 4.2), the same line is met, and two of its lines have no spare. Nothing was changed afterwards.

Sources: `extrapolation-test.md` (sections 1, 2 and 7), `extrapolation-test-2.md` (section 2.1 and 7), `extrapolation-test-3.md` (section 7), `extrapolation-test-4.md` (sections 4.2 and 5), `extrapolation-test-bagisto.md` (sections 4.2 to 4.4; the commit and time of the prediction, section 2). In run 1 there was no rule B; the figures are single cold values. In run 1 the verification app has 24 routes, one of them not measured at 100,000 rows.

### 2.2 Test B, every round

All rounds are on BookStack except the last row. "Limit rate" is item 4 (±20% / ±10%); "mean" is the mean absolute difference of utilization (item 2) against the naive estimate's.

| Round | Rows (multiple) | Prediction commit | Verdict | Main figures | What was changed afterwards |
| --- | --- | --- | --- | --- | --- |
| 1 | 10,000, 30,000 and 100,000 | `5bdf6e8` | **fail** (lowest standard not met, on item 4; goal not met on items 2 and 4) | mean 12.25 points (naive 23.10); "over 80%" 44 of 47; limit rate 2 of 5 and 1 of 5. At 100,000 rows the predicted limit was 35.1% to 38.5% below the measured | The row estimate (tangent rule, analyzed capture; a wrong shelf-route estimate); the weight of a row in the formula; the mark `app_limit_risk` for the application's time limit; recalibration; statistics kept in order during a measurement; the lab's entry no longer wraps a request in a transaction that is rolled back. |
| 2 | 50,000 and 200,000 | `089d9ad` | **pass at the lowest standard; goal missed on item 4 only** | mean 1.989 (naive 31.933); "over 80%" 27 of 29; limit rate 4 of 4 and **3 of 4** (4 scenarios measured: the least that is not undecidable). The mark failed: 1 hit, 1 miss (the book page at 200,000 rows, stopped by memory), 1 false alarm | The mark gets a memory side (factor 1.51, from the largest measured-over-predicted peak memory in test A's second run, D194, D196). |
| 3 | 300,000 (10×) | `206d114` | **undecidable** | 25 usable steps (10 needed) but a limit rate measured for **1 scenario** (4 needed); mean 4.594 (naive 31.389). The mark passed: 2 hits, 0 misses, 0 false alarms | The load tool had cut off requests still running at the end of a step, at its default of 30 s, and counted only those that had ended. It now counts requests sent inside the window and waits for running requests as long as the request's own limit (D220). A third side was added to the mark: the number of values in one statement against the placeholder limit (D218). |
| 4 | 90,000 (3×); the mark at 150,000 | `e5f406f` | **pass at the goal; an easy test by construction** | mean 1.035 (naive 28.653); "over 80%" 47 of 48; limit rate 5 of 5 within ±10% (largest difference −6.79%). 90,000 is between two counts already seen (70,000 and 100,000). The mark at 150,000: 2 hits, 0 misses, 0 false alarms | The statements were captured again without the application's own rebuild of `references`, and that estimate was used for round 5 (and for test A's fourth run). |
| 5 | 170,000 (5.667×) | `f52f692` | **pass at the lowest standard; goal not reached** | mean 2.541 (naive 32.984); "over 80%" 38 of 39; limit rate **5 of 5 within ±20%, 1 of 5 within ±10%**; in all five scenarios the predicted limit was lower than the measured one, by 8.7% to 15.2% | Only the warm-up of each step (the first part, which is thrown away) was lengthened, from 60 s to 573 s, a length decided from the prediction alone. |
| 6 | 180,000 (6.0×) | `79e38f2` | **pass at the goal** | mean 0.866 over 29 usable steps (naive 33.351); "over 80%" 37 of 37 (naive 32 of 37); limit rate **5 of 5 within ±10%** (−1.43% to −4.36%, all five a little low) | Nothing was changed. |
| Bagisto | 190,000 (6.33×) | `87e8f6ec` | **undecidable** | Six judged steps, all valid; **3 usable steps** (10 needed); limit rate measured for **0 scenarios** (4 needed). The 7th step was not judged | Nothing was repaired; the maintainer ruled not to raise the application's memory limit and not to run s-2 to s-5. |

Sources: `lab-backtest.md` (7.4), `lab-backtest-causes.md` (section 1), `lab-backtest-2.md` (sections 1, 5), `lab-backtest-3.md` (brief, sections 1, 5), `lab-backtest-4.md` (sections 1, 6.2), `lab-backtest-5.md` (sections 1, 4.3), `lab-backtest-6.md` (brief, sections 4, 8), `lab-backtest-bagisto.md` (brief, sections 7, 8).

Two things about the order of the rounds. First, **round 6 is not set side by side with rounds 1 to 5**: its warm-up was 573 s at every step, against 60 s before, and the record itself says no table compares them (`lab-backtest-6.md`, section 7). Second, the goal was reached by item 4 only after the load tool, the mark and the warm-up had been changed one after another; the passes of rounds 4 and 6 are not a result of one fixed method run six times.

### 2.3 What the specification asked for, and where it stands

The conditions are those of section 0 of `docs/simulator-spec.md`. The status is the one in the records at the close; condition 3 is provisional by its own text until conditions 1 and 2 are met.

| Condition | Status at the close |
| --- | --- |
| 1a. A at a size not seen before, for each application | **Met** on the verification app and on BookStack at 240,000 rows (the number short is 0 on all four lines). BookStack's margin is one route on two of the four lines; under rule A it would fail (26 of 34). |
| 1b. B passes at the goal and beats the naive estimate | **Met once**, in the sixth round (180,000 rows, 6.0×). The fifth round (170,000 rows) did not reach the goal; the rounds are not set side by side (section 2.2). |
| 2. A third application (Bagisto), at the same level | **Not met.** A on Bagisto is met, with two lines of the 19-route count at no spare (section 4.2). B on Bagisto is undecidable, and "undecidable counts as not met" (`docs/simulator-spec.md`, section 7.4.7). |
| 3. Every remaining outlier has an identified cause and a mark at prediction time | **Not met, and provisional.** 16 outliers; the cause is identified for 1; 3 had no mark (section 4.3). |

Sources: `extrapolation-test-4.md` (section 6), `extrapolation-test-bagisto.md` (sections 4.4 and 5), `lab-backtest-6.md` (section 8.4), `lab-backtest-bagisto.md` (section 8.5), `outlier-inventory.md` (section 4).

## 3. What matched

These are what the records show, at the row counts and on the applications named. They are not a claim about another application or another size.

- **Database utilization, in the lab, on BookStack.** At 180,000 rows (6.0× the capture) the mean absolute difference between predicted and measured utilization was 0.866 points over 29 usable steps, against 33.351 for the naive estimate. "Over 80% or not" agreed at 37 of 37 steps (naive: 32 of 37). At 170,000 rows the mean was 2.541 points (naive 32.984) and the threshold agreed at 38 of 39 steps. At 90,000 rows the mean was 1.035 (an easy test, section 2.2). The prediction beat the naive estimate on item 1 in every round that was decided.
- **The limit rate, at the sixth round.** The predicted limit rate was within ±10% of the measured one in 5 of 5 scenarios, from −1.43% to −4.36%. In the fifth round it was within ±20% in 5 of 5 and within ±10% in 1 of 5. In every scenario of rounds 4, 5 and 6 the prediction was lower than the measurement, that is, on the side of reaching the limit early. The cause of the bias was not separated in the records.
- **The cost of one request.** At 240,000 rows the number of queries of a cold request was inside ±5% for 21 of 21 routes of the verification app, 34 of 34 routes of BookStack and 54 of 56 routes of Bagisto. Rows examined were inside ±20% for 21 of 21, 29 of 34 and 52 of 56. Inside the goal (±2% and ±10%) on the unmarked routes: BookStack queries 15 of 15 and rows examined 13 of 15; Bagisto queries 21 of 22 and rows examined 22 of 22; the verification app 21 of 21 on both. On the verification app the largest error of rows examined was 1.83% (`outlier-inventory.md`, section 5).
- **The mark for the application's limits.** In B, the mark had 2 hits, 0 misses and 0 false alarms at 300,000 rows and the same at 150,000 rows (the book page and the ZIP export).
- **The order of work.** In all of these, the prediction was committed before the first measurement (section 6).

## 4. What did not match, and what could not be measured

### 4.1 B on Bagisto

B on Bagisto at 190,000 rows (6.33× the capture) stopped at the 7th step (0.2 requests per second) of the first scenario, when the application's container, limited to 6 GiB, was killed for memory (the `oom_kill` counter went from 0 to 3, three php-fpm children ended on signal 9). The database was at 23.4220% at the last judged step. The load tool's summary counted one 502 among the 80 requests of the window, on `GET /api/products`. The record reads that one heavy route is the cause: a single `GET /api/products` request takes about 150 s at this count, several overlap, and together they passed the container's limit. **That is a reading from the times, the route of the 502 and the number of requests in flight, not a measurement of the memory of each request; the cause is not established.** The memory mark of the prediction had not evaluated this route, because the route has no memory fit.

By the rule, the limit rate of the first scenario is "not measured"; the other four scenarios were not run. The six steps that were judged lie between 4.37% and 23.42% and are **not** a test of the prediction: they are far from 80%, and the record does not set them against the prediction. **The result says nothing about whether the database prediction on Bagisto is good or bad.** (`lab-backtest-bagisto.md`, sections 7 and 8.)

Two more facts about that B belong here. The request timeout was 2,400 s, not BookStack's 1,800 s, ruled after the upper bound of one request at this count had been seen and before any measured value was seen; it can work in favour of a pass, and no request in the run came near either limit (the longest was 162.1 s). And 22 runs measured before the fix of the lab's web server (section 5) were set aside, and the search of the calibration rates was made again.

### 4.2 Thin margins

- **BookStack, test A, fourth run.** Rows examined 29 of 34 against 28 needed. `GET /shelves/{slug}` is outside ±20% by 0.23 point (20.23%). Under rule A the same data give 26 of 34 and a fail; the two rules differ because six marked routes move inside the line and three move outside. Four of the heavy values rule B takes appeared in one lap of the repeat only, and their cause was not examined (`extrapolation-test-4.md`, sections 4.5 and 4.6). Whether the change of the estimate or the change of the size made the difference between the third and fourth runs is not separated, because the two changed together.
- **Bagisto, test A.** In the capture, 38 of the 57 routes with a complete estimate carry the capture's own note that the data may not have reached the route (the size of the answer is the same at every row count), so a flat count on them is not evidence that growth was predicted. The 19 others are the count that is set beside the 57 for that reason. On the count of the 57 routes the spare is 3 to 9 routes on the four lines. On the count of the 19 routes whose data seems to have arrived (the routes whose capture suggests the data reached them), two lines have no spare: rows examined 15 of 18 against 15 needed, and the goal on the unmarked routes' queries 5 of 6 against 5 needed. **One more route outside the line would have left condition 2, part A, unmet.** Six `partial` routes (an incomplete capture) are outside the denominator by R1; one of them, `GET /admin/marketing/promotions/cart-rules/edit/{id}`, is the only route whose capture verdict was O(N), and it answered HTTP 500 in the capture and ran past 600 s in the repeat.
- **B, fifth round.** Item 4 missed the goal by 3 scenarios (1 of 5 within ±10%; 4 needed), with all five predictions low by 8.7% to 15.2%.
- **B, second round.** Item 4 was measured for exactly 4 scenarios, the least that is not undecidable; at ±10% it was 3 of 4.

### 4.3 The 16 routes that remained outside the goal

Condition 3 asks that every outlier left has a cause that is identified and a mark at prediction time. Outliers are counted from test A (a route with a quantity outside the goal under rule B, R1). B at 180,000 rows has none, and B on Bagisto could not be counted. The verification app has none. Peak memory is not counted.

"Mark" is the list of routes marked in test A (`plan_changes_at_scale` or `plan_unstable`), counted as a mark by the maintainer's ruling D243 (judgement 1). "Cause" uses the definition of `outlier-inventory.md`: *identified* means a measurement or numbers in a record back the cause; *guessed* means a one-line reading; *unknown* means no investigation is recorded. When in doubt the lower class is taken. For Bagisto, the records say "no cause was examined", which is *unknown* by that definition; the inventory itself covers BookStack only, and applying its definition to Bagisto is a reading made for this summary.

**BookStack, 240,000 rows (10 routes)**

| Route | Quantity | Predicted | Measured | Measured ÷ predicted | Mark | Cause |
| --- | --- | ---: | ---: | ---: | --- | --- |
| `GET /shelves/{slug}/references` | rows examined | 84,217 | 324,204 | 3.850 | yes | guessed: the heavy value appears in one lap of the repeat only, and again at 70,000 rows |
| `GET /books/{bookSlug}/page/{pageSlug}` | rows examined | 1,608,578 | 1,008,237 | 0.627 | yes | guessed: the measured rows follow 4.2·N + 237 as at 200,000 and 300,000 rows; one statement's estimate fits as 2.6·N against a reading of 0.2·N |
| `PUT /shelves/{slug}` | rows examined | 276,268 | 504,226 | 1.825 | yes | guessed: one lap only, as the first row |
| `GET /books/{slug}/references` | rows examined | 84,217 | 168,207 | 1.997 | yes | guessed: one lap only, as the first row |
| `GET /shelves/{slug}` | rows examined; queries | 283.75; 18.878 | 236; 18 | 0.832; 0.953 | yes | rows: **unknown**; queries: guessed (two statement counts are 1.43876, not whole numbers) |
| `DELETE /books/{id}` | rows examined | 2,157,529 | 1,860,501 | 0.862 | yes | guessed: the point rule takes the rows of a scan as the rows read; the laps of the repeat differ |
| `POST /books/{bookSlug}/draft/{pageId}` | rows examined | 647,651 | 768,544 | 1.187 | **no** | guessed: two statements make 99% of the rows; one reads 0.80·N against 0.27·N in the estimate |
| `PUT /books/{bookSlug}/page/{pageSlug}/revisions/{revId}/restore` | rows examined | 275,590 | 324,245 | 1.177 | yes | guessed: one lap only |
| `POST /settings/maintenance/regenerate-references` | rows examined | 316,828,223 | 370,678,285 | 1.170 | yes | guessed: the estimate is below the measurement in the second, third and fourth runs |
| `DELETE /settings/maintenance/cleanup-images` | rows examined | 27,989,601 | 32,280,434 | 1.153 | **no** | **identified**: the route reads the `images` table in chunks of 1,000 by offset (`app/Uploads/ImageService.php:183-184` in the BookStack copy used), which grows with the square of the row count; seen directly at 70,000 rows |

Of the 10: 8 marked (6 `plan_changes_at_scale`, 2 `plan_unstable`), 2 not marked; cause identified 1, guessed 8, unknown 1; outside the lowest standard 5, outside the goal only 5 (`outlier-inventory.md`, sections 4 and 5).

**Bagisto, 240,000 rows (6 routes)**

| Route | Quantity | Predicted | Measured | Measured ÷ predicted | Mark | Cause |
| --- | --- | ---: | ---: | ---: | --- | --- |
| `GET /api/categories/tree` | queries; rows examined | 515.05; 15,760,555 | 4; 480,011 | 0.008; 0.030 | yes | not examined |
| `GET /customer/account/rma/get-order-items/{orderId}` | rows examined | 37 | 9 | 0.243 | yes | not examined |
| `POST /customer/account/orders/cancel/{id}` | rows examined | 2,051,731 | 960,006 | 0.468 | yes | not examined |
| `POST /admin/sales/invoices/create/{order_id}` | rows examined | 3,251,774 | 2,160,028 | 0.664 | yes | not examined |
| `GET /api/categories/attributes/{attribute_id}/options` | queries | 30.97 | 23 | 0.743 | **no** | not examined; the route has an offset statement and the offset mark did not mark it |
| `GET /api/categories` | rows examined | 960,224 | 1,200,029 | 1.250 | yes | not examined; the heavy value is the cold measurement's only, the three laps of the repeat read 960,049 on a different database |

Of the 6: 5 marked, 1 not marked; outside the lowest standard 5, outside the goal only 1 (`GET /api/categories`, 19.98%, inside ±20% by 0.02 point). Five of the six are estimated higher than measured (`extrapolation-test-bagisto.md`, sections 4.5 and 4.8).

**Together: 16 outliers; the cause is identified for 1 and not identified for 15; 3 had no mark at prediction time** (BookStack `POST /books/{bookSlug}/draft/{pageId}` and `DELETE /settings/maintenance/cleanup-images`, Bagisto `GET /api/categories/attributes/{attribute_id}/options`). Condition 3 is provisional because conditions 1 and 2 are not both met. The investigation of these causes was not continued after the maintainer's ruling of 2026-11-27.

### 4.4 The marks

- **False alarms.** Of the 19 BookStack routes marked in test A at 240,000 rows, 11 were inside the goal. Of the 34 marked Bagisto routes in the denominator, 29 were inside the goal on both quantities. A mark that is right does not show that its reason is right: for two BookStack routes the marked statement may not be the one that gave the error (`extrapolation-test-3-causes.md`, section 5).
- **The offset mark** (`offset_chunk_fit`, proposed after the BookStack outliers were known) was on **no** Bagisto route, so there is no hit and no false alarm. Its effect was not confirmed on Bagisto. Its count on BookStack (2 marked, 2 hits, 0 false alarms) is a fit to records already seen and not a test (`outlier-inventory.md`, section 4; `extrapolation-test-bagisto.md`, section 4.7).
- **A route left out of the test.** `GET /books/{slug}` on BookStack answered HTTP 500 (SQLSTATE 1390, more placeholders in one statement than the database allows) in every request at 300,000 rows and in all four tries at 240,000 rows. It was taken out of the mix of every B scenario from the prediction alone, at 170,000 and 180,000 rows, so B says nothing about it. Test A also leaves routes out of its denominators: on the verification app `GET /ng/first-or-create-in-loop` (an estimate, but the measuring process ended with exit 139), `GET /ng/nested` and `POST /ok/bulk` (estimates not complete); on BookStack `POST /books/{bookSlug}/copy` (estimate not complete; it ran past 600 s in all four tries). They are counted apart, not as misses (`extrapolation-test-4.md`, section 4.7).

## 5. What the lab cannot say

**The lab is not production, and no figure here is a precision on a production system.** The following are limits of the lab itself; each also holds wherever a result of B is quoted.

- **What the lab leaves out** (`lab-backtest.md`, section 6; `lab-backtest-6.md`, section 10). Cache behaviour of a real deployment; data larger than the database's memory (the buffer pool is made large on purpose so that every scenario's data fits); contention with other work on the same machine; the load of writes (only GET routes are loaded, and in the early rounds every request was rolled back). The database is fixed at 2 CPUs with a much larger application, so the database is the first limit by construction; a deployment where the application limits first is outside what B checks, and in Bagisto's case the application's memory did.
- **The generated data.** The generator's text bodies hold no links to each other, so the application's own rebuild of `references` would leave that table empty at every row count; the rebuild was run on no database of the later rounds. The tables that other routes read are as the generator wrote them, not in the state a real deployment's own maintenance would leave. Skew and hot rows are not reproduced.
- **Limits lifted for B.** B is measured with the application's run-time limit lifted and the PHP memory limit at 4 GB inside a container of 6 GiB, with a long request timeout. It says nothing about an application stopping early by time or by memory at its default settings, and the memory mark is the only part of the prediction that looks at that.
- **Things the lab adds that a real deployment would not have.** Each request costs one statement more than in production (the entry script asks which database it is on, as a guard of safety). The database uses about 0.9 point of CPU when it is asked nothing, which is in the measurement and not in the prediction (the measured utilization is higher than the predicted one at 29 of 32 steps where both are below 80% in the sixth round). The Bagisto lab and the capture fix session, cache and queue to fakes (array, sync), so Bagisto's health check runs on every admin request, and at each request the application reads `core_config` twice with a full scan, which the estimate does not contain (`lab-backtest-bagisto.md`, section 9).
- **The edges of a measurement window.** Requests that were sent in a window and ended after it have their database work outside the window, so the measured utilization can be a little lower than the true one. In the sixth round only the ZIP export had requests running at the edges (0 to 7 at the start, 0 to 8 at the end), and the count at the end minus the count at the start was within one request at every step. A warm-up that is too short, as in rounds 1 to 5 at 60 s, mixes the build-up of load into the window; **round 6 lengthened it to 573 s** and says that a real busy period also ends, which a steady-load measurement does not show.
- **A hole in the web server, found and fixed in one lab only.** In the Bagisto lab the web server's upstream keepalive (`keepalive 64`) with `fastcgi_keep_conn on` tied the application's 16 workers to idle connections after a load window, so stuck requests could invalidate a run or make its utilization too low. It was found during the search of the calibration rates; the two lines were taken out of the **Bagisto lab's** configuration only, and the fix was confirmed by one run. All 22 runs measured before it were set aside. **The BookStack lab still has both lines. Whether the BookStack runs were touched by the same gap was not examined**, so the two labs' web server configurations differ and the BookStack records are unchanged (`lab-backtest-bagisto.md`, section 2).
- **How to close each of these.** None of these was done or tested; they are directions, not results. Run the same checks on a real deployment's own traffic (this was never done, and is the only way to say anything about production precision); give the lab a database larger than memory and a write load; take the lines `keepalive 64` and `fastcgi_keep_conn on` out of the BookStack lab and re-measure, with a new row count; give the application container more memory than this machine can give, or lower the number of overlapping heavy requests, so that B can reach the database's limit on an application with a very heavy route; evaluate routes that have no memory fit instead of leaving them out of the memory mark.
- **Applications, counts and repeats.** Three applications; one row count for each test and each application, used once; a single cold measurement of an unmarked route and four of a marked route (the four values are not an estimate of the full spread, and a route near a line could fall on either side on another run). The calibration is made per application, so the verdicts on BookStack and on Bagisto are not set side by side as pass and fail.

## 6. How to check that each prediction was committed before its measurement

This section is for a reader who has only a clone of this repository and `git`. The claim to check is narrow: **the file that holds a prediction was added in a commit that is an ancestor of the first commit that holds the records of the measurement, the file has not changed since, and the records of the measurement are not in the prediction's commit.** The steps below were run on the clone as it is at the close of the verification; the outputs quoted are the ones they gave.

### 6.1 The steps

For a test, take the prediction file from the record of its round (each record has a table of the prediction commit, its time and the files in it: sections 1 and 2 of the test A records, sections 3 to 6 of the lab records). Then:

1. **Find the commit that added the file.**
   `git log --diff-filter=A --format='%H %cI %s' -- <prediction file>`
2. **Check that nothing touched the file afterwards.**
   `git log --format='%h %cI %s' -- <prediction file>` shows one commit, and `git diff --quiet <commit> HEAD -- <prediction file> && echo equal` prints `equal`.
3. **Check the order by ancestry.** Find the first commit that holds the records of the measurement (`git log --reverse --format='%h %cI %s' -- <records folder> | head -1`) and run `git merge-base --is-ancestor <prediction commit> <that commit> && echo ancestor`.
4. **Check that the records of the measurement are not in the prediction's commit.**
   `git cat-file -e <prediction commit>:<records folder> || echo absent`.
5. **Check the content against the gate's output.** The measuring entries run a gate before they start (`PREDICTION OK <file> <commit> <time>`), and for the sixth round of B the gate's output also records the file's sha256. Compute `git show <commit>:<prediction file> | sha256sum` and compare.
6. **Read the times as extra information.** `%cI` is the commit time; the first measured step has its own start time in its record. The times are in section 6.2.

### 6.2 Two worked examples, as they ran

B on Bagisto (prediction file `poc/lab/predictions/ladder-prediction-bagisto-b.json`):

```
$ git log --diff-filter=A --format='%H %cI %s' -- poc/lab/predictions/ladder-prediction-bagisto-b.json
87e8f6ecc3cb87334bc124c9c8f3df891e86b34f 2026-11-27T02:25:59+09:00 feat: add the prediction of the Bagisto backtest with the 2400 second timeout
$ git diff --quiet 87e8f6ec HEAD -- poc/lab/predictions/ladder-prediction-bagisto-b.json && echo equal
equal
$ git show 87e8f6ec:poc/lab/predictions/ladder-prediction-bagisto-b.json | sha256sum
cb955d5bfb247d48c59d87992f5acb73729a3819f0eed6c4ccb9c8ef69f30028  -
$ git merge-base --is-ancestor 87e8f6ec eb929a21 && echo ancestor      # eb929a21 first holds the records of the measurement
ancestor
$ git cat-file -e 87e8f6ec:docs/research/lab-data/bagisto-ladder-190k || echo absent
absent
```

A first start at 2026-11-27T02:33:07+09:00 stopped before any request was sent; the chain of measurements was started again at 2026-11-27T02:35:55+09:00 (`chain-log.txt`, 9 min 56 s after the commit); the commit that first holds the records is `eb929a21` (06:57:25). Seven commits lie between them, none touches the prediction file. The gate line `PREDICTION OK … 87e8f6ec…` is in `docs/research/lab-data/bagisto-b-prediction/second/prediction/prediction-gate.txt` and in the measuring tool's own output (`docs/research/lab-data/bagisto-ladder-190k/run-20261127/chain-1/s-1.output.txt`). That gate output has no sha256 line for this test, so the comparison of step 5 is the reader's own `sha256sum` above.

B at 180,000 rows on BookStack, sixth round (prediction file `poc/lab/predictions/ladder-prediction-6-b180k.json`):

```
$ git log --diff-filter=A --format='%H %cI %s' -- poc/lab/predictions/ladder-prediction-6-b180k.json
79e38f2944ba2d62ab5694d91534a1f88d49d430 2026-11-21T20:24:36+09:00 feat: add the prediction for the sixth ladder run at 180,000 rows
$ git show 79e38f29:poc/lab/predictions/ladder-prediction-6-b180k.json | sha256sum
be0076be321139ebe87b762e971b621875d14c0f1be0130d39e6be64eaab3808  -
$ grep -E 'sha256|PREDICTION' docs/research/lab-data/prediction-6/gate-output.txt | cut -c1-140
sha256 of the file: be0076be321139ebe87b762e971b621875d14c0f1be0130d39e6be64eaab3808
PREDICTION OK poc/lab/predictions/ladder-prediction-6-b180k.json 79e38f2944ba2d62ab5694d91534a1f88d49d430 2026-11-21T20:24:36+09:00
$ git merge-base --is-ancestor 79e38f29 14ce4a5d && echo ancestor     # 14ce4a5d first holds the records of the measurement
ancestor
$ git cat-file -e 79e38f29:docs/research/lab-data/ladder-180k || echo absent
absent
```

The sha256 equals the one in the gate's output. The first run began at 2026-11-21 20:27:29 (2 min 53 s after the commit), was stopped during rung 3 of the first scenario and is not used; all five scenarios were measured again from the start (`lab-backtest-6.md`, section 3.2). The commit that first holds the records is `14ce4a5d` (21:47:03).

### 6.3 Every prediction, its commit time and the first measurement

The commit hashes and times are those of `git log` on this repository; the first-measurement times are those written in the records named.

| Test | Prediction commit | Commit time (`%cI`) | First measurement, from the record |
| --- | --- | --- | --- |
| A, run 1 (and 100,000-row records) | `305c928` | 2026-11-04T17:09:44+09:00 | 17:10:24 (verification app); 17:51:57 (BookStack) (`extrapolation-test.md`, section 1) |
| B, round 1 | `5bdf6e88` | 2026-11-10T10:18:07+09:00 | 10:19:58 (`lab-backtest.md`, section 7.3) |
| A, run 2 | `62ef51e` | 2026-11-09T08:45:44+09:00 | 08:49:09 (verification app) (`extrapolation-test-2.md`, section 1) |
| B, round 2 | `089d9ad` | 2026-11-15T05:14:41+09:00 | 05:21:46 (`lab-backtest-2.md`, section 5.1) |
| A, run 3 and B, round 3 | `206d114` | 2026-11-16T08:53:18+09:00 | B 09:02:09; A 17:31:13 (`lab-backtest-3.md`, section 5.1; `extrapolation-test-3.md`, section 1) |
| B, round 4 (and the mark at 150,000 rows) | `e5f406f` | 2026-11-17T09:08:30+09:00 | the mark 09:17:22; B 11:54:46 (`lab-backtest-4.md`, sections 4.4 and 6.1) |
| A, run 4 and B, round 5 | `f52f692` | 2026-11-19T17:40:41+09:00 | B 2026-11-19 17:47:41; A 2026-11-20 06:46:30 (`lab-backtest-5.md`, section 2.4; `extrapolation-test-4.md`, status line) |
| B, round 6 | `79e38f2` | 2026-11-21T20:24:36+09:00 | 20:27:29 (first run, not used) (`lab-data/ladder-180k/README.md`) |
| A on Bagisto | `207911e` | 2026-11-24T13:04:39+09:00 | 14:57:57 (`extrapolation-test-bagisto.md`, status line) |
| B on Bagisto | `87e8f6ec` | 2026-11-27T02:25:59+09:00 | 02:37:13 (step 1; a first start at 02:33:07 stopped before any request was sent, and the chain was started again at 02:35:55) (`lab-backtest-bagisto.md`, section 7.1) |

The records of B of the second and third rounds say that every measured step names the same prediction commit and commit time (`prediction` in the step record); the second round's record also says that `git diff` over `poc/` and the specification between the prediction and `HEAD` is empty (`lab-backtest-2.md`, section 5.1, and `lab-backtest-3.md`, section 5.1). A reader can run the same `git diff` for any round.

### 6.4 What this check does not prove

- **A commit's time is the time on the clock of the machine that made it.** `git commit --date` or a changed clock can alter it. What a reader can rely on is the order of ancestry (the prediction's commit is an ancestor of the commit that first holds the measurement) and the chain of hashes (a commit's hash covers its parents), not the minute written beside it.
- **This does not show that the prediction was public before the measurement.** If the whole history is published in one `git push`, the hosting service's time stamp does not separate the commits. What can be said is that the prediction is in the history before the commit that holds the records of the measurement, not that anyone outside the project could have seen it first.
- **The time of the measurement has an independent trace** in the records the lab's tools keep outside the repository's commits (the load tool's summary, the database logs, the containers' start times); each lab record gives the start of its first step. That supports the clock, and does not replace the ancestry.
- **A branch of rewritten history exists in the clone.** The hashes in this section are those on the current main branch, and a reader should trace on that branch.
- **The file's content is checked only where the gate recorded a hash.** For B on Bagisto the gate's output has no sha256 line.

## 7. Where the verification stops

- **Version 1.0 is not claimed.** The conditions of section 0 of `docs/simulator-spec.md` were not loosened; they stand, and they are not all met (section 2.3).
- **What is shown:** on three applications, at 8 times the captured size (240,000 rows), the estimate of the number of queries and of rows examined was inside the lowest standard for most routes on the last run of each application; and in the lab on BookStack, at 180,000 rows, the predicted database utilization and limit rate agreed with the measurement within the goal. Each was measured on generated data, once, at one row count.
- **What is not shown:** B on Bagisto; the cause of 15 of the 16 outliers; the effect of the offset mark; anything about a real deployment; anything about row counts that were not tested.
- **Next steps** (moving the tool into `src/`, a public article) are for the maintainer to decide after this summary.
