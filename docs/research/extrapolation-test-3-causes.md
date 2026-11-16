# Extrapolation test (A), third run: where do the BookStack misses at 300,000 rows come from?

Status: written from the records only. Nothing was measured, nothing was built, and no estimate, calibration, formula, mark rule, pass line or earlier record was changed. The proposals of section 7 are not carried out and wait for the project owner's judgment.

This file follows `docs/research/extrapolation-test-3.md` (the third run of test A at 300,000 rows, where BookStack failed: rows examined inside the lowest standard for 24 of 33 routes, 27 needed) and `docs/research/lab-backtest-3.md` (the third round of test B and of the mark, where the limit rps of scenario s-3 was predicted at 0.751 times the measured one). It asks two questions:

1. **For the nine BookStack routes whose rows examined were outside ±20%, which statement and which rule of the estimate made the error?** (sections 2 to 6)
2. **For the limit rps of s-3 of test B, how much of the gap belongs to the estimate of a route, to a request the application could not finish, and to the rest?** (section 8, the same split as in `docs/research/lab-backtest-causes.md`)

As in the files it follows, everything here is about a check made in a lab, on synthetic data, for two applications. No value measured in production appears, and nothing here is a statement about the precision on a real deployment.

## 0. How the causes are looked for, and what the records can and cannot tell

**What was read.** The committed BookStack estimate (`poc/lab/predictions/extrapolation-3-bookstack-estimate.json`); the capture of the statements at 100, 1,000, 10,000 and 30,000 rows and the `EXPLAIN` output at 300,000 rows that the estimate was made from (kept outside the repository, SHA-256 in `docs/research/extrapolation-data-3/sha256.txt`: merged `capture.json` `8e2830c3…`, merged `explain.json` `83218b2c…`); the measured records of the run (`docs/research/extrapolation-data-3/measured/`, including the table statistics read before every trial of the repeat); and the records of the second run (200,000 rows) for the same routes.

**The estimate was re-computed, not trusted.** The per-statement fit (`y = a + b·N^k`, k from 0 to 3 in steps of 0.01, non-negative least squares, 12 significant digits) and the tangent rule were re-implemented from the capture file. They reproduce the committed `per_exec_h1` of **all 569 statement types** of the BookStack estimate (relative difference below 1e-6). So the way each statement's figure was produced can be stated exactly (a, b, k, the points it was fitted on), and not guessed.

**What the records do not hold: the rows examined per statement at 300,000 rows.** The measured record of a request gives the number of statements, the total rows examined (the handler count, D96), and a count of rows by table that is not the same quantity (it is not used for the split below). The capture, which does hold rows per statement, was taken only up to 30,000 rows. So at 300,000 rows the split of a route's measured total between its statements is an inference. Three kinds of statement about a cause are used below, and each says which it is:

- **Established by arithmetic**: the measured total, minus the small statements whose estimate is a few hundred rows and cannot be wrong by thousands, leaves a figure that equals, to the row, what a plan of the statement reads (for example `N + 0.15·N + 232`), and the same sum holds at 200,000 rows in the second run.
- **Consistent with, not shown**: a reading that fits every record that exists, where no record excludes another.
- **Not established**: the record does not say. What it does say is listed, and what would settle it is named in section 7.

## 1. The nine routes

Rule B, as in `extrapolation-test-3.md` (marked routes against the largest of four cold values). Estimates and measured values are copied from sections 3 and 4 of that file; "single" is the cold value of the first run, "largest" the largest of the four.

| # | Route | Estimate | Single | Largest | Error | Cause (section) |
| ---: | --- | ---: | ---: | ---: | ---: | --- |
| 1 | `GET /shelves/{slug}` | 289.0 | 375,226 | 375,226 | 99.92% | not established; reading consistent with a state effect (5) |
| 2 | `PUT /books/{bookSlug}/chapter/{chapterSlug}` | 75,763.8 | 345,232 | 345,240 | 78.05% | full scan of `references` (3), and the `references` select (4) |
| 3 | `PUT /books/{bookSlug}/page/{pageSlug}/revisions/{revId}/restore` | 75,787.2 | 345,246 | 345,262 | 78.05% | as 2 |
| 4 | `PUT /books/{bookSlug}/page/{pageSlug}` | 75,790.2 | 345,257 | 345,265 | 78.05% | as 2 |
| 5 | `GET /books/{slug}/references` | 40,810.7 | 105,210 | 105,210 | 61.21% | the `references` select (4) |
| 6 | `GET /shelves/{slug}/references` | 40,813.7 | 105,210 | 105,210 | 61.21% | as 5 |
| 7 | `GET /books/{bookSlug}/chapter/{chapterSlug}/references` | 40,817.2 | 105,215 | 105,215 | 61.21% | as 5 |
| 8 | `GET /books/{bookSlug}/page/{pageSlug}/references` | 49,560.5 | 120,219 | 120,219 | 58.77% | as 5 |
| 9 | `GET /books/{bookSlug}/page/{pageSlug}` | 2,011,437.3 | 1,260,237 | 1,635,227 | 23.01% | not established; a state effect and a lower level (6) |

Seven of the nine (rows 2 to 8) are accounted for to within a few hundred rows by two statements on the `references` table (sections 3 and 4). Two (rows 1 and 9) are not accounted for by a statement; sections 5 and 6 give what the records fix about them.

All nine are marked routes (`plan_changes_at_scale` or `plan_unstable` on at least one statement), as the third-run file says. For seven of them the mark is on the statement that carries the error. The mark did its job (it named the statement); the estimate's point did not use what the mark knew.

## 2. How a statement's figure is made, in the part that matters here

For a statement type, the capture gives rows examined per execution at four sizes. The point of H1 is the fit `a + b·N^k` through those four values, put at N = 300,000. Where the fitted exponent k is above 1, the point goes on along the tangent at the largest size instead (D174). For a statement marked `plan_changes_at_scale`, the plan at 300,000 rows is read from `EXPLAIN`; that gives only the upper side (H2: the `EXPLAIN` rows at N, multiplied by the ratio of measured to `EXPLAIN` rows at the small side). The upper side is not part of the pass line.

Three facts about this are used below.

1. The fit has four points and three free numbers. One point that was read under a different plan than the others can pull the exponent below 1, and then no tangent rule applies.
2. The point of a marked statement does not use the plan that `EXPLAIN` shows at 300,000 rows. The plan can differ from the plan at every captured size.
3. The capture is on a database analyzed after the build (D179). The second run's capture was not analyzed. That one change moves some points (section 4).

## 3. Cause A: a full scan that starts above the captured sizes (the three `PUT` routes)

**The statement:** `delete from references where from_type = ? and from_id in (?)` (`s1a3fa8aa3c`).

| Where | Plan | Rows |
| --- | --- | ---: |
| Capture, 100 / 1,000 / 10,000 / 30,000 rows | (reads on the index range) | 21 / 201 / 2,001 / 6,001 per run |
| Fit | `a` = 1, `b` = 0.2, k = 1.0 (a line, `0.2·N + 1`) | **60,001** at 300,000 |
| `EXPLAIN`, 10,000 rows (small side) | `range`, key `references_from_id_index` | 2,000 |
| `EXPLAIN`, 300,000 rows (all three states) | **`ALL`** (full scan of `references`), no key | **299,088** (297,949 in the `high` state) |
| Measured (inferred, below) | full scan | **300,000** |

**Established by arithmetic.** The estimate of the first `PUT` route is 75,763.8 = 60,001.0 (this delete) + 15,526.8 (the select of section 4) + 236.0 (all other statements). The measured total is 345,232 to 345,240. Taking off the 236 of the small statements leaves 344,996, which is 300,000 + 44,996. The select of section 4 reads 0.15·N on the capture's line (4,501 at 30,000 rows), which is 45,000 at 300,000 rows, so the delete reads 300,000: the whole table. The same sum holds in the second run: measured 230,232 = 200,000 + 30,000 + 232 (the estimate there was 70,230). Both statements fit both sizes at once, to the row. For the split to be different, the select would have to be off its line and the delete off the size of the table at both sizes in exactly compensating amounts, and no record points to that. The other two `PUT` routes give the same sums within 10 rows.

**Share of the miss.** Each of the three routes is short by about 269,475 rows (estimate 75,764 against 345,240). This delete accounts for 239,999 of it (89.1%: 300,000 against 60,001). The select of section 4 accounts for 29,473 (10.9%: 45,000 against 15,527).

**Which rule.** The plan of the delete changes between the captured sizes and 300,000 rows: from a range read of the rows that match (0.2·N) to a full scan of the table (N). `EXPLAIN` saw it (the statement is marked `plan_changes_at_scale`, and the plan at 300,000 rows is `ALL` for the 299,088 rows of the statistics, 0.3% below the 300,000 that were read). The point did not use it, because the point of H1 is the fit of the capture's values, and the capture never ran the full-scan plan. The upper side did read the plan, but multiplied the `EXPLAIN` rows by the small-side ratio (6,001 measured against 2,001 estimated, 3.0), which gave 896,971, three times the real figure. The ratio belongs to a range read, where the number of matching rows is what counts; it has no meaning for a full scan, whose rows are the rows of the table.

**A check inside the records: the same statement on another route.** The three `PUT /books/...` routes and `PUT /shelves/{slug}` run the same delete (the same text, and the same `EXPLAIN` plan at 300,000 rows: `ALL`, 299,088 rows). On `PUT /shelves/{slug}` the capture of that route read 21 / 1,003 / 10,003 / 30,003 rows (a read of about the whole table, 1.0·N, already at the captured sizes), so the fit gives 300,034.5 and the route's estimate is 315,797 against 345,240 measured: 8.53%, inside the lowest standard. The measured totals of the four routes are the same (345,232 to 345,265), which fits the same 300,000 for the delete on all four. Only the capture differs. This shows that the error is not in how the delete's rows grow with the data; it is in the plan, which the capture read as a range on three routes and as a full read on the fourth.

**The same cause elsewhere.** The same rule gap (a marked statement whose plan at 300,000 rows is a single-table full scan with more rows than the point) is on one more statement among the 33 routes of the denominator: `delete from deletions where deletable_type = ? and deletable_id = ?` (`sad4efc33ee`), whose point is 184,123 per run against a scan of 299,400 rows. It is on six routes (`DELETE /books/{bookSlug}/chapter/{chapterSlug}`, `DELETE /books/{bookSlug}/draft/{pageId}`, `DELETE /books/{bookSlug}/page/{pageSlug}`, `DELETE /books/{id}`, `DELETE /shelves/{slug}`, all at 16.83% under rule B, inside the lowest standard and outside the goal; and `POST /settings/recycle-bin/empty`, 8.8%, inside the lowest standard and the goal). These are not among the nine misses. Their cold values also differ between laps (1,560,450 to 2,550,461 for one route), a second effect that is not studied here.

## 4. Cause B: a point read under a different plan pulls the fit below the line (the four `references` routes, and the select inside the `PUT` routes)

**The statement:** `select * from references where to_type = ? and to_id = ? and ((references.from_type = ? and exists (select * from entities ...` (`sd7799eeccf`), which loads the references to an entity.

| Route | Capture: rows per run at 100 / 1,000 / 10,000 / 30,000 | Fit | Point at 300,000 | Linear from 30,000 |
| --- | --- | --- | ---: | ---: |
| `GET /books/{slug}/references` | 36 / 351 / **6,998** / 10,501 | a = 0, b = 30.66, k = 0.57 | 40,601.7 | 105,010 |
| `GET /shelves/{slug}/references` | 36 / 351 / **7,000** / 10,501 | the same | 40,604.7 | 105,010 |
| `GET .../chapter/{chapterSlug}/references` | 36 / 351 / **6,999** / 10,501 | the same | 40,603.2 | 105,010 |
| `GET .../page/{pageSlug}/references` | 41 / 401 / **7,499** / 12,001 | a = 0, b = 25.52, k = 0.6 | 49,343.5 | 120,010 |
| (inside the `PUT` routes) | 16 / 151 / **3,499** / 4,501 | a = 0, b = 24.99, k = 0.51 | 15,526.8 | 45,010 |

**What the capture shows.** At 100, 1,000 and 30,000 rows the statement reads a constant share of N: 0.35·N on three of the `GET` routes, 0.4·N on the page route, 0.15·N inside the `PUT` routes (the figures carry a constant 1), which is a straight line. At 10,000 rows it reads about twice that (6,998 against the 3,500 of the line; 7,499 against 4,000; 3,499 against 1,500 for the `PUT` routes). A fit through the four points cannot follow a line with one point at twice its height; it takes exponent 0.57 (0.6, 0.51). The exponent is below 1, so the tangent rule does not apply, and the point at 300,000 rows is 2.6 times too low.

**Established by arithmetic.** The four cold values of each `references` route are identical (105,210, 105,210, 105,215, 120,219 in all four tries). The other statements of a route are estimated at 209 to 217 rows in all; the measured total minus them leaves 105,001 rows for the statement on three routes and 120,002 on the fourth. The line from the capture's last point gives 105,010 and 120,010, against 105,001 and 120,002 read (9 and 8 rows away). At 200,000 rows (second run) the same four routes measured 70,210, 70,210, 70,215 and 80,218: the ratio of the 300,000-row value to it is 1.499 on each, as a straight line gives.

**A change that exposes it: the analyzed capture.** The second run's capture, made on databases that were not analyzed, read the same statement at 10,000 rows as 3,501 (`GET /books/{slug}/references`), 4,001 (the page route) and 1,501 (the `PUT` select), exactly on the line; its estimates for the four routes at 200,000 rows (70,210, 70,210, 70,215 and 80,218) were equal to the measured values. With the capture on analyzed databases (D179, made so that the estimate matches the lab's way of measuring) the 10,000-row point is at twice the line. So the change of D179 moved one capture point and made these four routes, and the `PUT` select, worse. It is not claimed that D179 is wrong; it is the place where the capture and the fit disagree.

**Which plan.** `EXPLAIN` at the small side (10,000 rows) shows for this statement an `index_merge` of `references_to_id_index` and `references_to_type_index` (512 rows per scan), and at 300,000 rows a `ref` on `references_to_id_index`, then one lookup in each joined table per row; the rows of the whole plan (`scan_rows_sum`) are 124,388 (`GET /books/{slug}/references`), 112,964 (`GET /shelves/{slug}/references`), 103,450 (the chapter route and the page route). Against what was read (105,001 on the first three routes, 120,002 on the page route) these are +18.5%, +7.6%, −1.5% and −13.8%. This agrees with a change of plan at about 10,000 rows, and with the plan at 30,000 rows being the one that holds at 300,000. The plan at 30,000 rows was not explained, so that part is consistent with the records, not shown. The statement is marked `plan_changes_at_scale`, so the mark saw a plan change, and the point again did not use it.

**Share of the miss.** For the four `references` routes this statement is the whole miss: the statement is short by 64,399 rows on the first three routes (105,001 against 40,602) and by 70,659 on the page route (120,002 against 49,344), which is what the routes are short by (105,210 − 40,810.7 = 64,399; 120,219 − 49,560.5 = 70,659). For the three `PUT` routes it is 29,473 of about 269,475 rows (10.9%).

## 5. `GET /shelves/{slug}` (row 1, error 99.92%): the statement is not established

**What the records fix.**

- The estimate is 289.0 rows, from statements that are flat from 10 to 30,000 rows (the capture's total reads 341 / 236 / 236 / 238 rows at 100 / 1,000 / 10,000 / 30,000).
- The measured value is 375,226 in all four tries; at 200,000 rows (second run) it was 250,226 in all four. The excess over the estimate is 374,937 rows at 300,000 and 250,000 at 200,000, so **1.25·N** (the second run's figure is exactly 1.25 × 200,000 + 226). A reading of 1.25·N at 30,000 rows would be 37,500, and the capture shows 238: whatever reads the 1.25·N is not seen by the capture.
- `EXPLAIN` at 300,000 rows marks only the `views` select (`plan_unstable`; one row in all three states of the statistics), and one statement has a heavy plan that is **not** marked: `select * from entities left join entity_container_data ...` for the books of the shelf (`s933461e5b8`): a `range` on `entities_type_index` with 107,516 rows at 300,000 rows (and 122,384 on `GET /books/{slug}`), and 2,002 rows at the small side, against a measured 3 rows at every captured size from 100 to 30,000. It is not marked because the plan signature (`range` on `entities_type_index`) is the same at the small side and at 300,000 rows. The rows it would read (107,516 estimated) do not equal the 374,937 either, so it is a candidate and not the answer.

**A state effect.** The shelf route is not the only one with the 1.25·N. The same quantity, 374,990 rows, is the difference between the cold values of `GET /books/{bookSlug}/chapter/{chapterSlug}` (1,980,240 against 2,355,230) and of `GET /books/{bookSlug}/page/{pageSlug}` (1,260,237 against 1,635,227). The four cold values of these routes are, in order (cold-once run, then laps 1, 2 and 3 of the repeat, which runs the routes of one database in a fixed order):

| Route | Cold-once | Lap 1 | Lap 2 | Lap 3 |
| --- | ---: | ---: | ---: | ---: |
| `GET .../chapter/{chapterSlug}` | 1,980,240 | 1,980,240 | 2,355,230 | 2,355,230 |
| `GET .../page/{pageSlug}` | 1,260,237 | 1,260,237 | 1,635,227 | 1,635,227 |
| `GET /shelves/{slug}` | 375,226 | 375,226 | 375,226 | 375,226 |

In the repeat the order inside one database is: chapter, ZIP export, page, `GET /books/{slug}`, `POST /settings/maintenance/regenerate-references`, shelf, then the next lap. The table statistics read before each trial (`stats_before.tsv`) give `innodb_table_stats.n_rows` of `references` as 299,088 before every trial up to and including the regeneration, and **0 from the shelf trial of lap 1 on**. The quantity appears exactly in the trials that come after the regeneration (shelf in every lap, chapter and page from lap 2) and not in those before it. The cold-once run had the same order (chapter, ZIP, page, then the regeneration, then the shelf), and its values are the "low" ones for chapter and page and the "high" one for the shelf.

**What this does and does not say.** The evidence is four comparisons inside one database (chapter and page, lap 1 against laps 2 and 3), all in one direction, with the cold-once values of the same two routes on the same side; the shelf route has no value from before the regeneration, so for it the association is by the order alone. No trial tests the link directly. It says that the shelf route's 374,937 extra rows are present in the measurement after a route that rewrites `references` and leaves its statistics at 0 rows, and were absent in the capture, which was taken on a database where `references` held its full number of rows with fresh statistics (the estimate assumes statistics in order, D180). So the miss of the shelf route may be, in part or whole, a difference between the state of the measured database and the state the estimate describes, and not an error in a rule of the estimate. The identity of the statement is not established, and neither is the link between the statistics and the 1.25·N. Section 7 names what would settle it.

## 6. `GET /books/{bookSlug}/page/{pageSlug}` (row 9, error 23.01%): not established

**What the records fix.**

- The estimate is 2,011,437.3 = 1,223,588.8 (the pages of the book, `s976b7c7275`) + 787,587.0 (`s7ff3e12408`, the entities of the book) + 261.5. Both statements read 4.0·N and 2.6·N on the capture (120,000 and 77,999 rows per run at 30,000 rows) in a line that holds from 10,000 to 30,000 rows, so the estimate is close to the capture carried linearly (6.6·N = 1,980,000; the point is 1.6% above it). The estimate is not a fit error.
- The measured values are 1,260,237 (cold-once and lap 1) and 1,635,227 (laps 2 and 3): **4.2·N + 237**, and 374,990 more, the 1.25·N of section 5. At 200,000 rows the same route's lowest value was 840,237, the same 4.2·N + 237. So the low state of the route is 4.2·N at two sizes, and **not 6.6·N**.
- The chapter route runs the same two large statements with the same capture (a total of 198,242 rows at 30,000 rows against 198,247 for the page route), and its low value is 1,980,240 = 6.6·N. The page route is 720,003 rows (2.4·N + 3) below it at 300,000 rows. Which statement reads 2.4·N less on the page route is not recorded. The capture figure of `s7ff3e12408` is 2.6·N, and a drop of that statement to 0.2·N would give the 2.4·N; this is only an arithmetic fit, no record shows it.
- `EXPLAIN` at 300,000 rows shows one statement of the page route with a large scan that the capture never read: `select * from entities left join entity_page_data ... where (draft = ? or ...)` (`s2abffb28d9`), `ALL` on `entities` with 303,921 rows in the whole plan (297,842 to 307,748 in the three states), against 5 / 5 / 5 / 11 / 11 rows on the capture at 10 / 100 / 1,000 / 10,000 / 30,000 rows and 10,262 at the small side of the `EXPLAIN`. It is not marked. A full scan of 300,000 rows would add to the measured value, not take from it, so it does not explain the shortfall, and it is a second place where the estimate's `EXPLAIN` and the capture disagree.

**What this means for the 23.01%.** Under rule B the route is compared with the largest of its cold values, 1,635,227, and the estimate is 23.01% above it. Under rule A, with the single value (1,260,237), it is 59.61% above. In both, the estimate carries the capture's 6.6·N line into a measurement that read 4.2·N to 5.45·N. The cause is that the measured request read less than the capture's line at 200,000 and 300,000 rows; the statement that read less is not established.

## 7. Proposals

These are proposals only. Nothing here is carried out, and no rule of the estimate was changed to write them. Three rules hold for all of them.

- **Seen sizes cannot confirm a repair made from them.** The sizes 100,000, 200,000 and 300,000 (and the nine routes) are used up as a check of anything repaired from these results. A repair is checked at a size not yet seen by A, and the choice of that size is the project owner's. The sizes 150,000 and 120,000 are kept for test B and the mark.
- **What is proposed to fix is fixed, and committed, before the estimates of the new size are made**, in the order of the earlier runs.
- **The what-if figures below are a screen on the records already seen, not a result.** They answer "does this change break any route that is right now", which the records can answer, and nothing more. The rules were looked for after the results were seen.

### 7.1 P1. A marked statement whose plan at the target size is a single-table full scan: use the scan's rows as the point

**Where.** `RowEstimator::type()`, for a statement marked `plan_changes_at_scale` whose `EXPLAIN` of the target-size database (default state) is one table with access type `ALL`: the point is the rows of that scan (`scan_rows_sum`), and the fit's value stays as the lower side. The ratio multiplication of H2 is not used for this case, because it has no meaning for a full scan (section 3).

**Evidence in the records.** The delete of section 3: `EXPLAIN` 299,088 against 300,000 read (0.3% below, inferred as in section 3), and 60,001 by the fit. Among the 33 routes of the denominator the class holds one other statement, `sad4efc33ee` (section 3).

**What-if on the seen data (BookStack, rule B, 33 routes).** The three `PUT` routes move from 78.0% to 8.8%; five `DELETE` routes from 16.8% to 5.8%; `POST /settings/recycle-bin/empty` from 8.8% to 16.0% (still inside ±20%); no route leaves the lowest standard. Rows examined inside ±20%: 24 of 33 → **27 of 33** (27 needed). Inside ±10%: 15 → 22.

**Check after the change.** `PUT` and `DELETE`-type routes at a size not seen, with the statement-level count of section 7.4 for the delete.

### 7.2 P2. A marked statement: never below the line carried from the largest captured size

**Where.** The same function: for a statement marked `plan_changes_at_scale` or `plan_unstable`, the point is the larger of the fit's value and the largest captured value carried linearly (value at the largest size × target size ÷ largest size); the fit stays as the lower side. The reason is section 4: the marked statement's cost per row can change with the plan at one captured size, and a fit through four points reads that as a falling exponent, which no tangent rule can undo.

**Evidence in the records.** The `references` select on the four routes and the three `PUT` routes (section 4): the line from 30,000 rows gives 105,010 and 120,010 against 105,001 and 120,002 read (0.0% to 0.2%) and 45,010 against 44,996 (0.03%). In the second run, whose capture had no mid-point anomaly, the fit and the line were nearly the same (70,001 by the fit and 70,020 by the line for the first `references` route at 200,000 rows, against 70,210 measured), so P2 would change nothing there.

**Risk.** For a marked statement whose true growth is below linear (constant or logarithmic), the line over-estimates. In the what-if below it is not worse for any route; one reason is that the rule applies only to marked statements. The proposal must be checked on a size where a marked statement grows less than linearly, if the project owner knows one.

**What-if on the seen data.** P2 alone: the four `references` routes move from 58.8% to 61.2% error to 0.0%; the `PUT` routes do not move (the delete dominates them; P1 does). Inside ±20%: 24 → 28 of 33; inside ±10%: 15 → 24. **P1 and P2 together: 31 of 33 inside ±20% and 26 inside ±10%**; no route that is right now becomes wrong (the one that moves, `POST /settings/recycle-bin/empty`, 8.8% to 16.0%, stays inside). The two routes still outside are the two that are not established: `GET /shelves/{slug}` (99.9%) and `GET /books/{bookSlug}/page/{pageSlug}` (23.0%).

**A broader rule does not pass the screen.** Using the `EXPLAIN` rows (`scan_rows_sum` at the target size) as the point for every marked statement gives 30 of 33, but moves `GET /books/{bookSlug}/export/zip` from 1.3% to 48.8% (the statement `s0fc8c91c7d`: 1,272,468 by the fit against 18,996 by `EXPLAIN`). The `EXPLAIN` rows of a join with a heavy fan-out are not a reliable figure; a single-table scan is. This is why P1 is limited to the single-table full scan and P2 does not use `EXPLAIN` rows.

### 7.3 P3. The mid-point from another plan: a way that does not need the largest-size line

An alternative to P2, if the project owner prefers a rule that reads the plan and not the shape of the series: **fit only on the capture sizes whose plan signature is the target's.** It needs `EXPLAIN` at every captured size, which is not run now (it is run at the small side and at the target only). The checks are the same as for P2 (the four `references` routes and the `PUT` select must come out at 105,010, 120,010 and 45,010 within a few percent, from the sizes where the plan is the target's). It is slower and heavier than P2 and it has not been tried; it is listed because it states the cause (a point from another plan) and not a symptom (a falling exponent).

### 7.4 P4. Record the rows per statement at the failing size, once, to settle sections 5 and 6

For the shelf route and the page route the statement is not known. One cold request of each route, at 300,000 rows, with the per-statement tap that the capture uses (the repeat's trial records say `tap: absent`), in both database states (before and after `POST /settings/maintenance/regenerate-references`), gives the rows per statement, and so which statement reads the 1.25·N and which reads 2.4·N less. It needs the lab and is outside this file (records only); it falls under the lab's safety conditions and needs the project owner's go-ahead.

### 7.5 P5. The measured database: the state left by an earlier route

The cold values of the shelf, chapter and page routes depend on whether `POST /settings/maintenance/regenerate-references` ran before them in the same database (section 5): a difference of 374,990 rows, which is 19% of the chapter route's low value and 30% of the page route's. Rule B takes the largest of four, so the state after the regeneration decides the route's value for two of the routes. This is a question about how test A is measured, not about a rule of the estimate, and it belongs to the project owner. Two options, neither tried: measure a route that follows a heavy writer in its own database (or before the writer), or keep the order and record the table statistics of each trial (already done) and let the comparison say which state each cold value belongs to. The estimate assumes statistics in order (D180); the measuring tool does not run `ANALYZE` after it builds its database (`docs/research/extrapolation-data-3/measured/README.md`), while the capture's databases are analyzed.

### 7.6 What none of this changes

The pass line, the tolerances, the denominator, the estimates and the list of marked routes of the third run stand. The third run's verdict (BookStack: fail) stands. The count of condition 1a (BookStack, lowest standard for rows examined: 3 short) stands. The what-if figures above are not a recount.

## 8. Test B, scenario s-3: where the 0.751 comes from

**Source.** The split is made from the records only, with the same formulas, in the same order, as `docs/research/lab-backtest-causes.md` section 2.3. The order was fixed before the records were read and was not changed after. The records: the prediction (`poc/lab/predictions/ladder-prediction-3.json`, scenario s-3), the estimate (`docs/research/lab-data/estimate-300k/estimate-300000.json`), the nine steps (`docs/research/lab-data/ladder-300k/s-3-300000/`), the one cold request per route of the mark (`docs/research/lab-data/mark-300k/mark-300000/http.jsonl`, measured with the default CPU limit of 30 s and memory limit of 512M) and its application log. The coefficients are those of the third prediction: `c_q` = 2.84407e-4, `c_row` = 9.37963e-7. The figures below are those printed by the allocation script that was written for this split (it reads the records only); none is worked out by hand.

### 8.1 The limit rps

| | Value |
| --- | ---: |
| Predicted limit rps (point) | 0.2952 |
| Measured limit rps, interpolated at 80% on the decided rps | 0.3932 |
| Ratio | **0.751** |
| Measured limit rps, interpolated on the achieved rps | 0.3778 (ratio 0.781) |

Both ratios are outside ±20%. The verdict of test B is not changed.

The predicted load per request of the mix, `L = Σ share × (database time of one request of the route)`, is 5.4206 CPU seconds; the measured limit 0.3932 implies 4.0692 (`1.6 ÷ limit`). The difference `ΔL` is 1.3514.

### 8.2 The split of `ΔL`

Two scales are used in this section and they are not the same quantity. The shares of 8.2 and 8.5 are shares of `ΔL`, the difference in the predicted load per request of the mix, read at the limit rps. The shares of 8.3 are shares of the sum of `Δ`, the difference in utilization points over the eight steps item 2 uses. For this reason the book page is 6.5% to 13.4% of `ΔL` and 9.1% of the utilization points, and the two are not to be compared as if they were one figure.

| Part | What it is | Share of `ΔL` |
| --- | --- | ---: |
| (a) | the estimate of the routes that completed in the mark (six of the eight) | −0.1% |
| (b) | the book page: in test B every request of it answered HTTP 500 (SQLSTATE 1390, the limit on placeholders), while the estimate counted it as complete | 6.5% to 13.4% |
| (c1) | the conversion of counts into database time, for the seven routes measured | 0.5% |
| rest | **the ZIP export route, whose complete request at 300,000 rows was not measured**, and the way the window counts its requests | about 93% |

Part (b) uses the cut request of the mark in place of the book page's real request in the steps, because the real database time of that request is not in the records. The range is the limit rps with the book page's work set to the cut request's conversion (1.862 s: 6.5%) and to 0 (13.4%); with the measured CPU of the cut request (1.613 s) it is 7.4%. Part (a): the six routes that completed have estimated rows 0.95 to 1.14 times the measured, and a conversion that is 0.00215 against 0.00396 (per request), so they are not a source of the gap. This differs from the 100,000-row split, where the ZIP route was measured without the limit three times; at 300,000 rows the mark's ZIP request was stopped by the memory limit (HTTP 500, "Allowed memory size of 536870912 bytes exhausted": 70,035 statements and 2,140,218 rows read, against 359,992.7 and 2,526,093.6 estimated), and its figures are those of a cut request. So the ZIP route's part cannot be split, from these records, between the estimate (a), the conversion (c1) and the window and the load (c2).

### 8.3 The split by step

The steps are in points of database utilization. `U0` is the predicted point, `M` the measured utilization, `Δ = U0 − M`. (c3), the difference between the decided and the achieved rps, is inside (c2) and not added. "ZIP" is the requests of the ZIP route recorded in the window over the planned.

| Step | Decided rps | `U0` | `M` | `Δ` | (a) | (b) | (c1) | (c2) | (c3) | ZIP | Window (s) |
| ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | --- | ---: |
| 1 | 0.025 | 6.78 | 8.26 | −1.49 | −0.002 | +0.11 | +0.008 | −1.60 | 0.00 | 2/2 | 1,600 |
| 2 | 0.035 | 9.49 | 10.08 | −0.59 | −0.003 | +0.15 | +0.011 | −0.75 | −0.01 | 2/2 | 1,142 |
| 3 | 0.05 | 13.55 | 13.53 | +0.02 | −0.005 | +0.22 | +0.016 | −0.21 | 0.00 | 2/2 | 800 |
| 4 | 0.07 | 18.97 | 16.25 | +2.72 | −0.006 | +0.31 | +0.022 | +2.40 | +0.46 | 1/2 | 571 |
| 5 | 0.1 | 27.10 | 20.36 | +6.74 | −0.009 | +0.44 | +0.032 | +6.28 | +0.68 | 1/2 | 400 |
| 6 | 0.15 | 40.65 | 33.34 | +7.32 | −0.014 | +0.66 | +0.047 | +6.62 | +0.48 | 3/4 | 533 |
| 7 | 0.2 | 54.21 | 52.65 | +1.56 | −0.018 | +0.88 | +0.063 | +0.63 | +1.36 | 2/4 | 400 |
| 8 | 0.3 | 81.31 | 69.98 | +11.32 | −0.027 | +1.32 | +0.095 | +9.94 | +2.03 | 3/6 | 400 |
| 9 | 0.45 | 121.96 | 86.10 | +35.87 | −0.041 | +1.97 | +0.142 | +33.79 | +5.48 | 2/8 | 356 |
| 2 to 9 | | | | +64.95 | −0.123 | +5.94 | +0.428 | +58.71 | | | |

Step 1 is under 10% utilization and is not used by item 2 of test B; the 2-to-9 row is the eight steps item 2 uses. Of the +64.95 points, (a) is −0.2%, (b) 9.1%, (c1) 0.7% and (c2) 90.4%. The database's idle CPU (0.638 points a step, from the mark's idle record of 12,752 µs per second over 2 cores) is 5.10 points of the eight steps, and is inside (c2).

### 8.4 Clues in the records for the ZIP part

- **The size agrees with three times the 100,000-row measurement.** The ZIP request at 100,000 rows is counted by the measuring parts as 120,004 statements and 835,196 rows (`docs/research/lab-data/diagnosis/per-request/README.md`, line 53; that cold request was stopped by PHP's limit with HTTP 500, and the server's own count of the same request without the limit is 120,036 statements and 835,207 rows, `diagnosis/zip-limit/compare.json`). Three times the first figures is 360,012 and 2,505,588. The 300,000-row estimate is 359,992.7 statements and 2,526,093.6 rows: 0.99995 and 1.008 times. The book page's 100,000-row figure (1,260,454 rows) times three is 3,781,362, and the estimate (3,840,178.6) is 1.016 times it. This holds on the assumption that statements and rows grow in proportion to the count; it is not a measured complete request at 300,000 rows. The 300,000-row estimate does not show the shape seen at 100,000 rows (an estimate 2.02 times the measured rows for the book page and 1.78 times for the ZIP rows).
- **The steps where the ZIP requests were all recorded agree with the estimate; the others fall.** In steps 1 to 3 (ZIP 2 of 2 recorded) the database time of the step, less the six small routes' mark CPU, the book page's 1.613 s and the idle rate, divided by the ZIP requests, gives a ZIP request of 120.2, 106.1 and 101.3 s against the estimate's 104.75 s (ratios 1.15, 1.01, 0.97). In steps 4 to 9 (ZIP recorded fewer than planned) it gives 87.4, 77.1, 85.4, 102.2, 90.6 and 74.1 s (0.83, 0.74, 0.82, 0.98, 0.86, 0.71). With 2 to 8 ZIP requests in a window, one request more or less moves a value much, and the back-calculation assumes that all of the window's database time belongs to the ZIP requests and the mark's values. What can be said is only that the long windows with all ZIP requests recorded agree with the estimate within 3% to 15%, and the short windows, where a ZIP request is left running at the end, fall to 0.71 to 0.98.
- **The longest request is a large share of the window.** The longest request in a step lasts 217 to 239 s in steps 1 to 7, 259.2 s in step 8 and 308.5 s in step 9 (`summary.json`, `duration_ms.max`), against windows of 356 to 400 s in steps 5 and 7 to 9: 55%, 60%, 65% and 87% of the window in steps 5, 7, 8 and 9 (about half to nearly nine-tenths). The database work of a request still running at the end of the window falls outside it (`lab-backtest-3.md` sections 4.3 and 5.4). The positive `Δ` of steps 4 to 9, expressed in the database time of one estimated ZIP request (104.75 s), is 0.30, 0.51, 0.74, 0.12, 0.86 and 2.44 requests, which does not exceed the 1, 1, 1, 2, 3 and 6 ZIP requests that were not recorded in those steps. This says only that the window can account for a gap of that size; it does not say it did.
- **The calibration's width does not explain it.** The seven leave-one-out fits give a limit rps of 0.2947 to 0.2960 for s-3 (`lab-backtest-3.md` section 3). To reach 0.3932 the prediction would have to rise by 33.2%.

### 8.5 The width of part (b)

The real database time of the book page in test B is not in the records, so the limit rps is given for a range of it (`limit = 1.6 ÷ L`).

| The book page's request | `L` | Limit rps | Ratio to the measured 0.3932 |
| --- | ---: | ---: | ---: |
| as estimated (3.616 s) | 5.4206 | 0.2952 | 0.751 |
| the six complete routes replaced by their measured counts | 5.4224 | 0.2951 | 0.750 |
| and the book page by the mark's cut request (1.862 s) | 5.3347 | 0.2999 | 0.763 |
| and the conversion replaced by measured database CPU (1.613 s for the book page) | 5.3284 | 0.3003 | 0.764 |
| book page's work 0 | 5.2416 | 0.3052 | 0.776 |

Even with no work for the book page the limit is 0.776 of the measured. The book page alone is 6.5% to 13.4% of `ΔL`. The number is not the same as the 0.3627 of `lab-backtest-3.md` section 4.4 (there the measured utilization was lowered by the book page's share; here the predicted load is).

### 8.6 A conditional reading of the rest (assumption, not a record)

The remaining 93% of `ΔL` (1.2592, with the book page at the cut request) would be met by a ZIP request of **79.6 s** (0.760 of the estimated 104.75 s), taking no idle off and the book page at 1.613 s. If the ZIP request at 100,000 rows without the limit, whose database CPU (33.43 s) was 0.958 of the conversion of 120,004 statements and 835,196 rows, held the same ratio at 300,000 rows, with the counts three times those of 100,000 rows, a ZIP request would be 100.3 s and the predicted limit 0.3134 (0.797 of the measured). The remaining 20% (0.3134 to 0.3932) would then be the window and the load. This is arithmetic under an assumption that the records cannot check; it is not read as a cause.

### 8.7 What can be said, and what cannot

Can be said:

1. The estimate of the routes that completed is not a source of the gap (−0.1%).
2. The book page answered 500 in every request of every step of test B; the estimate counted it as complete, so the predicted limit is low. This is 6.5% to 13.4% of the gap. It is not an error of the estimate; it is a request that the application could not finish (the limit on placeholders), and it is the case the mark of the placeholder limit is meant to name.
3. About 93% of the gap belongs to the ZIP export. Its estimated statements and rows agree with three times the 100,000-row measurement, so no rule of the estimate points to an error, under the assumption of linear growth.
4. `Δ` is about zero in the steps where the ZIP requests were all recorded (1 to 3) and positive in the steps where ZIP requests were left unfinished at the end of the window (4 to 9); the size of `Δ` is within what the unfinished requests could account for.
5. The calibration's width does not explain the gap.

Cannot be said:

1. How the ZIP part divides among the estimate, the conversion and the window: no complete ZIP request was measured at 300,000 rows.
2. How much database work of the unrecorded ZIP requests fell inside a window: the step records have no start and end time per request.
3. What the database did for the book page before it answered 500 in test B.
4. Whether the +8.26% measured in step 1 (6.78% predicted) is the idle CPU of 0.638 points: not tested.

### 8.8 Proposals for test B (not carried out)

- **The measuring tool.** The window of a step should not lose the database work of a request still running at its end. The project owner ruled on 2026-11-17 that the tool count a step by the requests it sent and wait for a running request as long as the request time limit; the check afterwards is whether the `Δ` of steps 4 to 9 returns to the level of steps 1 to 3 (within ±1 point). If it does, the main part of this gap was in the measuring tool; if not, the ZIP conversion or the estimate.
- **A complete ZIP request at 300,000 rows**, cold and alone, with the limits lifted, measured once (statements, rows, database CPU), so that parts (a) and (c1) of the ZIP route can be told apart. It takes about 200 seconds; it needs the lab's safety conditions and a decided order.
- **The mark of the placeholder limit** (already ruled), with the note on the limit rps that a route stopped by that limit is counted as complete.
- The rules of the estimate for statements and rows are not shown to need a change by this split. Whether to change them waits for the complete ZIP request.

Proposals of test B are checked at a size not yet used (150,000 rows, then 120,000 if the first does not run to the end), and not at 100,000, 200,000 or 300,000.

## 9. What the records give for the project owner, in short

- Seven of the nine misses (the three `PUT` routes and the four `references` routes) are statements on one table, `references`, and two rules of the estimate: a full scan that starts above the captured sizes is not read from the `EXPLAIN` that saw it (3), and a captured point read under another plan turns the fit sub-linear (4). Both are shown by arithmetic on the records and by the second run. A what-if on the seen data, which is not a result, gives 31 of 33 inside ±20% with both proposals (27 with the first alone), and moves no right route outside.
- The two others (`GET /shelves/{slug}`, `GET /books/{bookSlug}/page/{pageSlug}`) are not explained by a statement. The records show that the measured request reads a quantity the capture never read (1.25·N), and that it appears after a route that rewrites `references` and leaves its statistics at 0, which is a question about the state of the measured database (7.5) as much as about a rule.
- For test B s-3: 6.5% to 13.4% of the gap is the book page that the application could not finish; the remaining 93% is the ZIP route, which cannot be split without a complete request.
- Nothing in the estimate was changed, and nothing here is a recount of the third run.
