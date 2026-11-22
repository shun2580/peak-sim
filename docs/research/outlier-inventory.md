# Inventory of the remaining outliers: a look ahead at condition 3

Status: **This is a record made from existing records. Nothing was measured for it. Bagisto has not been measured, and its outliers are not in this inventory. This inventory does not meet condition 3 of section 0 of `docs/simulator-spec.md`; it counts how many outliers are left, and why, before Bagisto is measured (D242).**

The part that lists the outliers and counts them (sections 1 to 7 and 9) is written. The part that proposes how to strengthen the marks (section 8) is not written yet.

Condition 3 asks that every outlier left after conditions 1 and 2 is met has a cause that is identified one route at a time, and that the tool can say "this may not hold" at prediction time. An outlier whose cause is not known keeps version 1.0 back. Conditions 1 and 2 are not both met yet (condition 2 is the third application, Bagisto, and it is not measured), so any count of condition 3 stays provisional (D241, D242). This file looks at the two latest tests only and gives the count as it stands in the records.

**In brief:**

1. **Outliers: 10.** All 10 are BookStack routes in the fourth run of A at 240,000 rows (11 rows, because one route is outside the goal on two quantities). The verification app has none. B at 180,000 rows has none.
2. **Outside the lowest standard: 5** of the 10. Each of the 5 had a mark at prediction time.
3. **Causes: identified 0, guessed 3, unknown 7.** So **10 outliers have no identified cause**, which is what condition 3 asks for and what is missing.
4. **Marks at prediction time: 8 of the 10 had one, 2 did not** (`POST /books/{bookSlug}/draft/{pageId}` and `DELETE /settings/maintenance/cleanup-images`). The 8 are on the list of marked routes of A (`plan_changes_at_scale` 6, `plan_unstable` 2). Whether that list counts as "can say this may not hold" is a question for the maintainer, and the count of routes that had **no** mark is **2** if it counts and **10** if it does not.
5. **Counted apart: 6 routes** (A 5, B 1) that could not be measured or whose estimate is not complete. They are not outliers and are not in the counts above.
6. **Bagisto is not measured.** Its outliers are to be added when it is.

## 1. What was done

The records of the two latest tests were read again, and every route or step outside the goal was listed with its numbers, whether the prediction carried a mark when it was fixed, and what the records say about the cause. This is a look ahead at condition 3 of section 0 of the spec. **It is a record only: no measurement was made, no database was built, no container was started, and no estimate, rule, tool, earlier record or lab data was changed.** The measured numbers are copied from the earlier records and are not rounded again.

Two readers rebuilt the table of A without seeing each other's work, and a third read both and checked the raw records. They agreed on every number, every mark and 9 of the 11 rows' causes. The two rows on which they differed were decided under the rule "when in doubt, take the lower class" (section 3.3); both are marked in section 5.3. B was rebuilt from the raw records by one reader and checked by another, and the two agree.

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
2. Predicted (rule B), measured, ratio (measured divided by predicted), relative error (|predicted − measured| divided by measured), and band (outside the goal only, or outside the lowest standard).
3. **The mark at prediction time**: present or absent; if present, its name and the file it was read from at the commit of the prediction. The mark was judged **only from the output committed with the prediction** (`f52f692` for A, `79e38f2` for B). A reason found afterwards does not turn "absent" into "present".
4. **The cause**, in one of three classes (section 3.3).

### 3.3 The three classes of cause

- **identified:** the cause is backed by a measurement or by numbers in a record, and the record's path (and section or D number) is given.
- **guessed:** a one-line guess, with the record the guess was made from.
- **unknown:** the records have no investigation of the cause.

**When in doubt, the lower class is taken** (identified or guessed: guessed; guessed or unknown: unknown). A route with two rows takes the lower of the two classes for the route count.

How the marks are read is given in the table of section 5.2. The names are those of the tool's output: `plan_changes_at_scale` and `plan_unstable` (the list of marked routes of A), and `app_limit_risk_memory` and `app_limit_risk_placeholders` (the limit marks of B). Which of these can count as "can say this may not hold" in condition 3 is a matter of reading the spec, and **this file copies the names and the files they come from and does not decide it.**

## 4. The numbers at the head

Bagisto is not measured and is not in this table.

| Item | A 240,000 | B 180,000 | Total |
| --- | ---: | ---: | ---: |
| Outliers (routes; for B, scenarios or steps) | **10** (BookStack 10, verification app 0; 11 rows) | **0** | **10** |
| Of these, outside the lowest standard | 5 (all 5 with a mark) | 0 | 5 |
| Cause identified | 0 | 0 | **0** |
| Cause guessed | 3 | 0 | 3 |
| Cause unknown | 7 | 0 | 7 |
| No mark at prediction time | **2** if the list of marked routes of A counts as a mark; **10** if it does not | 0 | 2 or 10 |
| Counted apart (outside the denominator, not counted above) | 5 routes | 1 route | 6 |

- **Causes not identified: 10** (unknown 7, guessed 3). This is the same number as the "remaining 10 (provisional)" of D241.
- Which way to count the routes with no mark (2 or 10) depends on whether the list of marked routes of A is read as a mark in the sense of condition 3. **The count waits for the maintainer's decision.**
- By rows instead of routes, the classes of the 11 rows are: guessed 4, unknown 7.

## 5. A at 240,000 rows: BookStack

The verification app has no outlier. The numbers that show it: 21 routes in the denominator, the largest error in the number of queries 0.00%, and the largest error in rows examined 1.83% (`GET /ok/chunk` and `GET /ok/lazy`).

### 5.1 The 10 routes (11 rows)

Ratio is measured divided by predicted. The relative error is |predicted − measured| divided by measured. The predicted value is that of rule B.

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

Marked: 8 routes (`plan_changes_at_scale` 6, `plan_unstable` 2). Not marked: 2. All 5 routes outside the lowest standard had a mark.

Two facts from the records that bear on how to read these marks, copied and not decided here:

- For 5 of the 8 marked routes (rows 1, 3, 4, 8 and 9), the measured value lies between the estimate's point and its upper side (`rows_examined_upper`). For rows 2, 5a and 6, the measured value is below the point, and the upper side points upward only.
- The marks of rows 2 and 5 are on statement `sf034e6d815` only. For the 300,000-row run, `extrapolation-test-3-causes.md` section 5 wrote that on the same route the marked statement is not the one that gave the error. The record has no list of the statements that gave the error at 240,000 rows.

### 5.3 The cause of each row, in one line

Classes follow section 3.3. "Record" is a path under `docs/research/` unless it starts with `poc/`.

| # | Quantity | Class | One line, and the record it comes from |
| ---: | --- | --- | --- |
| 1 | rows examined | unknown | The heavy value 324,204 appears in one lap of the repeat only: the repeat laps read 84,210, 84,210 and 324,204, and the first cold run read 84,210. The database was removed afterwards (D236), so it could not be examined. The record has no investigation of why. `extrapolation-test-4.md` section 4.6 |
| 2 | rows examined | guessed | The measured rows follow 4.2·N + 237, the same line as at 200,000 and 300,000 rows. The estimate is higher. An arithmetic reading that fits: if statement `s7ff3e12408` read 0.2·N rows instead of 2.6·N, the sum would match. This is arithmetic only, and D225 records that the cause is not established. `extrapolation-test-3-causes.md` sections 6 and 10.3 |
| 3 | rows examined | unknown | As row 1. The repeat laps read 276,232, 276,232 and 504,226. |
| 4 | rows examined | unknown | As row 1. The repeat laps read 84,210, 84,210 and 168,207. |
| 5a | rows examined | unknown | The estimate is 47.75 rows above the measured 236. The record does not say which statement is high or why. (The two readers differed here: one wrote guessed, one wrote unknown. The restatement "the estimate is higher by 47.75 rows" says no mechanism, so unknown is taken.) |
| 5b | queries | guessed | The counts of two statements are not whole numbers (1.43876 each), and 16 + 2 × 1.43876 = 18.8775, which matches the estimate 18.878. The record is the estimate at `f52f692` (`poc/lab/predictions/extrapolation-4-bookstack-estimate.json`). |
| 6 | rows examined | guessed | The point rule P1 takes the rows of a scan as the rows read. D225 (ruling 1) records an error in the same direction at 200,000 rows. The laps of the repeat differ (1,860,501, 1,248,500, 1,248,500) and the reason for that was not examined. `extrapolation-test-3-causes.md` end of section 3 |
| 7 | rows examined | unknown | The record has no investigation. The route read 3.2·N + 544 rows at 300,000 rows as well. |
| 8 | rows examined | unknown | As row 1. The repeat laps read 276,246, 276,246 and 324,245. |
| 9 | rows examined | unknown | The record has no investigation. In the second, third and fourth runs of A the estimate for this route is below the measurement. |
| 10 | rows examined | guessed | The route reads the table in chunks of 1,000 rows by offset, 241 times, which grows with the square of the row count. Measured 32,280,434 less two counting statements that use `LIKE` (240,000 × 5 and 240,000 × 8 rows, 3,120,000 together) is 29,160,434, against 1,000 × (1 + … + 240) + 240,000 = 29,160,000: the same to 434 rows. The chunk size of 1,000 was not checked in the application's code, so this is not raised to identified. Records: the measured attempts of BookStack at 240,000 rows (`extrapolation-data-4/measured-240000/bookstack/attempts.jsonl`) and the estimate at `f52f692` |

Counts by route (a route with two rows takes the lower class): identified 0; guessed 3 (`GET /books/{bookSlug}/page/{pageSlug}`, `DELETE /books/{id}`, `DELETE /settings/maintenance/cleanup-images`); unknown 7 (`GET /shelves/{slug}/references`, `PUT /shelves/{slug}`, `GET /books/{slug}/references`, `GET /shelves/{slug}`, `POST /books/{bookSlug}/draft/{pageId}`, `PUT …/revisions/{revId}/restore`, `POST /settings/maintenance/regenerate-references`).

The second row on which the two readers differed is row 10. One wrote unknown; the other wrote guessed, because the guess is a mechanism that was checked against the recorded row counts (above). The third reader checked the arithmetic and took guessed.

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

To be added.

## 9. What cannot be said

- **Bagisto is not measured.** Its outliers are not here. This inventory does not meet condition 3, and the number 10 is the count of the two latest tests only; it will change when Bagisto is added.
- **The causes were sorted from the records; none was measured for this file.** Of the 11 rows, none is identified, and the 4 guessed rows are guesses made from numbers in the records, not findings. The heavy values of rows 1, 3, 4 and 8 cannot be investigated because the database of that run no longer exists (D236).
- **The classes were sorted by taking the lower one when in doubt.** The count "identified 0" rests on this. A different reader could place a row one class higher.
- **Which marks count is not decided.** The 8 marked routes carry the names of the list of marked routes of A. Whether a name on that list is enough for "the tool can say this may not hold at prediction time" is a question for the maintainer, and the two counts of routes without a mark (2 or 10) differ by exactly that.
- **The marks were judged only from the output committed with each prediction.** A mark that could be given today, or a reason found after the result, is not counted.
- **The counts are for rule B, the goal, and the two tests named in section 2.** Rule A, older tests, and any other size are outside them. Under rule A, four of the routes (rows 1, 3, 4 and 8) are inside the goal; they are outliers here because the spec's rule B gives the verdict, and the cause of the heavy laps cannot be examined (D236; `extrapolation-test-4.md` section 4.6).
- **Nothing here says how accurate the tool is on a real deployment.** Both tests were made on generated data in a test setup, and the figures of this file inherit that.
