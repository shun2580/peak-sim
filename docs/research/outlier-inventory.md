# Inventory of the remaining outliers: a look ahead at condition 3

Status: **This is a record made from existing records, with one section (5.4) that records a measurement of the causes at 70,000 rows made afterwards. Bagisto has not been measured, and its outliers are not in this inventory. This inventory does not meet condition 3 of section 0 of `docs/simulator-spec.md`; it counts how many outliers are left, and why, before Bagisto is measured (D242). Marks are counted as D243 rules: the list of marked routes of A counts as a mark, and every count of marks is shown beside the false alarms and the doubt about rows 2 and 5.** Revised after the first version: the cause of row 10 was raised to identified by reading the application's code (section 5.3); the share counts of section 8.6 were corrected (an earlier version read the estimate's statement text, which is cut at 160 characters, and missed an offset); and the causes of rows 1 to 9 were sorted again after the measurement at 70,000 rows (section 5.4), which moved 6 rows from unknown to guessed, left row 5a unknown and raised none to identified. Section 8.9 records the offset mark that D245 added to the tool and counts it apart from the count above; that count is not changed.

The part that lists the outliers and counts them (sections 1 to 7 and 9) and the part that proposes how to strengthen the marks (section 8) are written.

Condition 3 asks that every outlier left after conditions 1 and 2 is met has a cause that is identified one route at a time, and that the tool can say "this may not hold" at prediction time. An outlier whose cause is not known keeps version 1.0 back. Conditions 1 and 2 are not both met yet (condition 2 is the third application, Bagisto, and it is not measured), so any count of condition 3 stays provisional (D241, D242). This file looks at the two latest tests only and gives the count as it stands in the records; the measurement at 70,000 rows (section 5.4) is not a counted test.

**In brief:**

1. **Outliers: 10.** All 10 are BookStack routes in the fourth run of A at 240,000 rows (11 rows, because one route is outside the goal on two quantities). The verification app has none. B at 180,000 rows has none.
2. **Outside the lowest standard: 5** of the 10. Each of the 5 had a mark at prediction time (counted as D243 rules; see the caveat in item 4).
3. **Causes: identified 1, guessed 8, unknown 1.** So **9 outliers have no identified cause**, which is what condition 3 asks for and what is missing. The one identified is row 10 (`DELETE /settings/maintenance/cleanup-images`), read in the application's code (section 5.3) and seen directly at 70,000 rows (section 5.4). The 8 guessed rows have a statement, a plan or a number that fits, none shown at 240,000 rows; the one unknown is row 5a (`GET /shelves/{slug}`), whose statements were measured but whose reason was not examined and for which no guess is made (section 5.4); the four that were heavy on one lap only (rows 1, 3, 4, 8) were heavy again at 70,000 rows, and why is not established (section 5.4). Before the measurement the counts were guessed 2 and unknown 7.
4. **Marks at prediction time: 8 of the 10 had one, 2 did not** (`POST /books/{bookSlug}/draft/{pageId}` and `DELETE /settings/maintenance/cleanup-images`). The 8 are on the list of marked routes of A (`plan_changes_at_scale` 6, `plan_unstable` 2). The maintainer ruled that this list counts as the mark of condition 3, counted by route as in the test of the marks (spec 7.4.1), so the count of routes with **no** mark is **2** (D243, judgement 1).
   **Caveat, set beside every count of marks (D243):** (a) of the 19 BookStack routes that carry a mark in A at 240,000 rows, **11 were inside the goal** (11 false alarms); (b) for rows 2 and 5 the marked statement may not be the one that gave the error (`extrapolation-test-3-causes.md` section 5, 300,000-row run), so a mark can be right while its reason is not. Raising the precision of the marks is a task for after version 1.0.
   **The offset mark of D245, counted on its own line and not in the count above (section 8.9):** if the offset mark is added, the route newly marked is `DELETE /settings/maintenance/cleanup-images` (one route), and the routes with no mark are `POST /books/{bookSlug}/draft/{pageId}` (one route). Say it with the same four points: **the shape of the mark was chosen after the outliers were known, the limit "only statements run twice or more" was also added after its result was seen, the count "2 marked, 2 hits, 0 false alarms" is a fit to records already seen and not a test, and the effect of the mark can be tested only on Bagisto**. Beside this count too (D243): of the 19 BookStack routes that carry a mark in A at 240,000 rows, 11 were inside the goal, and for rows 2 and 5 the marked statement may not be the one that gave the error; with the offset mark the marked routes are 20, 11 of them inside the goal.
5. **Counted apart: 6 routes** (A 5, B 1) that could not be measured or whose estimate is not complete. They are not outliers and are not in the counts above.
6. **Bagisto is not measured.** Its outliers are to be added when it is.

## 1. What was done

The records of the two latest tests were read again, and every route or step outside the goal was listed with its numbers, whether the prediction carried a mark when it was fixed, and what the records say about the cause. This is a look ahead at condition 3 of section 0 of the spec. **For sections 1 to 5.3 it is a record only: no measurement was made for them, and no estimate, rule, tool, earlier record or lab data was changed.** The measured numbers are copied from the earlier records and are not rounded again. Section 5.4 records the measurement of the causes at 70,000 rows that D243 ruled, made afterwards; it changed no estimate, rule, tool, earlier record or lab data either.

Two readers rebuilt the table of A without seeing each other's work, and a third read both and checked the raw records. They agreed on every number, every mark and 9 of the 11 rows' causes. The two rows on which they differed were decided by the third reader against the records: one by taking the lower class (row 5a), one by checking the arithmetic of the guess (row 10). Both are marked in section 5.3. B was rebuilt from the raw records by one reader and checked by another, and the two agree.

## 2. What is counted

Only the two latest tests are counted:

- **A: the fourth run of the extrapolation test, 240,000 rows** (`extrapolation-test-4.md`, D235). The prediction was fixed in `f52f692`.
- **B: the sixth round of the lab backtest, 180,000 rows** (`lab-backtest-6.md`, D240, D241). The prediction was fixed in `79e38f2` (`poc/lab/predictions/ladder-prediction-6-b180k.json`).

Older tests are not counted. Condition 3 counts what is left after conditions 1 and 2 are met, and 1a and 1b were met in these two tests. Older misses (for example BookStack in A at 300,000 rows, or item 4 of B at 170,000 rows) were used only as a clue to the cause of the same route, through `extrapolation-test-3-causes.md`, `lab-backtest-5-causes.md` (D237) and the decision records.

Routes that were not measured, or whose estimate is not complete, are not outliers. They are listed in section 7 and counted apart.

## 3. Definitions

### 3.1 What is an outlier

The definition is that of section 7.4 of the spec. It is not new.

- **A:** under rule B, a route whose number of queries or number of rows examined is outside the **goal** (±2% for queries, ±10% for rows). Whether it is also outside the **lowest standard** (±5%, ±20%) is shown in a column. A route outside the goal on two quantities has two rows and counts as one route.
- **B:** a scenario whose predicted limit rps is outside the goal (±10%) in item 4, or a step for which "did it pass the threshold" differs from the measurement in item 3. The difference of utilization of a step is **not** an outlier, because the spec has no per-step line for it. Steps whose difference is more than 5 points can be shown in a separate table for reference, and they are not counted.

### 3.2 The columns of a row

1. Test, application, route (for B, scenario and step) and quantity.
2. Predicted (the estimate's point), measured (rule B: for a marked route, the largest of its four cold values), ratio (measured divided by predicted), relative error (|predicted − measured| divided by measured), and band (outside the goal only, or outside the lowest standard).
3. **The mark at prediction time**: present or absent; if present, its name and the file it was read from at the commit of the prediction. The mark was judged **only from the output committed with the prediction** (`f52f692` for A, `79e38f2` for B). A reason found afterwards does not turn "absent" into "present".
4. **The cause**, in one of three classes (section 3.3).

### 3.3 The three classes of cause

- **identified:** the cause is backed by a measurement or by numbers in a record, and the record's path (and section or D number) is given.
- **guessed:** a one-line guess, with the record the guess was made from.
- **unknown:** the records have no investigation of the cause.

**When in doubt, the lower class is taken** (identified or guessed: guessed; guessed or unknown: unknown). A route with two rows takes the lower of the two classes for the route count.

How the marks are read is given in the table of section 5.2. The names are those of the tool's output: `plan_changes_at_scale` and `plan_unstable` (the list of marked routes of A), and `app_limit_risk_memory` and `app_limit_risk_placeholders` (the limit marks of B). The maintainer ruled in D243 (judgement 1) that the list of marked routes of A (`plan_changes_at_scale` and `plan_unstable`) counts as the mark of condition 3, by route, as in the test of the marks (spec 7.4.1). **This file copies the names and the files they come from, and follows that ruling.**

## 4. The numbers at the head

Bagisto is not measured and is not in this table.

| Item | A 240,000 | B 180,000 | Total |
| --- | ---: | ---: | ---: |
| Outliers (routes; for B, scenarios or steps) | **10** (BookStack 10, verification app 0; 11 rows) | **0** | **10** |
| Of these, outside the lowest standard | 5 (all 5 with a mark) | 0 | 5 |
| Cause identified | 1 | 0 | **1** |
| Cause guessed | 8 | 0 | 8 |
| Cause unknown | 1 | 0 | 1 |
| No mark at prediction time | **2** (the list of marked routes of A counts as a mark, D243) | 0 | **2** |
| Counted apart (outside the denominator, not counted above) | 5 routes | 1 route | 6 |

- **Causes not identified: 9** (guessed 8, unknown 1). The first version of this file said 10 (unknown 7, guessed 3, the same number as the "remaining 10 (provisional)" of D241); row 10 was raised from guessed to identified afterwards (section 5.3), and after the measurement at 70,000 rows 6 rows moved from unknown to guessed and row 5a stayed unknown (section 5.4; a row is guessed, not identified, when no mechanism is shown at 240,000 rows). The number of outliers stays 10.
- The routes with no mark are counted as **2**, because the maintainer ruled that the list of marked routes of A is a mark in the sense of condition 3 (D243, judgement 1; the other reading, which would give 10, is kept in section 8.2 as a record and was not taken).
- **Caveat, set beside every count of marks (D243):** of the 19 BookStack routes that carry a mark in A at 240,000 rows, **11 were inside the goal** (11 false alarms); and for rows 2 and 5 the marked statement may not be the one that gave the error (`extrapolation-test-3-causes.md` section 5, 300,000-row run), so a mark can be right while its reason is not. Raising the precision of the marks is a task for after version 1.0. The "all 5 with a mark" of the table is a count of this kind, and rows 2 and 5a are two of those 5.
- By rows instead of routes, the classes of the 11 rows are: identified 1, guessed 9, unknown 1.
- **Counted on a separate line (D245; section 8.9). The count "No mark at prediction time: 2" in the table above is not changed.** If the offset mark is added, the route newly marked is `DELETE /settings/maintenance/cleanup-images` (1 route; `regenerate-references` was already on the list of marked routes of A), so the routes with no mark are `POST /books/{bookSlug}/draft/{pageId}` (1 route). This is stated with its four points in the one sentence: **the shape of the mark was chosen after the outliers were known, the limit "only statements run twice or more" was also added after its result was seen, the count "2 marked, 2 hits, 0 false alarms" is a fit to records already seen and not a test, and the effect of the mark can be tested only on Bagisto**. The doubt of D243 goes beside it: of the 19 BookStack routes that carry a mark in A at 240,000 rows, 11 were inside the goal (11 false alarms), and with the offset mark 11 of 20 marked routes are inside the goal; and for rows 2 and 5 the marked statement may not be the one that gave the error. Raising the precision of the marks is a task for after version 1.0.

## 5. A at 240,000 rows: BookStack

The verification app has no outlier. The numbers that show it: 21 routes in the denominator, the largest error in the number of queries 0.00%, and the largest error in rows examined 1.83% (`GET /ok/chunk` and `GET /ok/lazy`).

### 5.1 The 10 routes (11 rows)

Ratio is measured divided by predicted. The relative error is |predicted − measured| divided by measured. The predicted value is the estimate's point, and the measured value is that of rule B (for a marked route, the largest of its four cold values).

| # | Route | Quantity | Predicted | Measured | Ratio | Relative error | Band |
| ---: | --- | --- | ---: | ---: | ---: | ---: | --- |
| 1 | `GET /shelves/{slug}/references` | rows examined | 84,217 | 324,204 | 3.850 | 74.02% | outside the lowest standard |
| 2 | `GET /books/{bookSlug}/page/{pageSlug}` | rows examined | 1,608,578 | 1,008,237 | 0.627 | 59.54% | outside the lowest standard |
| 3 | `PUT /shelves/{slug}` | rows examined | 276,268 | 504,226 | 1.825 | 45.21% | outside the lowest standard |
| 4 | `GET /books/{slug}/references` | rows examined | 84,217 | 168,207 | 1.997 | 49.93% | outside the lowest standard |
| 5a | `GET /shelves/{slug}` | rows examined | 283.75 | 236 | 0.832 | 20.23% | outside the lowest standard |
| 5b | `GET /shelves/{slug}` | queries | 18.878 | 18 | 0.953 | 4.88% | outside the goal only |
| 6 | `DELETE /books/{id}` | rows examined | 2,157,529 | 1,860,501 | 0.862 | 15.96% | outside the goal only |
| 7 | `POST /books/{bookSlug}/draft/{pageId}` | rows examined | 647,651 | 768,544 | 1.187 | 15.73% | outside the goal only |
| 8 | `PUT /books/{bookSlug}/page/{pageSlug}/revisions/{revId}/restore` | rows examined | 275,590 | 324,245 | 1.177 | 15.01% | outside the goal only |
| 9 | `POST /settings/maintenance/regenerate-references` | rows examined | 316,828,223 | 370,678,285 | 1.170 | 14.53% | outside the goal only |
| 10 | `DELETE /settings/maintenance/cleanup-images` | rows examined | 27,989,601 | 32,280,434 | 1.153 | 13.29% | outside the goal only |

Routes outside the lowest standard: 5 (rows 1 to 5a). Route 5 is counted once, on both of its rows.

### 5.2 The mark at prediction time

Read from `poc/lab/predictions/extrapolation-4-bookstack-marked.json` and `extrapolation-4-bookstack-estimate.json` at `f52f692`. None of the four prediction files of A at that commit has a field named `app_limit_risk`, `app_limit_risk_memory` or `app_limit_risk_placeholders`; those fields were not part of A's output then.

| # | Route | Mark at `f52f692` | Statement ids |
| ---: | --- | --- | --- |
| 1 | `GET /shelves/{slug}/references` | `plan_changes_at_scale` | `sd7799eeccf` |
| 2 | `GET /books/{bookSlug}/page/{pageSlug}` | `plan_unstable` | `sf034e6d815` |
| 3 | `PUT /shelves/{slug}` | `plan_changes_at_scale` | `s1a3fa8aa3c`, `sd7799eeccf` |
| 4 | `GET /books/{slug}/references` | `plan_changes_at_scale` | `sd7799eeccf` |
| 5 | `GET /shelves/{slug}` | `plan_unstable` | `sf034e6d815` |
| 6 | `DELETE /books/{id}` | `plan_changes_at_scale` | `sad4efc33ee` |
| 7 | `POST /books/{bookSlug}/draft/{pageId}` | **no mark** | |
| 8 | `PUT …/revisions/{revId}/restore` | `plan_changes_at_scale` | `s1a3fa8aa3c`, `sd7799eeccf` |
| 9 | `POST /settings/maintenance/regenerate-references` | `plan_changes_at_scale` | `s55d8c74b35` |
| 10 | `DELETE /settings/maintenance/cleanup-images` | **no mark** | |

Marked: 8 routes (`plan_changes_at_scale` 6, `plan_unstable` 2). Not marked: 2 (counted as D243 rules). All 5 routes outside the lowest standard had a mark. **Beside these counts (D243):** of the 19 BookStack routes that carry a mark in A, 11 were inside the goal (11 false alarms), and rows 2 and 5 may be marked on a statement that is not the one that gave the error (second fact below). Raising the precision of the marks is a task for after version 1.0.

Two facts from the records that bear on how to read these marks, copied from the records. The second is one of the two doubts that D243 asks to set beside every count of marks:

- For 5 of the 8 marked routes (rows 1, 3, 4, 8 and 9), the measured value lies between the estimate's point and its upper side (`rows_examined_upper`). For rows 2, 5a and 6, the measured value is below the point, and the upper side points upward only.
- The marks of rows 2 and 5 are on statement `sf034e6d815` only. For the 300,000-row run, `extrapolation-test-3-causes.md` section 5 wrote that on the same route the marked statement is not the one that gave the error. The record has no list of the statements that gave the error at 240,000 rows.

### 5.3 The cause of each row, in one line

Classes follow section 3.3. "Record" is a path under `docs/research/` unless it starts with `poc/`.

| # | Quantity | Class | One line, and the record it comes from |
| ---: | --- | --- | --- |
| 1 | rows examined | guessed | The heavy value 324,204 appears in one lap of the repeat only: the repeat laps read 84,210, 84,210 and 324,204, and the first cold run read 84,210. The database was removed afterwards (D236), so it could not be examined (`extrapolation-test-4.md` section 4.6). **At 70,000 rows the heavy run appeared again in the last four trials of the third lap:** one statement (`sc40f193985`) grew from 8 to 70,002 rows, its plan changed from `range` to `index`, and the statistic of the `references` table had fallen to 0.2·N and below. That the fall caused the change of plan is consistent with the records but not shown, and why the statistic falls, and whether the same happened at 240,000 rows, is not established. Section 5.4 |
| 2 | rows examined | guessed | The measured rows follow 4.2·N + 237, the same line as at 200,000 and 300,000 rows. The estimate is higher. An arithmetic reading that fit: if statement `s7ff3e12408` read 0.2·N rows instead of 2.6·N, the sum would match (D225 records that the cause is not established; `extrapolation-test-3-causes.md` sections 6 and 10.3). **At 70,000 rows** the route reads 2.2·N + 239 without ANALYZE and 6.6·N + 248 after it, the estimate has the shape of the analyzed state, and `s7ff3e12408` reads 0.60·N without ANALYZE (not 0.2·N; `s976b7c7275` is also low). So the guess is now that the repeat's database had no ANALYZE; which state it was in at 240,000 rows is not established. Section 5.4 |
| 3 | rows examined | guessed | As row 1. The repeat laps read 276,232, 276,232 and 504,226. At 70,000 rows the same statement as in row 1 (`sc40f193985`) grew, from 8 to 66,502 rows. Section 5.4 |
| 4 | rows examined | guessed | As row 1. The repeat laps read 84,210, 84,210 and 168,207. At 70,000 rows `sd7799eeccf` grew from 24,501 to 48,998 rows and its plan changed from `ref` to `index_merge`. Section 5.4 |
| 5a | rows examined | unknown | The estimate is 47.75 rows above the measured 236. In the first version the record did not say which statement is high (the two readers differed: one wrote guessed, one unknown, and unknown was taken because the restatement says no mechanism). **At 70,000 rows**, where this route reads the same 236 rows, the difference is carried by two statements estimated above what they read: `sf034e6d815` (24.17 against 2, 22.17 of the 47.75) and `s67def0f140` (23.60 against 5, 18.60). Why they are estimated so high was not examined, and no guess is made, so the row stays unknown (the lower class is taken when in doubt). Section 5.4 |
| 5b | queries | guessed | The counts of two statements are not whole numbers (1.43876 each), and 16 + 2 × 1.43876 = 18.8775, which matches the estimate 18.878. The record is the estimate at `f52f692` (`poc/lab/predictions/extrapolation-4-bookstack-estimate.json`). |
| 6 | rows examined | guessed | A guess: the point rule P1 takes the rows of a scan as the rows read. D225 (ruling 1) records an error in the same direction at 200,000 rows. The laps of the repeat differ (1,860,501, 1,248,500, 1,248,500) (`extrapolation-test-3-causes.md` end of section 3). At 70,000 rows the laps differ too (543,001, 490,431, 543,032), through one statement (`sad4efc33ee`, 5 runs per lap, 17,501 to 66,504 rows per run); why was not measured. Section 5.4 |
| 7 | rows examined | guessed | The route read 3.2·N + 544 rows at 300,000 rows as well. **At 70,000 rows** two statements are 99% of the rows, and after ANALYZE the route reads 3.2·N + 547, the same shape. One of them, `s68a46df765`, reads 0.80·N against 0.27·N in the estimate; at 240,000 rows that is 126,345 rows more, near the miss of 120,893. This is arithmetic that assumes the 0.80·N shape holds at 240,000 rows; no tap exists there. Section 5.4 |
| 8 | rows examined | guessed | As row 1. The repeat laps read 276,246, 276,246 and 324,245. At 70,000 rows `sd7799eeccf` grew from 10,501 to 24,500 rows. Section 5.4 |
| 9 | rows examined | guessed | In the second, third and fourth runs of A the estimate for this route is below the measurement. **At 70,000 rows** two statements are 99.8% of the rows. A call of `s55d8c74b35` reads 0.61·N rows against 0.48·N in the estimate, and a call of `sc6102fdbc3` 0.70·N against 0.68·N. That `s55d8c74b35` is the short one is consistent with the records but not shown (no per-statement value at 240,000 rows). Section 5.4 |
| 10 | rows examined | identified | **Established (read in the code, and the numbers agree):** the route reads the `images` table of type `gallery` or `drawio` with `chunk(1000, …)` (`app/Uploads/ImageService.php:183-184` in the BookStack copy used for the tests, commit `045c6a9`; route `routes/web.php:233`, controller `app/Settings/MaintenanceController.php:36-59`, body `ImageService.php:178-207`). The framework's `chunk` reads by offset: `$offset = (($page - 1) * $count) + $skip`, the next page while a page returns a full chunk (`vendor/laravel/framework/src/Illuminate/Database/Concerns/BuildsQueries.php:40-80`, Laravel v13.35.0). So the offset grows by 1,000 on each read, the number of reads is floor(M/1,000) + 1 (M is the number of rows of those types) and the rows read grow with the square of M. The capture records the same statement with `limit 1000 offset 30000`. At 240,000 rows: 241 reads and 1,000 × (1 + … + 240) + 240,000 = 29,160,000 rows. The measured 32,280,434 less the two counting statements that use `LIKE` (240,000 × 5 and 240,000 × 8 rows, 3,120,000 together) is 29,160,434: the same to 434 rows (0.0015%). **Established (why the estimate came out low):** the rows per read of this statement are N up to 1,000 rows and about N/2 + 1,000 above, so the curve bends at the chunk size, and the estimate's formula `a + b·N^k` cannot follow a bend. Fitted to the four captured sizes (100, 1,000, 10,000, 30,000) with the tool's own fit (`poc/runtime/src/Judge/CostFit.php`), the exponent for rows per read is 0.91 and for the count 0.99, both bending downward, and the fit gives a count of 236.55 and 105,133 rows per read, the same as the estimate file at `f52f692` in every digit shown (the sums for the runs at 200,000 and 300,000 rows reproduce in the same way). Against the code's 241 and 120,996, the count is 1.9% low, the rows per read 13.1% low and the chunk rows 14.7% low; that one statement accounts for almost all of the 13.29% of the route. **Consistent with the records but not shown:** that M equals N (the number of images equals the number of generated rows). The captured counts (1, 2, 11, 31) would be the same for any M in [N, N + 999], and the number of rows of those types at 240,000 rows is not recorded; the sum above agrees to 0.0015%, which supports it. **Not established:** what the remaining 434 rows (against the code's formula) and 234 rows (the rest of the estimate) come from; they are other statements and were not measured. Records: `extrapolation-data-4/measured-240000/bookstack/attempts.jsonl`, the capture of the fourth run (`capture.json` of the 31 routes; checksum in `extrapolation-data-4/bookstack/sha256.txt`; not in the repository), `poc/runtime/profiles/bookstack.php:66-68` (every image is made type `gallery`), and the estimate at `f52f692`. This does not change any rule or estimate. Whether the estimate's formula should be able to bend is a change of rule and is not proposed here. **At 70,000 rows (section 5.4)** the calls are N/1,000 + 1 at five sizes and the rows read follow the square to within 72 rows. |

Counts by route (a route with two rows takes the lower class): identified 1 (`DELETE /settings/maintenance/cleanup-images`); guessed 8 (`GET /shelves/{slug}/references`, `GET /books/{bookSlug}/page/{pageSlug}`, `PUT /shelves/{slug}`, `GET /books/{slug}/references`, `DELETE /books/{id}`, `POST /books/{bookSlug}/draft/{pageId}`, `PUT …/revisions/{revId}/restore`, `POST /settings/maintenance/regenerate-references`); unknown 1 (`GET /shelves/{slug}`, row 5a). Before the measurement at 70,000 rows the counts were guessed 2 and unknown 7; the 6 that moved are rows 1, 3, 4, 7, 8 and 9, and row 5a stayed unknown (section 5.4).

The second row on which the two readers differed is row 10. One wrote unknown; the other wrote guessed, because the guess is a mechanism that was checked against the recorded row counts. The third reader checked the arithmetic and took guessed, since the chunk size of 1,000 had not been checked in the application's code. That was later checked (the row above), and the row is now identified. The "guessed 3" and "identified 0" of the first version are therefore corrected to guessed 2 and identified 1.

### 5.4 The cause measurement at 70,000 rows

D243 (judgement 3) ruled that the causes are measured at 70,000 rows, a development size that is not used for a test, alongside the preparation for Bagisto. **This section records that measurement. It is not a counted test, it does not change the number of outliers, and it changes no estimate, mark rule, tool, earlier record or lab data.** A claim about the 240,000-row records is said to be so; every other number is from the 70,000-row records.

**What was run.** The same task list, the same BookStack copy (`045c6a9`) and the same copy of the tool as the repeat at 240,000 rows (commit `7a4cf32`), with `--stats-auto-recalc=keep` and a request limit of 600 seconds. Two things differ from that repeat: the size (70,000 rows) and the per-statement tap, which is on here (`--tap=on`; the repeat had it off).

| Step | What | Time (2026-11-24, start and end of the script) | Trials |
| --- | --- | --- | ---: |
| Laps | the 21 routes of the repeat in the same order and the same four groups, 3 laps, one database per group, no ANALYZE | 02:16:17 to 04:15:15 (1 h 59 min) | 63 |
| One at a time | rows 2, 5, 9, 7 and 10, once without ANALYZE and once after `ANALYZE TABLE` on every table, 2 groups, databases other than those of the laps | 02:16:18 to 02:28:23 (12 min) | 10 |
| Row 10 by size | `DELETE /settings/maintenance/cleanup-images` at 100, 1,000, 10,000 and 30,000 rows (the captured sizes), ANALYZE before, as in the capture | 02:30:44 to 02:35:34 (5 min for the four) | 4 |

- All 77 trials ended with exit 0. A tap exists for 74. The 3 trials of `POST /books/{bookSlug}/copy` (one per lap) were cut at the request limit (606 to 607 seconds, status 500) and have none; in the repeat at 240,000 rows the same route ran past 600 seconds in all four runs.
- The copy of the tool at `7a4cf32` is older than the current one: it removes the database volumes when it ends (`09514cd` later made the teardown keep them unless `--purge` is given). So it was run with `--keep-stack`, and each database container was stopped with `docker stop -t 180` and exited with code 0. The list of volumes taken before and after each step shows none gone.
- Statement ids are those of the estimate and the capture: the first ten digits of the sha1 of the statement's type text as `StatementShape::pattern` makes it (literals and the length of `in (…)` lists taken out, case kept). A count of rows is the sum of `rows_sum` over the statements of the id.
- The laps and the one-at-a-time step are two databases built from the same recipe. They have the same number of rows in all 41 tables measured. The checksums of 4 tables (`role_permissions`, `roles`, `settings`, `users`) differ, and why was not examined. A value from one step is not carried to the other without saying so.
- The records are in `docs/research/outlier-causes-data/`.

**Heavy on one lap only (rows 1, 3, 4, 8).** Rows read in the window, lap 1, lap 2, lap 3 (the order of the trial in the third lap is in brackets):

| Row | Route | Lap 1 | Lap 2 | Lap 3 | The statement that grew (rows read, lap 1, 2, 3) |
| ---: | --- | ---: | ---: | ---: | --- |
| 1 | `GET /shelves/{slug}/references` (60) | 24,710 | 24,710 | **94,704** | `sc40f193985`, `select distinct from_type from references`: 8, 8, 70,002 |
| 3 | `PUT /shelves/{slug}` (59) | 80,732 | 80,732 | **147,226** | `sc40f193985`: 8, 8, 66,502 |
| 4 | `GET /books/{slug}/references` (58) | 24,710 | 24,710 | **49,207** | `sd7799eeccf`, `select * from references where to_type = ? and to_id = ? …`: 24,501, 24,501, 48,998 |
| 8 | `PUT …/revisions/{revId}/restore` (57) | 80,746 | 80,746 | **94,745** | `sd7799eeccf`: 10,501, 10,501, 24,500 |

- **Established:** the heavy run appeared again. The same four routes, in the last four trials of the third lap, as in the repeat at 240,000 rows; so it is not something that happens only at the larger size. Laps 1 and 2 read the same rows. In each route one statement is the only one whose count or rows changed between lap 1 and lap 3.
- **Established:** the plan of that statement changed in the heavy lap (the tap's EXPLAIN of its first run). `sc40f193985`: `range` on `references_from_type_index` (estimated rows 4) in lap 1, `range` (2) in lap 2, `index` (1) in lap 3. `sd7799eeccf`: `ref` on `references_to_id_index` (27,086) in lap 1, `ref` (17,474) in lap 2, `index_merge` of `references_to_id_index` and `references_to_type_index` (1,737) in lap 3.
- **Established:** the statistic of the `references` table fell in the same period. Its PRIMARY cardinality was first read as 69,948 (after order 40) and then fell by 7,000 after every PUT trial: 62,948 after order 41, then 55,948, 48,948, 41,948, 34,948, 27,948, 20,948, 13,948, 6,948 and 0 after order 59. The stored `innodb_table_stats` row of the table stayed at 69,948 (last update 18:43:05). The first heavy run (order 57) ran with 13,948 before it (about 0.2·N); orders 58, 59 and 60 ran with 6,948, 6,948 and 0. The statistic alone does not draw the line: order 56, another route (`GET /books/{bookSlug}/page/{pageSlug}/references`), also ran with 13,948 and read the same rows as in laps 1 and 2.
- **Established:** no other route of the 21 is heavy in lap 3 alone. The six routes of the first group (the deletes and the recycle bin) read between 364,438 and 595,484 rows from lap to lap without a fixed order.
- **Consistent with the records but not shown:** that the heavy run follows from the fall of the statistic and the change of plan with it (the plan changed in the heavy runs, and the statistic was lowest then); and that the fall is tied to repeating PUT on the same database and not to the number of rows (it falls by the same 7,000 at each PUT, and the heavy runs are in the third lap). Neither reading was tested, for example by changing the number of laps.
- **Not established:** why a PUT lowers the statistic; the real number of rows of the table (it was not counted: the 70,002 and 66,502 rows read agree with about 70,000 rows, which is not a count); whether the heavy laps at 240,000 rows had the same statement, plan and statistic (that repeat had the tap off and no EXPLAIN, and its database no longer exists, D236); where between 13,948 and 0 the plan changes; whether the heavy run moves when the number of laps or the order changes. **The cause of rows 1, 3, 4 and 8 at 240,000 rows is not established.** They are guessed, not identified.
- **Beside the marks (D243):** for rows 4 and 8 the statement that grew (`sd7799eeccf`) is a marked statement of the route. For rows 1 and 3 it is not: the marks of row 1 are on `sd7799eeccf`, and those of row 3 on `sd7799eeccf` and `s1a3fa8aa3c`, and neither changed between laps; the statement that grew is `sc40f193985`.

**Estimate too high (rows 2, 5, 6).**

Row 2, `GET /books/{bookSlug}/page/{pageSlug}`, rows read:

| State | `s976b7c7275` (`select name, id, slug, … from entities left join entity_page_data …`) | `s7ff3e12408` (`select * from entities left join entity_container_data …`) | Window |
| --- | ---: | ---: | ---: |
| 70,000 rows, one at a time, without ANALYZE | 111,997 (1.60·N) | 42,001 (0.60·N) | 154,239 |
| 70,000 rows, one at a time, after ANALYZE | 280,000 (4.00·N) | 181,999 (2.60·N) | 462,248 |
| 70,000 rows, in the laps (no ANALYZE; lap 1, 2, 3) | | | 462,247, 462,247, 462,246 |
| Estimate at 240,000 rows (`f52f692`) | 978,390 (4.08·N) | 629,913 (2.62·N) | 1,608,578 |

- **Established:** ANALYZE changes the rows of this route 3.0 times (154,239 to 462,248), and the two statements above carry 308,001 of the 308,009 rows of the difference. They are the same two statements as the estimate's (ids by the capture's rule).
- **Established:** after ANALYZE the two statements have the shape of the estimate (4.00·N and 2.60·N against 4.08·N and 2.62·N); without ANALYZE they are far below it (1.60·N and 0.60·N).
- **Established:** in the laps the route read the value of the analyzed state in all three laps, because the automatic update of the statistic of `entities` (`n_rows` 69,673 to 71,811, at the end of order 20, 18:30:31) came before the route ran in order 21. The one-at-a-time run without ANALYZE ran on the earlier statistic.
- **Established, from the 240,000-row record:** the route read 1,008,237 = 4.2·N + 237 there. At 70,000 rows it reads 2.2·N + 239 without ANALYZE and 6.6·N + 248 with it. The 240,000-row value lies between the two states.
- **Consistent with the records but not shown:** that the miss comes from the repeat's database having no ANALYZE while the database of the capture had one. This is the direction of `extrapolation-test-3-causes.md` section 10.3, and the estimate has the shape of the analyzed state.
- **Not established:** which statistic state the repeat's database had when the route ran at 240,000 rows (4.2·N is neither state); how much of the miss the missing ANALYZE explains (the 240,000-row value without ANALYZE was not measured). The earlier arithmetic reading, that `s7ff3e12408` read 0.2·N instead of 2.6·N, is not what the unanalyzed state gives here: it reads 0.60·N, and `s976b7c7275` is also low (1.60·N against 4.08·N). **The row stays guessed, with a changed guess.**
- **Beside the marks (D243):** the marked statement of row 2 is `sf034e6d815` (`select * from views …`). At 70,000 rows it differs from the estimate by 14.00 rows (16 against 2) without ANALYZE and 13.00 after it. The difference between the estimate and the tap is carried by `s976b7c7275` and `s7ff3e12408`, which are not marked. This was measured at 70,000 rows, not 240,000, so it bears on the doubt of D243 and does not settle it.

Row 5, `GET /shelves/{slug}`. The route reads 236 rows at 70,000 rows without ANALYZE, 238 after it (the laps: 237, 237, 236), and 236 at 240,000 rows, so its rows do not depend on N and the tap can be set against the estimate at 240,000 rows. Statement by statement, estimate (count times rows per call) against the tap without ANALYZE:

| Statement | Estimate | Read at 70,000 rows | Difference |
| --- | ---: | ---: | ---: |
| `sf034e6d815`, `select * from views …` (the marked statement) | 24.17 | 2 (3 after ANALYZE) | 22.17 |
| `s67def0f140`, `select * from activities …` | 23.60 | 5 | 18.60 |
| `sae79dbbc2c`, `select count(*) from references …` | 10.77 | 8 | 2.77 |
| `s6a6174c841`, `select exists(select * from favourites …)` | 3.35 | 2 (3 after ANALYZE) | 1.35 |
| `sa8fffb0b26` | 2.88 (1.43876 × 2) | 2 | 0.88 |
| `s933461e5b8` | 3.80 | 3 | 0.80 |
| the other 10 statements together | | | 1.19 |
| The route | 283.75 | 236 | 47.75 |

- **Established (5a):** the 47.75 rows of difference are carried mostly by two statements estimated above what they read: `sf034e6d815` (22.17, 46%) and `s67def0f140` (18.60, 39%); the difference between the 236 and the 238 rows is one row of `sf034e6d815` and one of `s6a6174c841` (each 2 rows without ANALYZE, 3 after).
- **Established (5b):** the route runs 18 queries at 70,000 rows, as it did at 240,000. `sa8fffb0b26` and `sde990ad951` run once each, where the estimate has 1.43876 each; 16 + 2 × 1.43876 = 18.8775, which is the estimate's 18.878.
- **Not established:** why the estimate has 24.17 rows for `views` and 23.60 for `activities` (this was not examined), and why the counts of the two statements are fitted to 1.43876 instead of 1. **Row 5a stays unknown** (the statements are measured, the reason is not, and no guess is made); 5b stays guessed.
- **Beside the marks (D243):** the marked statement of row 5, `sf034e6d815`, is the largest single part of the difference (46%), and an unmarked statement, `s67def0f140`, is the second (39%). The mark is on a statement that is part of the error and not the whole of it. Same limit: 70,000 rows, a route whose rows do not depend on N.

Row 6, `DELETE /books/{id}`:

- **Established:** the statement that moves between laps is `sad4efc33ee` (`delete from deletions where deletable_type = ? and deletable_id = ? …`). It runs 5 times in every lap and reads 262,503, 210,000 and 262,503 rows (windows 543,001, 490,431, 543,032). Per run it reads from 17,501 to 66,504 rows (lap 1 and 3) and from 14,001 to 63,004 (lap 2). The other statements of the route read the same in the three laps. The repeat at 240,000 rows read 1,860,501, 1,248,500 and 1,248,500 on its three laps.
- **Not established:** why the rows of one run range so widely (it was not measured whether it depends on the position of the target in `deletions`); whether the premise of the point rule P1, that the rows of a scan are the rows read, holds here (the tap has the rows read per run, not the rows scanned); why the laps differ. **The row stays guessed.**

**Always heavier than the estimate (row 9),** `POST /settings/maintenance/regenerate-references`, rows read:

| Statement | Calls and rows, without ANALYZE | Calls and rows, after ANALYZE | Estimate at 240,000 rows: calls, rows per call |
| --- | ---: | ---: | ---: |
| `s55d8c74b35` (`select id, description_html from entities left join entity_container_data …`) | 423; 17,948,623 | 423; 18,017,023 | 1,416; 114,361 (0.48·N) |
| `sc6102fdbc3` (`select id, html from entities left join entity_page_data …`) | 281; 13,866,721 | 281; 13,866,721 | 943; 163,920 (0.68·N) |
| Window | 31,886,239 | 31,954,647 | |

- **Established:** the two statements are 99.8% of the window. Of the 68,408 rows between the two states, 68,400 are `s55d8c74b35`; the other 8 are one statement on `webhooks`. The laps read 31,954,647 in all three laps. At 70,000 rows a call of `s55d8c74b35` reads 42,432 rows on average (0.61·N) and a call of `sc6102fdbc3` 49,348 (0.70·N).
- **Consistent with the records but not shown:** that the statement short in the estimate is `s55d8c74b35` (0.48·N in the estimate against 0.61·N here), and not `sc6102fdbc3` (0.68·N against 0.70·N).
- **Not established:** whether the rows of a call are proportional to N (the chunks advance by offset, so it depends on the position), because the 240,000-row records have no value per statement. **Row 9 moves from unknown to guessed.**

**No mark (rows 7 and 10).**

Row 7, `POST /books/{bookSlug}/draft/{pageId}`, rows read:

| Statement | Without ANALYZE | After ANALYZE | Estimate at 240,000 rows |
| --- | ---: | ---: | ---: |
| `s68a46df765` (`select max(priority) … left join entity_page_data …`) | 55,999 (0.80·N) | 55,999 (0.80·N) | 65,655 (0.27·N) |
| `sc26304c1f7` (`select max(priority) … left join entity_container_data …`) | 28,001 (0.40·N) | 167,999 (2.40·N) | 581,439 (2.42·N) |
| Window | 84,540 | 224,547 (3.2·N + 547) | 647,651 |

- **Established:** the two statements are 99.4% of the window without ANALYZE and 99.8% after it. `s68a46df765` does not change with ANALYZE; `sc26304c1f7` reads 6 times more after it. The analyzed window has the shape of the 240,000-row measurement (768,544 = 3.2·N + 544).
- **Consistent with the records but not shown:** that the miss at 240,000 rows comes from `s68a46df765`. `sc26304c1f7` agrees with the estimate (2.40·N against 2.42·N); `s68a46df765` is 0.80·N here against 0.27·N in the estimate, which at 240,000 rows is 126,345 rows more than the estimate, the same order as the miss of 120,893 rows. This is arithmetic that takes the 0.80·N shape to hold at 240,000 rows.
- **Not established:** which of the two statements gave the miss at 240,000 rows (no tap there); whether the rows of a call are proportional to N. **Row 7 moves from unknown to guessed.** The offset-share mark gives no ground for this route (it has no offset statement); no mark rule for it is proposed here, and the choice stays with the maintainer.

Row 10, `DELETE /settings/maintenance/cleanup-images`, by size (`sdf9dc81d9e` is `select * from images where type in (?, ?) order by images.id asc limit ? offset ?`):

| N | Calls of `sdf9dc81d9e` | Rows read | Rows / N | Window | Seconds |
| ---: | ---: | ---: | ---: | ---: | ---: |
| 100 | 1 | 102 | 1.020 | 1,602 | 1 |
| 1,000 | 2 | 2,003 | 2.003 | 15,203 | 3 |
| 10,000 | 11 | 65,012 | 6.501 | 195,212 | 30 |
| 30,000 | 31 | 495,032 | 16.501 | 885,232 | 88 |
| 70,000 | 71 | 2,555,072 | 36.501 | 3,465,264 | 204 |

- **Established:** the number of calls is N/1,000 + 1 at all five sizes, and the rows read follow 500·m·(m+1) + N (m = N/1,000, rounded down) to within 72 rows at every size, the largest difference being 0.003% at 70,000 rows. This is the square read in the application's code (section 5.3), seen directly. After ANALYZE the 70,000-row window is 3,465,272 (8 rows more) and `sdf9dc81d9e` is unchanged: this route does not depend on ANALYZE. At 70,000 rows `sdf9dc81d9e` is 73.7% of the window; the two `LIKE` counts (8 and 5 rows per image, N calls each, 560,000 and 350,000 rows) are 16.2% and 10.1%.
- The class stays identified. The "Not established" part of section 5.3 (the 434 and 234 rows) is not touched by this measurement.

**What the measurement changes in the causes.** Before it, 7 of the 11 rows had no investigation (unknown). After it, each of rows 1, 3, 4, 8 (statement, plan and statistic recorded), 7 and 9 (statements measured) has an investigation and a stated reading, and none is shown at 240,000 rows, so each moves from unknown to **guessed**. Row 5a has its statements measured but no stated reading, so it stays **unknown**. No row is raised to identified. Rows 2, 5b and 6 stay guessed and row 10 stays identified. Counts by route: identified 1, guessed 8, unknown 1 (section 4). **A reader who wants a mechanism shown before a row counts as guessed would put some of these rows back to unknown (section 9).**

**Beside the counts of marks (D243), unchanged by this measurement:** of the 19 BookStack routes that carry a mark in A at 240,000 rows, 11 were inside the goal (11 false alarms), and routes with no mark are 2. The doubt that a mark can be right while its statement is not the one that gave the error is not removed: at 70,000 rows, for row 2 the difference is carried by unmarked statements; for row 5 the marked statement is 46% of it; for rows 1 and 3 the statement that grew is not a marked one. These are measurements at another size, and the 240,000-row records still have no statement list for the error. Raising the precision of the marks is a task for after version 1.0.

**Limits.** This is 70,000 rows. A comparison with 240,000 rows is a comparison of the shape of statements, except for rows 5 and 10 where it is stated why it holds. Bagisto is not measured, so this section does not say that condition 3 is met with these 10 routes. The offset-share mark of section 8.6 and the treatment of static analysis stay with the maintainer.

## 6. B at 180,000 rows

**Outliers: 0.** Both conditions of the definition hold:

- **Item 4:** the predicted limit rps is inside ±10% of the measured one in **5 of 5** scenarios. The prediction is a little lower than the measurement in all five.

  | Scenario | Pair of measured rps around the limit | Predicted (point) | Measured | Ratio | Relative error |
  | --- | --- | ---: | ---: | ---: | ---: |
  | s-1 | 0.15 to 0.2 | 0.17816 | 0.18366 | 1.0309 | −3.00% |
  | s-2 | 0.07 to 0.1 | 0.09545 | 0.09744 | 1.0208 | −2.04% |
  | s-3 | 0.3 to 0.45 | 0.38165 | 0.39425 | 1.0330 | −3.20% |
  | s-4 | 0.1 to 0.15 | 0.12726 | 0.12911 | 1.0145 | −1.43% |
  | s-5 | 0.45 to 0.7 | 0.50876 | 0.53194 | 1.0455 | −4.36% |

  The relative error here is (predicted − measured) divided by measured, as the spec defines it for item 4. None is outside ±20% either.
- **Item 3:** "over 80% or not" agrees at **37 of 37** valid steps. Changing the test from "80% or more" to "more than 80%" gives the same result, because no measured step is exactly 80% (the nearest is 81.94%, step 5 of s-2).

**How the 0 was checked.** The prediction was read from the blob at `79e38f2`, not from the working tree (`scenarios[].limit_rps.point.rps`, `scenarios[].stages[].over_threshold.point` and `…utilization.point`). The measurements were read from `docs/research/lab-data/ladder-180k/run-2-20261122/s-N-180000/rung-*/record.json` (`db_utilization`, `decided_rps`, `rps_valid`); the first launch of s-1, which is invalid, was not used. The measured limit rps is the linear interpolation between the two neighbouring valid steps that straddle 80% (spec 7.2). The figures were calculated again from the records and agree with section 8 of `lab-backtest-6.md`.

**For reference, not counted.** Of the 29 usable steps, none differs from the prediction by more than 5 points (the largest is 3.69 points, s-1 at 0.2 rps: measured 86.11%, predicted 89.81%), and the mean absolute difference is 0.866 points. Among all 37 steps, one differs by more than 5 points: s-5 at 0.7 rps (measured 99.30%, predicted 110.07%, a difference of 10.77 points, not more than 15). It is not a usable step because the measured utilization is above 90%, and **it is not counted**, as the definition of section 3.1 says.

**The marks of B** are given only for the record, since B has no outlier. In the prediction at `79e38f2`, the list of marked routes has `GET /shelves/{slug}` in all five scenarios, `app_limit_risk_placeholders` marks `GET /books/{slug}` (one statement carries 108,009 values, 1.648 times the limit of 65,535), and `app_limit_risk_memory` marks no route. The EXPLAIN of the estimate input (`9fe817a`) marks `plan_unstable` on 3 statements and `plan_changes_at_scale` on none.

## 7. Counted apart: 6 routes (A 5, B 1)

These are routes that could not be measured, or whose estimate is not complete (A: section 4.7 of `extrapolation-test-4.md`; B: section 2.2 of `lab-backtest-6.md`). They are outside the denominator, are not outliers, and are not in the counts of section 4. Their number is given so that it is not lost.

| Test | Application and route | Why | Class of the reason | Mark at prediction time |
| --- | --- | --- | --- | --- |
| A | verification app, `GET /ng/first-or-create-in-loop` | the measuring child process ended with exit code 139 | unknown | none (the verification app had no mark) |
| A | verification app, `GET /ng/nested` | the estimate is partial: the capture was cut at 60 seconds | identified (why the route is slow was not examined) | none |
| A | verification app, `POST /ok/bulk` | the estimate is partial (SQLSTATE 1390) and the measurement ran past 600 seconds | identified for the estimate; unknown for the 600 seconds | none |
| A | BookStack, `GET /books/{slug}` | SQLSTATE 1390 in all four runs | identified | `plan_unstable` (`f52f692`); `app_limit_risk_placeholders` is not a field of A's four prediction files, though B's prediction has it for this route |
| A | BookStack, `POST /books/{bookSlug}/copy` | the estimate is partial (`insufficient_scales`) and all four runs ran past 600 seconds | identified for the estimate; unknown for the 600 seconds | `plan_changes_at_scale` (`s0fc8c91c7d`) |
| B | BookStack, `GET /books/{slug}` (all five scenarios) | the largest statement carries 108,009 values, above the limit of 65,535 | identified | `app_limit_risk_placeholders` (`79e38f2`) |

For B, "identified" has a limit that is worth stating. This route was not run at 180,000 rows, because it was taken out of the mix from the prediction alone. The reason for taking it out (108,009 values against 65,535) is a number in the prediction's output. That the failure really occurs as SQLSTATE 1390 was measured at 150,000 and 300,000 rows. No record shows it at 180,000 rows. The rule "when in doubt, the lower class" would give guessed; identified is kept because the failure was measured at two other sizes, and the class is open to being lowered.

## 8. Proposals to strengthen the marks

Three things hold for everything in this section.

1. **These are proposals only.** The tool, the predictions, the rules and the earlier records were not changed, and nothing was measured for them. The proposals were written before the measurement at 70,000 rows; its result is in section 5.4.
2. **Every "how many it would catch" number was counted on the same records after the outliers were seen.** It is a fit, not a test. A proposal can only be checked on records not yet seen (for example Bagisto).
3. **The choices below were the maintainer's.** They have since been made in D243 (section 8.8 gives each ruling). Nothing was changed or measured for this file by those rulings.

B has no outlier, so there is no proposal for B. All proposals are about the 10 BookStack routes of A at 240,000 rows. The numbers are counted from the estimate files of A at `f52f692` (`poc/lab/predictions/extrapolation-4-{bookstack,fixtures}-estimate.json`) and from the raw records of the measurement (`extrapolation-data-4/measured-240000/bookstack/attempts.jsonl` and `extrapolation-data-4/measured-240000/variance/bookstack/a4var240k/`).

### 8.1 The four groups

The 10 routes fall into four groups by what the records show. The row numbers are those of section 5.1. The cause classes are those after the measurement at 70,000 rows (section 5.4).

| Group | Routes (row numbers of section 5.1) | Mark at `f52f692` | Cause class | Direction of the measurement |
| --- | --- | --- | --- | --- |
| **Heavy on one lap only** | 1 `GET /shelves/{slug}/references`; 3 `PUT /shelves/{slug}`; 4 `GET /books/{slug}/references`; 8 `PUT …/revisions/{revId}/restore` | yes, `plan_changes_at_scale` | guessed | above the estimate, on the third lap only |
| **Estimate too high** | 2 `GET /books/{bookSlug}/page/{pageSlug}`; 5 `GET /shelves/{slug}`; 6 `DELETE /books/{id}` | yes (rows 2 and 5 `plan_unstable`; row 6 `plan_changes_at_scale`) | guessed 2, unknown 1 | below the estimate |
| **Always heavier than the estimate** | 9 `POST /settings/maintenance/regenerate-references` | yes, `plan_changes_at_scale` | guessed | above the estimate (also in the second and third runs of A) |
| **No mark** | 7 `POST /books/{bookSlug}/draft/{pageId}`; 10 `DELETE /settings/maintenance/cleanup-images` | **no** | guessed 1, identified 1 | above the estimate |

### 8.2 The question that came first: does the list of marked routes count as a mark for condition 3?

Condition 3 says the tool can say "this may not hold" at prediction time. The list of marked routes of A is in the prediction's output. Whether being on that list is enough was a matter of reading the spec, and it was **the maintainer's to decide**. **The maintainer ruled that it counts (D243, judgement 1), by route, as in the test of the marks (spec 7.4.1).** Both readings are kept here as the record of what was put to the maintainer; the second was not taken.

**Read as "it counts":**

- The spec's wording is only that the tool "can say" so at prediction time. The list is in the output, and the meaning of each mark (the plan changes at scale; the plan is unstable) is given with it.
- The test of the marks (spec 7.4.1) puts the pass line at "no miss" and says the number of false alarms is always reported. The same practice can be applied here: the false alarms are counted and shown, and are not a reason to set the marks aside.

**Read as "it does not count":**

- The list marks 19 of the 34 BookStack routes, and 11 of those 19 were inside the goal (11 false alarms). A mark on more than half of the routes says little to a user about which route is at risk.
- The marks of rows 2 and 5 are on the statement `sf034e6d815` only. `extrapolation-test-3-causes.md` section 5 wrote, for the 300,000-row run, that on the same route the marked statement is not the one that gave the error. So a mark can be right while its reason is not.
- A mark says "may not hold" and does not say in which direction or by how much. The three routes of the "estimate too high" group were measured below the estimate, and the upper side (`rows_examined_upper`) points only upward.

**What changes in the counts** (A at 240,000 rows, BookStack, rule B). The first row is the one the maintainer took (D243):

| Reading | Outliers with a mark | Outliers with no mark | False alarms (marked, inside the goal) |
| --- | ---: | ---: | ---: |
| It counts (taken, D243) | 8 | **2** | 11 of the 19 marked routes |
| It does not count (not taken) | 0 | **10** | not applicable |

**Beside the count taken (D243):** 11 of the 19 marked routes were inside the goal, and rows 2 and 5 may be marked on a statement that is not the one that gave the error (second bullet of "does not count" above). Raising the precision of the marks is a task for after version 1.0.

For reference, not counted: the 19 marked routes carry an upper side as well as the point. Of the 8 marked outliers, 5 (the four of "heavy on one lap only" and the one of "always heavier than the estimate") have a measured value inside [point − 10%, upper side]. The 3 of "estimate too high" are below the point − 10%. The upper side is wide, from 1.02 to 24.3 times the point (for example, `GET /books/{slug}/references` has point 84,217 and upper side 2,043,810), so it is not narrow enough to use for capacity planning.

### 8.3 Heavy on one lap only (rows 1, 3, 4, 8)

**(a) Mark proposal.**

- All four already have a `plan_changes_at_scale` mark (so they had a mark, the list counting as a mark under D243). **Beside this count (D243):** 11 of the 19 marked BookStack routes were inside the goal (false alarms), and for rows 2 and 5 the marked statement may not be the one that gave the error; the precision of the marks is a task for after version 1.0.
- A stronger form: add one line to the mark of such a route saying that the same request can read very different numbers of rows from one run to another. What to look at and what threshold to use cannot be set while the cause is not known.
- How many it would catch: **cannot be counted**, because the records have no quantity, available at prediction time, that separates the lap that turns heavy. For all four, the other three runs are within 0.01% to 0.24% of the estimate, and only the heavy run is outside.

**(b) Measurement proposal.**

- What: run the 21 routes for three laps in the order of the last part of the third lap of the 240,000-row repeat (trials 57 to 60 of that record), with the per-statement row counts (the tap) switched on, to see whether a heavy run appears and, if it does, which statement grows. The record at 240,000 rows had the tap off (`tap=off` in its `run.txt`) and no ANALYZE (`analyze=none`). ANALYZE is not run here, to keep the conditions the same.
- On which database: a development size of 70,000 rows is recommended. It is not a size used for a test, so it is not used up. 240,000 rows is a size already seen, and its database no longer exists (D236).
- Time: the 240,000-row repeat (one database, three laps, 21 routes) took 4 hours 21 minutes from building the database, a value read from the record (`extrapolation-data-4/measured-240000/variance/bookstack/a4var240k/run.txt`: `started=2026-11-20T06:47:35+0900`, `finished=2026-11-20T11:08:47+0900`). The time for 70,000 rows with the tap on is not in any record: the smaller size makes it shorter and the tap makes it longer. **The expectation that it would not exceed 4 hours 21 minutes is conditional and is not a measured value.**
- Limit: if the heavy run does not appear at 70,000 rows, the cause stays unknown (it may be something that happens only at 240,000 rows).

### 8.4 Estimate too high (rows 2, 5, 6)

**(a) Mark proposal.**

- All three have a mark. The present mark gives a range upward only, so the stronger form is to say also that the point may be too high, and to add the range below to the output of a marked route.
- How many it would catch: **cannot be counted**, because there is no way yet to make the range below and the prediction file has no value for it.

**(b) Measurement proposal.**

- Row 2, `GET /books/{bookSlug}/page/{pageSlug}`: check the difference named in section 10.3 of `extrapolation-test-3-causes.md`. The database used for the capture and the EXPLAIN had ANALYZE run; the database of the repeat did not. On a database of 70,000 rows, take the tap both without and with ANALYZE, and see the rows of statement `s7ff3e12408` (2.6·N or 0.2·N). It is one request at a time, so it should be short (conditional, not a measured value).
- Row 5, `GET /shelves/{slug}`: the route reads 236 rows at every size. One tap run at any size shows which statement carries the 47.75 rows of difference, and the real counts of the two statements whose count is 1.43876 (`sa8fffb0b26` and `sde990ad951`). It is the cheapest measurement here.
- Row 6, `DELETE /books/{id}`: the rows vary from lap to lap, 1,248,500 to 1,860,501. At 70,000 rows, take the tap for each lap and compare the rows scanned and the rows read of statement `sad4efc33ee` (this checks the premise of the point rule P1). It goes into the same run as the heavy-on-one-lap measurement.

### 8.5 Always heavier than the estimate (row 9)

**(a) Mark proposal.**

- The route already has a mark. It was above the estimate in the second, third and fourth runs of A, but "it missed in the same direction in an earlier test" is not information a new application has, so it is not made into a proposal.
- How many it would catch: **cannot be counted**.

**(b) Measurement proposal.**

- Take the tap at 70,000 rows and see which of the two statements that make up 99.9% of the estimate (`s55d8c74b35` and `sc6102fdbc3`) is short. The route gives the same value (370,678,285) in all four runs, so one run should be enough (conditional, not a measured value).

### 8.6 No mark (rows 7 and 10): the core of the hole in condition 3

**Row 10, `DELETE /settings/maintenance/cleanup-images` (identified: reading in chunks by offset grows with the square of the row count; section 5.3).**

**Correction to the first version of this section.** The first version counted the statements with an `OFFSET` by searching the `pattern` field of the estimate file. That field is cut at 160 characters (`poc/runtime/src/Estimate/RowEstimator.php:310`, `substr(…, 0, 160)`; 945 of the 1,282 statement rows of the estimate of A at 240,000 rows are exactly 160 characters long), so an `OFFSET` after the cut was not seen. Two statements of `POST /settings/maintenance/regenerate-references` (`s55d8c74b35`, `sc6102fdbc3`; `limit 100 offset 6000` and `limit 100 offset 12000` in the capture) were missed, and one of `GET /books/{slug}` (share 0.0001). The first version wrote that one BookStack route would be marked (cleanup: 1 hit, 0 misses, 0 false alarms). Counted again on the full statement text of the capture (`id` of the estimate looked up in the capture), the share of regenerate-references is 0.9992 (0.5112 + 0.4880), so **two routes would be marked: cleanup (0.8885) and regenerate-references (0.9992; already on the list of marked routes of A, so it is not a new mark): 2 hits, 0 false alarms**. The number of routes newly marked is still one (cleanup). The verification app is unchanged. The numbers of the earlier records were not changed; this section was corrected.

**(a) Mark proposal.**

- What to look at, first form (share of the offset statements in the rows read): the share of the estimate's rows read that comes from statements with an `OFFSET`. Threshold 0.5, **a provisional value used only to count, not recommended**. Counted on the full statement text, for A at 240,000 rows: BookStack, cleanup (0.8885) and regenerate-references (0.9992), 2 hits and 0 false alarms; `GET /shelves/{slug}` is 0.0832, below the threshold; verification app, `GET /ok/chunk` and `GET /ok/lazy` (share 1.000, inside the goal at 1.83%), 2 false alarms. The same correction applies to the same count at 300,000 rows (regenerate-references at 0.9994 was missed). **These numbers were counted on records after the outliers were seen: they are a fit, not a test.**
- Weakness of the first form: the same offset-chunk reading is estimated well for the two verification routes, so "it uses an offset" alone does not separate the routes that miss. The reason is now known (section 5.3): the estimate's formula cannot follow a bend at the chunk size, and a bend inside the captured sizes is visible in the capture alone. So a threshold can be set from records already seen.
- **Proposal, second form (a threshold set from the capture and the goal only). The adoption is waiting for the maintainer. It is not adopted, not implemented, and no rule has been changed.** For each statement with an `OFFSET` (taken from the capture's full text), leave out the largest captured size, fit the statement's count and rows per read on the other sizes with the tool's fit, and extrapolate to the left-out size. The relative error of the product against the captured value is the hold-out error ε. For a route, S is the sum, over its offset statements, of the statement's share of the route's estimated rows times |ε|. A route is marked when S is at least the goal of the spec for rows (0.10). Only statements run twice or more in the route count. The threshold is set by this rule from the captured sizes and the spec's goal, and does not use a measurement at any larger size or whether an outlier occurred.
- What it gives when counted on records already seen, **counted after the outliers were seen on the same records, so it is a fit and not a test**: A at 240,000 rows, BookStack: cleanup S = 0.1476 (ε = −0.1661) and regenerate-references S = 0.3327 are marked (2 marked, 2 hits, 0 false alarms; the second was already on the list of marked routes of A, so one route is newly marked); verification app: `GET /ok/chunk` and `GET /ok/lazy` S = 0.0352, not marked. At 300,000 rows the same two are marked (S = 0.1507 and 0.3338) and the verification app is not. At 200,000 rows only three sizes were captured, so the hold-out cannot be made and no mark can be given. **The limitation "only statements run twice or more" was added after the first counting, having seen its result** (`GET /shelves/{slug}` has a single `limit 20 offset 0` statement whose S would be 0.3867 without it, a hit by chance because that route's error comes from another statement; the limit changes no count of newly marked routes, because that route is already on A's list). **The cleanup margin is small (0.1476 against a line of 0.10), and the judgement rests on three statements (cleanup, the two of regenerate-references) and the verification chunk: weak evidence.** It can only be tested on records not yet seen, for example Bagisto.
- Limits stated first: the hold-out extrapolates 3 times (10,000 to 30,000 rows) while the real prediction extrapolates about 8 times, so ε is probably smaller than the real error and a missing mark is not a safety statement; with fewer than four captured sizes the mark cannot be given; a bend above the largest captured size (more than 30,000 rows) is not seen.
- Which mark to strengthen: whether it is a new mark, or an addition to the meaning of `plan_changes_at_scale`, is to be decided at implementation. Adopting a mark rule that is added to the tool must be committed before the Bagisto estimate is made.
- **Beside the counts of marks above (D243):** of the 19 BookStack routes that carry a mark in A at 240,000 rows, 11 were inside the goal (11 false alarms), and for rows 2 and 5 the marked statement may not be the one that gave the error. Adding the second form would give 20 marked routes, 11 of them inside the goal. The count of outliers with no mark would go from 2 to 1 (row 7 only); **this count is not changed in section 4**, because the second form is not adopted. Raising the precision of the marks is a task for after version 1.0.

**(b) Measurement proposal.**

- Reading the application's code for the chunk size is done (1,000; section 5.3). What is left for this route is to take the tap at 70,000 rows and at the captured sizes (100 to 30,000) and line up the count and the rows per run of statement `sdf9dc81d9e` by size, to see the square directly. Most of what that would show is already seen in the reproduction of the fit (section 5.3), so it is not needed to establish the cause.

**Row 7, `POST /books/{bookSlug}/draft/{pageId}` (guessed after the measurement at 70,000 rows, section 5.4; it was unknown when the text below was written).**

**(a) Mark proposal.**

- There is no proposal with a basis, and the measurement at 70,000 rows (section 5.4) does not give one. The measurement is 120,893 rows above the estimate. The estimate is almost entirely two statements on `max(priority)` (`sc26304c1f7` and `s68a46df765`, neither marked), and the record does not say which of the two is short.
- Counting "has a statement with an offset" would mark this route too, but the share of the estimate that comes from offset statements is 0.000, so there is no ground connecting it to the miss. That would be a hit by chance, and it is not counted as a proposal.
- How many it would catch: **cannot be counted**, because the cause is not known.

**(b) Measurement proposal.**

- The route read 3.2·N + 544 rows at 300,000 rows as well. Take the tap at 70,000 rows and look at the rows of the two statements to see which is outside the estimate (conditional, not a measured value).

### 8.7 The measurements together

The maintainer ruled that they are made (D243, judgement 3). **They were made at 70,000 rows; the result is in section 5.4.** The text below is the plan as it was put to the maintainer. They can be put in one run:

- Build one database of 70,000 rows (no ANALYZE) and run the heavy-on-one-lap order of three laps with the tap on. Then take the six routes of the other three groups one at a time with the tap, and last run ANALYZE and take row 2 again.
- 70,000 rows is not a size used for a test, so no unseen size is used up. It uses a database other than the one for building Bagisto and can go on alongside it.
- On time, the three laps are expected not to exceed the 4 hours 21 minutes of the 240,000-row repeat, and the other three groups are one request at a time. **Both are conditional expectations and not measured values.**
- Only the causes that the tap can settle to a statement (the groups "estimate too high", "always heavier than the estimate" and "no mark") can be identified, as expected. "Heavy on one lap only" stays unknown if the heavy run does not appear again. **Result:** the heavy run appeared again, so that group did not stay unobserved, and its cause is still not established; the statements were named for rows 5a, 7 and 9 but no row was raised to identified (section 5.4).

### 8.8 Choices put to the maintainer, and the rulings (D243)

The recommendations below were recommendations. The maintainer has ruled on all three in D243, and each ruling is written under its choice. The rulings change no rule, estimate or prediction, and nothing was measured for this file.

**Choice 1. Does the list of marked routes of A count as a mark for condition 3?** (Section 8.2.)

- (1) It counts. Outliers with no mark: 2. The number of false alarms is shown every time.
- (2) It does not count. Outliers with no mark: 10. Condition 3 then needs a new mark.
- (3) It counts, except where the reason of the mark differs from the reason of the miss (the `views` statement of rows 2 and 5). Outliers with no counted mark: 4 (the 2 unmarked and rows 2 and 5).
- **Recommended: (1)**, because it fits the spec's wording ("can say") and the practice of the test of the marks (no miss; the number of false alarms is reported), and because the count does not move afterwards. (3) cannot be fully counted now, because the records at 240,000 rows do not say which statement gave the error.
- **Ruled: (1) (D243, judgement 1).** Outliers with no mark: 2. Beside every count: 11 of the 19 marked routes were inside the goal, and rows 2 and 5 may be marked on a statement that is not the one that gave the error. Raising the precision of the marks is a task for after version 1.0.

**Choice 2. How to strengthen the marks for the two routes with no mark (rows 7 and 10).**

- (1) First read the application's code for the chunk size of cleanup (no measurement), and work the offset-share mark into a form whose threshold can be set from records already seen. The draft route waits for the result of the measurement.
- (2) Mark any route that has a statement with an offset. This catches both routes in A, but the draft route would be a hit by chance, and it adds 1 new false alarm in BookStack (`PUT /books/{slug}`) and 3 in the verification app (chunk, lazy and paginate).
- (3) Do not strengthen now; decide together after Bagisto.
- **Recommended: (1)**, because (2) is a hit made after the outliers were seen, and it counts a hit that has no ground.
- **Ruled: (1) (D243, judgement 2).** The chunk size is read from the application's code, not measured. The offset-share mark is worked into a form whose threshold is set from records already seen only. Option (2) was not taken. The mark for the draft route waits for the result of the measurement.

**Choice 3. Whether and when to make the measurements of section 8.7.**

- (1) Make them, alongside the preparation for Bagisto, at 70,000 rows (a size that is not used up).
- (2) Make them after Bagisto, together with the outliers of Bagisto (the order of the spec's course, step 3 then step 4).
- (3) Do not make them (the 10 routes stay with unknown causes and version 1.0 is not reached).
- **Recommended: (1)**, because it does not use a size reserved for tests, it can go alongside Bagisto on a different database, and the six routes of the three groups need one request each. The maintainer should be told first that "heavy on one lap only" may not be identified.
- **Ruled: (1) (D243, judgement 3).** The measurements are made at 70,000 rows, alongside the preparation for Bagisto, on a different database. The maintainer accepted beforehand that the four routes of "heavy on one lap only" may stay with an unknown cause.

### 8.9 The offset mark of D245, counted apart

D245 adopted the second form of section 8.6(a) and the tool now carries it. Section 8.6 records the proposal as it stood when it was written (not adopted) and is not changed. This section only adds. No number, class, earlier record or verdict above is rewritten.

**What was adopted and where it is.** The rule was adopted in D245 (`8fb24fe`, with the formula and the status of the draft route corrected in `f63232b`) and implemented before any Bagisto estimate or prediction was made, in `5212935` (`feat: mark routes whose offset chunk reads fit poorly within the captured sizes`). The estimate output no longer cuts the statement text at 160 characters (`2701c81`); this changes no estimated number. The rule and its limits are described in `dec492b` (`docs/simulator-spec.md` section 4.3.1 and `poc/runtime/README.md`). The rule: for each statement whose text contains `offset`, leave out the largest captured size, fit its count and its rows per read on the other sizes with the tool's own fit, extrapolate to the left-out size, and take the relative error ε = (extrapolated − actual)/actual of the product. For a route, S = Σ s_i × |ε_i|, where s_i is the statement's share of the route's estimated rows. A route is marked when S ≥ 0.10 (the spec's goal for rows). Only statements run twice or more count, and a statement captured at fewer than four sizes is not given an ε (it is not counted). The mark is a separate key of the estimate output (`offset_chunk_fit`) and of `bin/marked-routes --estimate`; it is not mixed into `marked`, and `bin/simulate` does not read it.

**What it gives on the record already seen (BookStack, A at 240,000 rows).** Two routes are marked: `DELETE /settings/maintenance/cleanup-images` (S = 0.1476; share 0.8885, ε = −0.1661) and `POST /settings/maintenance/regenerate-references` (S = 0.3327). Both are outside the goal (13.29% and 14.53%), so there are 2 hits and 0 false alarms. The verification app's `GET /ok/chunk` and `GET /ok/lazy` have S = 0.0352 and are not marked. At 300,000 rows the same two routes are marked (S = 0.1507 and 0.3338) and the verification app is not. At 200,000 rows only three sizes were captured, so no mark can be given. The implementation reproduced these numbers from the stored captures with no difference from the figures of section 8.6(a). Source: the output kept with the implementation, compared against the stored estimate of the fourth run (`f52f692`) with only the statement text and the new key differing.

**Said with the four points, in one sentence:** the count "2 marked, 2 hits, 0 false alarms" is a fit and not a test: **the shape of the mark was chosen after the outliers were known, the limit "only statements run twice or more" was also added after its result was seen, the count "2 marked, 2 hits, 0 false alarms" is a fit to records already seen and not a test, and the effect of the mark can be tested only on Bagisto**.

**Limits (D245).**

- The hold-out extrapolates about 3 times (10,000 to 30,000 rows) while the real prediction extrapolates about 8 times (30,000 to 240,000 rows), so ε comes out smaller than the real error and S tends to withhold a mark. **No mark does not mean safe.**
- With fewer than four captured sizes the rule cannot be applied. Bagisto is captured at 100, 1,000, 10,000 and 30,000 rows, which is enough.
- The hold-out shows only a poor fit inside the captured sizes. A bend above the largest captured size is not seen.
- A route whose estimate is not complete (a statement type with no number) is given no S and no mark (`route_incomplete`); for example `POST /books/{bookSlug}/copy` has an offset statement but its estimate is not complete.
- The margin of cleanup-images is small (0.1476 against a line of 0.10), and the judgement rests on three statements (cleanup-images, the two of regenerate-references) and the verification chunk. This is weak evidence.
- `POST /books/{bookSlug}/draft/{pageId}` is not caught: the share of its offset statements is 0.000. Whether to add a mark for it is not decided.

**The count of condition 3, set beside the offset mark.** The count in section 4 and in item 4 of the brief stays as D243 counted it (judgement 1): the list of marked routes of A is the mark, and the routes with no mark are 2 (`POST /books/{bookSlug}/draft/{pageId}` and `DELETE /settings/maintenance/cleanup-images`). Beside it, as a separate line: **if the offset mark is added, the route newly marked is `cleanup-images` (1 route) and the routes with no mark are 1 (`draft`).** This line carries the four points above, in the same sentence as the count: **the shape of the mark was chosen after the outliers were known, the limit "only statements run twice or more" was also added after its result was seen, the count "2 marked, 2 hits, 0 false alarms" is a fit to records already seen and not a test, and the effect of the mark can be tested only on Bagisto**. The two lines carry the doubt of D243 in the same place: of the 19 BookStack routes that carry a mark in A at 240,000 rows, **11 were inside the goal** (false alarms), and with the offset mark the marked routes are 20, 11 of them inside the goal; and for rows 2 and 5 the marked statement may not be the one that gave the error (`extrapolation-test-3-causes.md` section 5), so a mark can be right while its reason is not. Raising the precision of the marks is a task for after version 1.0. Condition 3 is not shown to be met by either count: Bagisto is not measured, and the offset mark has not been tried on a record it was not fitted to.

**Causes, rechecked.** The cause of row 10 (`DELETE /settings/maintenance/cleanup-images`) stays **identified** (section 5.3), and it stands on code that can be followed to a line: the chunk size of 1,000 is at `app/Uploads/ImageService.php:183-184` in the BookStack copy used for the tests (commit `045c6a9`), the offset growth is in the framework's `chunk` (`vendor/laravel/framework/src/Illuminate/Database/Concerns/BuildsQueries.php:40-80`), and the reproduction of the estimate from the four captured sizes agrees with the estimate file in every digit shown. The cause of row 7 (`draft`) stays **guessed** (section 5.3). The counts of causes (identified 1, guessed 8, unknown 1) are not changed.

## 9. What cannot be said

- **Bagisto is not measured.** Its outliers are not here. This inventory does not meet condition 3, and the number 10 is the count of the two latest tests only; it will change when Bagisto is added.
- **The causes were sorted from the records and from the measurement at 70,000 rows (section 5.4), which is a measurement at another size.** Of the 11 rows, 1 is identified (row 10, by reading the application's code, agreeing with the record's numbers and seen directly at 70,000 rows), and the 9 guessed rows are readings that fit numbers, not findings at 240,000 rows (the other row, 5a, is unknown). The heavy values of rows 1, 3, 4 and 8 themselves cannot be investigated, because the database of that run no longer exists (D236); the heavy run was seen again at 70,000 rows and its cause is not established.
- **The classes were sorted by taking the lower one when in doubt.** The count "identified 1" rests on this. A different reader could place a row one class higher. The move of 6 rows from unknown to guessed rests on the definition of section 3.3 (the records now hold an investigation of each, and a stated reading): a reader who wants a mechanism shown before a row counts as guessed would put some of them back to unknown, and the count of causes not identified (9) would not change.
- **Which marks count is ruled (D243), and the count has two doubts that always go with it.** The 8 marked routes carry the names of the list of marked routes of A, and the maintainer ruled that a name on that list is a mark, so routes without a mark are **2**. But 11 of the 19 marked routes were inside the goal (false alarms), and for rows 2 and 5 the marked statement may not be the one that gave the error, so the mark may be right for another reason. The precision of the marks is a task for after version 1.0, and this file does not claim that condition 3 is met.
- **The marks were judged only from the output committed with each prediction.** A mark that could be given today, or a reason found after the result, is not counted.
- **The counts are for rule B, the goal, and the two tests named in section 2.** Rule A, older tests, and any other size are outside them. Under rule A, four of the routes (rows 1, 3, 4 and 8) are inside the goal; they are outliers here because the spec's rule B gives the verdict, and the cause of the heavy laps cannot be examined (D236; `extrapolation-test-4.md` section 4.6).
- **Nothing here says how accurate the tool is on a real deployment.** Both tests were made on generated data in a test setup, and the figures of this file inherit that.
