# Estimating rows examined from EXPLAIN: candidate formulas and the rule that picks one

Status: the candidates and the selection rule (sections 1 to 6) were written and committed **before** `explain-at-scale` was run at 100,000 rows; the results (sections 7 and 8) were added after that run. Nothing in sections 1 to 6 was changed after the run (section 7.0 says what was and was not done with the first run of the comparison).

This file belongs to the development check of `docs/simulator-spec.md` (version 0.2, sections 4.3 and 4.4, decisions D106 and D107). It does not decide pass or fail of anything: the pass of version 0.2 is decided at 200,000 rows, with the estimates committed before that measurement (D104, spec section 7.1). The pass line, the tolerances and the denominator are those of the first extrapolation test and are not changed here (`docs/research/extrapolation-test.md`, section 0; D71, D95).

## 0. What was read before writing this, and what was not

Read: the spec (0.2), D103 to D109, the record of the first extrapolation test (`extrapolation-test.md`, including its step-1 fits and predictions in `extrapolation-data/`) and the variance investigation (`variance-investigation.md`).

Not read: any `EXPLAIN` output of a 100,000-row database. None existed when this file was written. The first test and the variance investigation already show 100,000-row measurements, so the later comparison is a check on data that were seen before (section 8, item 5).

## 1. The quantity

For one route, at a target scale N (here N = 100,000), the quantity is the one the first test measured: **rows examined by the cold request**, the sum of the session's `Handler_read_*` counters over the request, less the counters' own idle growth (first test, section 0; D73, D96). It is not rows returned.

The estimate for a route is

```
estimate(route, candidate) = Σ over statement types s of  per_execution(s, candidate) × count(s, N)
```

- `count(s, N)` is the number of executions of the statement type at N: the static analysis's count where it fixes one, otherwise the per-type formula fitted from the three capture scales (spec section 4.2). It is **the same for every candidate**, so the candidates differ only in `per_execution`.
- `per_execution(s, candidate)` is computed from the `EXPLAIN FORMAT=JSON` of the type's representative statement, taken on the database of N rows (spec section 4.3). Section 3 says how.
- A type whose plan changes between statistics states (`plan_unstable`) uses its heaviest state (section 5).

## 2. Reading the JSON (common to all candidates)

`EXPLAIN FORMAT=JSON` of MySQL 8 gives a tree. The fields used, with the meaning this file assumes:

| Field | Meaning assumed |
| --- | --- |
| `access_type` | `ALL`, `index`, `range`, `ref`, `ref_or_null`, `eq_ref`, `const`, `system`, `index_merge`, `fulltext`, `unique_subquery`, `index_subquery` |
| `rows_examined_per_scan` | the optimizer's estimate of the rows (index entries or table rows) read each time this table is scanned, before the non-index part of the condition is applied |
| `filtered` | estimated percentage of those rows that pass the rest of the condition (a string such as `"100.00"`) |
| `rows_produced_per_join` | the estimated rows leaving this table within one execution of its query block: cumulative over the tables to its left (it is the number of rows that enter the next table) |
| `using_join_buffer` | present (`hash join` or `Block Nested Loop`) when the table is joined through a buffer: the table is then read once per execution of the block, not once per outer row |
| `nested_loop`, `table` | the join order: a list of `table` entries, or a single `table` |
| `ordering_operation`, `grouping_operation`, `duplicates_removal`, `buffer_result`, `windowing` | wrappers around a `table` or `nested_loop`; descended into, they add no reads of their own here |
| `union_result` / `query_specifications` | each specification is a query block executed once |
| `attached_subqueries`, `select_list_subqueries`, `having_subqueries`, `order_by_subqueries`, `group_by_subqueries`, `materialized_from_subquery` | subquery blocks; each has `dependent` and `cacheable` flags |
| `using_filesort` (in `ordering_operation`) | the whole input is read and sorted before any limit applies |
| `message` | for example "no matching row in const table", "Impossible WHERE": the block reads nothing |

Walk of one query block, `loops` being the number of times the whole block is executed (1 for the top block):

```
scans for the first table             = loops
scans for table i (i > 1)             = loops × rows_produced_per_join(table i-1)
scans for table i with a join buffer  = loops
reads of table i                      = scans(i) × rows_examined_per_scan(i)
reads of the block                    = Σ reads of its tables  +  reads of its subqueries
```

Subqueries:

- not `dependent` and `cacheable`, or `materialized_from_subquery`: one execution, `loops` = 1 times the outer `loops`;
- `dependent` (or not `cacheable`) in `select_list_subqueries`, `having_subqueries`, `order_by_subqueries`, `group_by_subqueries`: executed once per row the outer block produces, so `loops` = outer `loops` × the outer block's last `rows_produced_per_join`;
- `dependent` (or not `cacheable`) in `attached_subqueries` (inside a table's condition): executed once per row examined at that table, so `loops` = scans(table) × `rows_examined_per_scan`(table). This is deliberately the upper figure, since the condition may be evaluated for fewer rows.

If `rows_produced_per_join` is absent for a table, it is the previous cumulative figure × `rows_examined_per_scan` × `filtered` / 100. A block with a `message` reads nothing. Reads of the temporary tables that grouping, sorting, unions and materialization may use are not counted by any candidate; whether that leaves a residual is looked at in the results (section 7).

A statement that has no table access by its form (transaction control, `SET`, `SAVEPOINT`, a `SELECT` without `FROM`) is not sent to `EXPLAIN`; it counts as 0 under every candidate and is counted under its own name in section 7, so it does not hide among the statements for which `EXPLAIN` was unavailable. An `INSERT … VALUES` reads 0 itself. For `INSERT … SELECT`, the select part is walked as above.

**An unrecognised shape.** If the raw JSON of a statement has a node type or field this file does not name, and the walk above cannot proceed without it, the statement is `explain_unavailable` with the reason `unrecognised_shape` under every candidate. The walk is not extended after the run.

## 3. DELETE and UPDATE

`EXPLAIN FORMAT=JSON` of a single-table `DELETE` or `UPDATE` is expected to give a `table` node flagged `"delete": true` or `"update": true`, with the same `access_type`, `rows_examined_per_scan` and `filtered` fields as a `SELECT` (to be checked against the raw JSON of the run). A multi-table one gives a `nested_loop` like a `SELECT`.

- The target table is walked as the first table of the block (scans = loops); the reads are those of finding the rows. The rows then changed or removed are counted by `Handler_update` and `Handler_delete`, which are not part of rows examined, so they are not added.
- A `DELETE` or `UPDATE` with no `WHERE` has `access_type` `ALL`: the whole table is read.
- Foreign-key cascades are done inside the storage engine and are not counted as extra reads here.
- If the node has no `rows_examined_per_scan` (and no `rows`), the statement is `explain_unavailable` with the reason `no_row_estimate_for_dml`. The statement is not rewritten into a `SELECT`: only the statement as captured is sent, and `EXPLAIN` is the only thing sent (spec section 4.3).
- A write that uses a sort (`using_filesort`) reads its rows twice in the engine (the scan, then a second read by row position). No candidate adds the second read. If it matters, it appears as a residual in section 7.

## 4. The candidates (three)

The three are nested: each adds one correction to the one before, so that the results say which correction paid for itself.

### C1. Nested scan sum

`per_execution` = the walk of section 2: Σ over table nodes of `scans × rows_examined_per_scan`, with the subquery rules.

Why: `rows_examined_per_scan` is the optimizer's own figure for what one scan reads, and `rows_produced_per_join` says how many times the next table is scanned. For a single-table statement this is just `rows_examined_per_scan`. The spec states that rows examined and rows returned differ by orders when the index does not serve the filter (spec section 4.1); `filtered` is therefore used only to count how often the next table is scanned, never to cut the reads of the table it describes.

### C2. C1 plus a per-scan overhead

`per_execution` = C1 + Σ over table nodes of `scans × c(access_type)`, with

| `access_type` | c |
| --- | ---: |
| `ALL`, `index` | 2 |
| `ref`, `ref_or_null`, `range`, `index_merge`, `fulltext`, `unique_subquery`, `index_subquery` | 1 |
| `eq_ref`, `const`, `system` | 0 |

Why: the handler count includes the call that finds the end of a scan. The first test measured it: for a scan of a whole table or index it was 1 to 3 rows per statement (78 rows over 34 statements, mean 2.29; section 5 of `extrapolation-test.md`), and a range or `ref` read has one end-of-range read (D96). The constant 2 is the mean rounded; it is fixed here and not fitted to the 100,000-row data. It is small for a statement that reads many rows and large for the pattern of one small statement per row, which is what a count of 100,000 executions multiplies. A lookup by a unique key reads one row, and `rows_examined_per_scan` is already 1.

### C3. C2 plus a limit cap

`per_execution` = C2, except that for the first (driving) table of the top block the reads are capped when the statement stops early:

```
cap applies when:  the statement text has LIMIT l [OFFSET o]
                   and the plan has no using_filesort, no grouping_operation,
                   no duplicates_removal, and is not a union
cap for that table's reads = ceil( (o + l) / (filtered / 100) ) + c(access_type)
reads of that table        = min( C2 reads of that table, cap )
```

Why: `EXPLAIN` reports the rows of a scan without a limit in some plans (a full scan with no ordering shows the whole table), while execution stops after `l` rows pass the filter. Dividing by `filtered` allows for the rows that are read and then rejected by the part of the condition the index does not serve. The `min` makes the cap harmless where `EXPLAIN` already applied the limit. If the text cannot be parsed for its limit, the cap is not applied; a correction that cannot be applied is never applied silently as zero and never makes a route incomplete.

Blind spot, stated now: the representative statement of a type is the one captured at the largest small scale. For a type whose executions carry different offsets (a loop over pages), the representative's `o` is not typical of the others, so the cap is wrong for such a type in either direction; no candidate can know the other offsets from the captured representative.

## 5. Statements whose plan is unstable (D107)

For each type and each candidate, `per_execution` is computed on every statistics state (three: default, fewer and more sample pages; spec section 4.4). The type's `per_execution` is the **largest** of the three. The per-state values are all kept in the output. The heaviest state may differ between candidates; the rule is applied to each candidate's own figures. A type with `plan_changes_at_scale` uses the plan at the target scale, as `EXPLAIN` there shows it.

## 6. The rule that picks one candidate

Fixed now. It does not use any 100,000-row `EXPLAIN` output.

**Denominator.** The routes in the denominator of rows examined of the first test, at 100,000 rows: 23 routes of the verification app and 23 of BookStack (the entries of rows examined in each target's `compare.json`; `POST /ok/bulk` is outside it for the reason in section 4.1 of `extrapolation-test.md`). The route list and the measured value of each route are those of that record: the single cold value measured at 100,000 in the first test. No route is added or removed. The routes outside that denominator (the twelve not-found routes, the routes with too few scales) are not used for the choice; if a record exists for them they may be shown in a separate table.

**Complete routes.** A route is *complete* when every statement type of the route has an estimate (none is `explain_unavailable`). The choice is made on the complete routes of both targets taken together. The routes that are not complete stay in the denominator for the shares of section 7 (counted as not within tolerance there, and also shown over the complete routes only) and are listed with the reason; they are not dropped. If fewer than 20 complete routes remain, the choice is undecidable: it is reported as such and no candidate is chosen.

**Error.** For a route, `err = |estimate − measured| / measured`. If `measured` is 0, `err` is 0 when `estimate` is below 0.5 and 1 otherwise (the first test's rule for a zero measurement).

**Score.** M = the median of `err` over the complete routes, pooled over both targets.

**Choice.**

1. The candidate with the smallest M is chosen.
2. Candidates whose M differ by less than 0.01 are tied. Among tied candidates the order is: the larger share of complete routes with `err` ≤ 0.20 (the minimum line); then the larger share with `err` ≤ 0.10 (the goal); then the lower number (C1 before C2 before C3, the simpler one).
3. The choice is a choice for the next step, the estimates committed before the 200,000-row test. It is not a result of a test and does not use the pass line.

**Why a median over routes.** It is not moved by the few routes whose measured value is itself a draw from a moving distribution: six BookStack `DELETE` routes read between about 520 thousand and 850 thousand rows at 100,000 depending on the statistics state at that moment (`variance-investigation.md`, section 4), and the first test kept one cold value. With 46 routes at most, eight such routes cannot decide a median. The same table is also shown with the other recorded values of those routes; that table is not used for the choice.

**What would reopen it.** A candidate set is fixed. If the results show a shape or a residual that none of the three can express, a new candidate set is written, dated and committed before it is compared; the three above and their results stay in this file.

## 7. Results of the 100,000-row run

### 7.0 What was run, and how the estimates were made

- `explain-at-scale` at 100,000 rows, tool commit `c55f23a`, started after the candidates commit `6e673a6` (committed 2026-11-09 01:22:41 +09:00; the run started at 01:45:40, the verification app finished at 01:46:46 and BookStack at 01:52:09, both with exit code 0). Each data plan was built twice (100,000 and 10,000 rows) and every statement type was sent as `EXPLAIN FORMAT=JSON` in three statistics states (`STATS_SAMPLE_PAGES` 20, 4, 200).
- Statistics checks: in all 12 databases of 100,000 rows (4 for the verification app, 8 for BookStack) and in all 3 states, the option was set as intended, the stored statistics did not change during the `EXPLAIN`s, and the sample sizes were in the expected order (36 of 36 state checks `ok`; no `ANALYZE` error).
- Routes: 24 captured routes of the verification app (43 statement types) and 27 of the 28 captured BookStack routes with statements (491 types; `GET /oidc/callback` issues no statement and counts 0 rows). Every one of the 23 + 23 routes of the denominator (section 6) has an estimate.
- `count(s, N)` is the per-type formula fitted by the capture from the three small scales (100, 1,000, 10,000), evaluated at N = 100,000. The static analysis's count was not used separately; for a type with a constant count the two agree. `per_execution` is the walk of section 2 over the raw JSON of the 100,000-row `EXPLAIN`, taking the largest of the three states (section 5; for every type, not only those marked unstable). The statements that cannot be sent to `EXPLAIN` by their form (7 `SET SESSION TRANSACTION …` statements) count 0 reads.
- Measured values and the version 0.1 predictions are those of the first test (`extrapolation-data/*/compare.json` and its measurements); they were not re-measured.
- **The first run of the comparison, and one correction.** The comparison script first treated a `duplicates_removal` wrapper that appears as an item of a `nested_loop` list (the shape of a semi-join with duplicate removal) as an unrecognised shape. That made one statement type, and so 3 BookStack routes, `explain_unavailable` under every candidate; on those 43 complete routes the rule chose C3 (median error C1 2.8%, C2 2.8%, C3 0.7%). The wrapper is one of those listed in section 2 ("descended into, they add no reads of their own"); the script's restriction of `nested_loop` items to plain tables was a defect of the script, not a shape the walk does not name. The script was corrected to descend into it, in table order, and run again: all 46 routes are complete and the rule chooses C2 (section 8.1). Both outcomes are reported because the choice depends on it. No coefficient, constant, rule or candidate was changed; the correction was made after the first result had been seen.

The estimates, the comparison script and its output are kept with the run records, outside the repository, so that they can be rebuilt from `explain.json` and `capture.json`.

### 7.1 Error of each candidate, per target

Error is `|estimate − measured| / measured` per route, over the 23 routes of each target (all complete). The shares are of the 23 routes (46 for both).

| Target | Candidate | Median error | Within ±20% (minimum line) | Within ±10% (goal) | Worst error |
| --- | --- | ---: | ---: | ---: | ---: |
| Verification app | C1 | 0.0% | 17 of 23 (73.9%) | 16 of 23 (69.6%) | 99.9% |
| Verification app | C2 | 0.0% | 18 of 23 (78.3%) | 18 of 23 (78.3%) | 99.9% |
| Verification app | C3 | 0.0% | 19 of 23 (82.6%) | 19 of 23 (82.6%) | 79.8% |
| BookStack | C1 | 63.2% | 6 of 23 (26.1%) | 6 of 23 (26.1%) | 1139.7% |
| BookStack | C2 | 66.5% | 6 of 23 (26.1%) | 4 of 23 (17.4%) | 1139.7% |
| BookStack | C3 | 66.5% | 5 of 23 (21.7%) | 3 of 23 (13.0%) | 1139.7% |
| Both | C1 | 16.3% | 23 of 46 (50.0%) | 22 of 46 (47.8%) | 1139.7% |
| Both | C2 | 11.7% | 24 of 46 (52.2%) | 22 of 46 (47.8%) | 1139.7% |
| Both | C3 | 11.7% | 24 of 46 (52.2%) | 22 of 46 (47.8%) | 1139.7% |

On the same 23 routes, the fits of the first test (version 0.1) were within ±20% on 22 of 23 (verification app) and 15 of 23 (BookStack), and within ±10% on 20 of 23 and 15 of 23. The per-route table is in the comparison output.

Reading of the table, within what it shows:

- On the verification app the candidates differ by the corrections they add: C1 alone reads 20.0% low on `GET /ng/count-in-loop` and 12.5% low on `GET /ng/nested` (the end-of-scan read of 100,000 small `ref` statements), which C2 recovers; C3 recovers `GET /ok/paginate` (C1 and C2 read the whole table because `EXPLAIN` of an unordered `LIMIT … OFFSET …` shows no limit) and loses nothing on this target.
- On BookStack none of the candidates is close to version 0.1: 6 of 23 against 15 of 23 inside ±20%. The median is pulled to 63–67% by one defect that all three share and by the per-statement effects of section 7.5.
- C2 and C3 have the same pooled median because the median route is the same; they differ on single routes in both directions (C3 helps `GET /ok/paginate`, and costs `POST /settings/maintenance/regenerate-references`, section 7.5).

### 7.2 Comparison with the fits of the first test (version 0.1), including the eight BookStack misses

The eight BookStack routes outside ±20% in the first test, with the estimate of each candidate (error in brackets):

| Route | Measured | Version 0.1 | C1 | C2 | C3 |
| --- | ---: | ---: | ---: | ---: | ---: |
| `DELETE /books/{bookSlug}/chapter/{chapterSlug}` | 520,510 | 832,784 (60.0%) | 849,615 (63.2%) | 949,723 (82.5%) | 949,723 (82.5%) |
| `DELETE /books/{bookSlug}/draft/{pageId}` | 520,514 | 832,775 (60.0%) | 849,614 (63.2%) | 949,720 (82.5%) | 949,720 (82.5%) |
| `DELETE /books/{bookSlug}/page/{pageSlug}` | 595,461 | 832,773 (39.9%) | 849,618 (42.7%) | 949,725 (59.5%) | 949,725 (59.5%) |
| `DELETE /settings/maintenance/cleanup-images` | 6,450,294 | 2,568,985 (60.2%) | 2,705,594 (58.1%) | 3,105,783 (51.9%) | 3,105,783 (51.9%) |
| `POST /settings/maintenance/regenerate-references` | 64,849,407 | 15,325,810 (76.4%) | 66,641,547 (2.8%) | 66,643,923 (2.8%) | 26,067,931 (59.8%) |
| `POST /settings/recycle-bin/empty` | 520,467 | 832,821 (60.0%) | 849,606 (63.2%) | 949,711 (82.5%) | 949,711 (82.5%) |
| `PUT /books/{bookSlug}/chapter/{chapterSlug}` | 115,232 | 34,481 (70.1%) | 481,799 (318.1%) | 481,815 (318.1%) | 481,815 (318.1%) |
| `PUT /books/{bookSlug}/page/{pageSlug}` | 115,257 | 34,506 (70.1%) | 481,814 (318.0%) | 481,836 (318.1%) | 481,836 (318.1%) |

What happened to the eight: inside ±20% under C1 and C2, one (`regenerate-references`; under C3 it is outside); none of the other seven. The four `DELETE`-type routes of the `deletions` family stayed about as wrong as before for the reason in the next paragraph, `cleanup-images` improved a little (60.2% to 58.1% under C1) and stayed low, and the two `PUT` routes got worse (70% low became 318% high, section 7.5).

The six `DELETE`-type routes that read the `deletions` table were measured once in the first test and several times in the variance investigation (36 trials, the value moving between 520 thousand and 850 thousand with the statistics' recalculation; and a run with the recalculation switched off, 520,498 to 520,531). The estimate is the heaviest of the three states (full scan of `deletions` in all three, section 7.3), which is the 850 thousand level:

| Route | First test | Recorded minimum | Recorded maximum | Statistics held still | C1 | Error of C1 against the minimum / the maximum |
| --- | ---: | ---: | ---: | ---: | ---: | --- |
| `DELETE /books/{bookSlug}/chapter/{chapterSlug}` | 520,510 | 520,458 | 850,461 | 520,518 | 849,615 | 63.2% / 0.1% |
| `DELETE /books/{bookSlug}/draft/{pageId}` | 520,514 | 520,454 | 850,515 | 520,514 | 849,614 | 63.2% / 0.1% |
| `DELETE /books/{bookSlug}/page/{pageSlug}` | 595,461 | 520,459 | 850,460 | 520,519 | 849,618 | 63.2% / 0.1% |
| `DELETE /books/{id}` | 850,504 | 520,500 | 850,535 | 520,531 | 849,623 | 63.2% / 0.1% |
| `DELETE /shelves/{slug}` | 850,456 | 520,451 | 850,516 | 520,511 | 849,612 | 63.2% / 0.1% |
| `POST /settings/recycle-bin/empty` | 520,467 | 520,438 | 595,440 | 520,498 | 849,606 | 63.2% / 42.7% |

Against the first-test value the estimate is within ±20% on two of the six (the two whose single value happened to be at the 850 thousand level); against the heaviest recorded level it is within 0.1% on five of the six. The recycle-bin route was never recorded above 595 thousand, so the heaviest-state rule overshoots it under every reading. The estimate does not and cannot say which level a given run lands on; the heaviest-state rule was fixed in advance for that reason (D107).

Of the routes the first test got right, the candidates lose some: of the 15 BookStack routes inside ±20% in version 0.1, C2 keeps 5 and loses 10 (the four `references` listing routes, the two `PUT` routes of a shelf and a revision restore, and the four small routes of section 7.5), and it gains `regenerate-references`. On the verification app, C2 keeps 17 of the 22 and loses 5 (`/ok/chunk`, `/ok/lazy`, `/ok/paginate`, `/ng/first-or-create-in-loop`, `/ng/save-in-loop`), and gains `/ok/with-count`.

### 7.3 The two signals that were known beforehand

Neither was fixed after the result; this is what the marks show.

- **`delete from references where from_type = ? and from_id in (?)` (range read at 10,000 rows, full read at 100,000): caught.** On the four BookStack routes that run it with the large representative (`PUT` of a chapter, of a page, of a shelf, and the revision restore) the plan is a range read of `references_from_id_index` (about 2,000 rows estimated) at 10,000 rows and a full scan (`ALL`, 99,964 rows; 99,335 in the "more pages" state) at 100,000 rows in all three states, and `plan_changes_at_scale` is set. On the two other routes that run the same type (`POST` of a draft, `POST …/regenerate-references`) the representative is a small one (4 and 400 rows) and the plan is a range read at both scales, so the mark is not set there; the mark depends on the representative statement's bindings, as the blind spot of section 4 says.
- **`delete from deletions where deletable_type = ? and deletable_id = ? …` (the plan moved with the statistics): not caught as `plan_unstable`.** The plan of this statement is the same in the three states of the run, a full scan of `deletions` (99,900, 99,900 and 99,648 rows estimated). `plan_unstable` is set on none of the six routes. What is set on all six is `plan_changes_at_scale` (a range read of `deletions_deletable_id_index`, 2,000 rows, at 10,000; the full scan at 100,000). So the mark that appears is a different one, and the estimate (the full scan, the heaviest level) agrees with the heaviest recorded level (section 7.2). The three states did not reproduce the range plan that the variance investigation saw in 56 of 72 plans after its trials: here the statistics were rebuilt by `ANALYZE TABLE` with 4, 20 and 200 sampled pages, and all three gave the full scan. Whether another state would is not shown by this run.

### 7.4 Counts and lists: `plan_unstable`, `plan_changes_at_scale`, `explain_unavailable`

Over every statement type of every captured route (not only the denominator routes):

| | Statement types | `plan_unstable` | `plan_changes_at_scale` | `explain_unavailable` |
| --- | ---: | ---: | ---: | ---: |
| Verification app | 43 | 0 | 0 | 0 |
| BookStack | 491 | 3 | 19 | 7 |

- `plan_unstable` (3): `select * from views where viewable_type = ? and viewable_id = ? and … (user_id = ?) limit ?` in `GET` of a chapter, of a page and of a shelf. In all three routes the plan is a `ref` on `views_user_id_index` in two states and an `index_merge` of two indexes in the "more pages" state; both estimate 1 row, so the estimate does not move.
- `plan_changes_at_scale` (19 statement types in routes): the `deletions` delete on 6 routes (5 `DELETE` routes and the recycle-bin route); the big `select * from references where to_type = ? and to_id = ? and (…)` on 8 routes (the 4 `references` listing routes and the 4 `PUT` routes); `delete from references where from_type = ? and from_id in (?)` on the 4 `PUT` routes; and the `select id, description_html from entities …` of `regenerate-references`.
- `explain_unavailable` (7): all are `SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED`, reason `not_explainable_kind`; they read no table and count 0. No statement was unavailable for any other reason, and with the corrected walk none was `unrecognised_shape`.

### 7.5 Residuals worth looking at (temporary tables, sorted writes, paging loops)

From a statement-level look at 10,000 rows (the estimate of each type against the rows it actually read there; a diagnostic, not used for the choice) and the per-route table:

1. **Dependent subqueries counted at their upper figure** (the rule of section 2): the big `select * from references …` has one `exists (…)` per `OR` branch. `EXPLAIN` estimates 19,744 rows produced by `references`, and the subqueries are counted once per such row (381 to 436 thousand under every candidate at 100,000 rows) while the request read about 35 thousand rows in all. One reading, not checked here: the first condition of each branch (`from_type = ?`) fails for most rows, so the `exists` is not run for them. This is the largest part of the 318% to 1,140% errors on 8 BookStack routes (4 `references` listings, 4 `PUT`). It is the choice of section 2 ("deliberately the upper figure"), and it is wrong by a factor of ten here. No candidate can express it.
2. **Paging loops** (the blind spot of section 4): the representative of a `… limit ? offset ?` type is the largest offset captured. `/ok/chunk` and `/ok/lazy` run 1,001 pages at 100,000 rows; the estimate is 1,001 × 10,100 (offset 10,000 plus limit 100, the last page of the 10,000-row capture), while the rows read were 50.2 million because the pages read offsets from 0 to 100,000. The estimate is 79.8% low. `cleanup-images` pages over `images` in the same way. `regenerate-references` agrees to 2.8% under C1 and C2 although, at 10,000 rows, its two paging statements are estimated 61% and 37% high: two errors of different sign that happen to cancel are not evidence that the estimate is right there. C3 (the limit cap) applies to those two statements and lowers that route to 26 million (59.8% low); on the pure paging routes the cap, offset plus limit, is the same figure as the estimate and changes nothing.
3. **A temporary table's reads are not counted** (section 2): `select distinct role_permissions.name … from role_user …` read 190 rows and is estimated 61 to 63. It is in every BookStack request that checks permissions, so on the small routes (`GET …/draft/{pageId}` 211 rows, `GET /images/edit/{id}` 199, `POST /import/{id}` 191, `PUT /settings/users/{id}` 204) the estimate is 64–90 and the error is 57–67%, about 120 to 130 rows each. These routes are small in absolute terms; the error is large only because the denominator is small.
4. **`EXPLAIN` of an impossible lookup reads 0, the request reads 1** (`/ng/first-or-create-in-loop`): `select … where product_id = ? limit 1` on an empty table is shown as reading nothing, and each of the 100,000 executions read one row; the estimate is 49.9% low.
5. **The end-of-scan constant of C2 is not true of an update by primary key** (`/ng/save-in-loop`): `update … where id = ?` reads 1 row per execution, C2 adds 1 for the access type shown, so C2 is 50% high where C1 is exact. For `ref` reads of the `count(*)` loop the constant was right, and C1 is 20% low. The one constant per access type of C2 cannot tell these apart.
6. **Representative that is not typical of the type.** The `references` selects and deletes use the largest-data binding of the 10,000-row capture; they are why the `plan_changes_at_scale` mark is set on some routes and not on others for the same type (section 7.3).

## 8. Choice and what can be said

### 8.1 The candidate chosen by the rule of section 6

Rule applied as written, on the 46 complete routes (23 of each target): median error C1 16.3%, C2 11.7%, C3 11.7%. C1 is out by more than 0.01. C2 and C3 differ by less than 0.01 and are tied; both have 24 routes within ±20% and 22 within ±10%, so the last step decides: the lower number. **The candidate chosen is C2** (C1 plus a per-scan overhead).

If the first run of the comparison (section 7.0) were kept, the rule would choose C3 on 43 routes. The choice is reported as C2 because the corrected walk is the one section 2 describes. The choice is for the next step (the estimates committed before the 200,000-row test); it is not a result of a test.

The chosen candidate does not come near the minimum line on BookStack: 6 of 23 routes within ±20% (version 0.1: 15 of 23), and 18 of 23 on the verification app (22 of 23). The rule picks the best of three; it does not say that any of them is good enough.

### 8.2 What can and cannot be said

1. The 100,000-row records were seen before; this is a check during development, not a test with a blind prediction. The first run of the comparison was also seen before its walk was corrected (section 7.0).
2. Pass or fail of version 0.2 is decided at 200,000 rows with the estimates committed first. Nothing here is a pass or a fail.
3. Two applications, generated data, one cold value per route. Six of the BookStack routes have a measured value that moves with the statistics (section 7.2); against them an estimate can be right or wrong depending on which recorded level is called the truth.
4. What the data support: `EXPLAIN`-based estimates reproduce the rows of statements that read through an index or a table scan the optimizer sizes well (most of the verification app's N+1 and loop routes within 0.1%, with the end-of-scan constant of C2 where the loops are of small `ref` statements), and the heaviest-state rule reproduces the heaviest recorded level of the `deletions` routes. The marks `plan_changes_at_scale` caught the `references` delete signal and flagged the `deletions` delete; `plan_unstable` did not catch the `deletions` delete.
5. What the data do not support: that this estimate is more accurate than the fits of the first test (on these 46 routes it is not: 24 against 37 within ±20%), or that it generalises to a third application. Five causes of misses are named in section 7.5; none was corrected, because a candidate set is fixed (section 6). A new candidate set, dated and committed before it is compared, would be the way to address them; this file does not do that.
