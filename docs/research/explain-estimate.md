# Estimating rows examined from EXPLAIN: candidate formulas and the rule that picks one

Status: the candidates and the selection rule are written and committed **before** `explain-at-scale` is run at 100,000 rows. The results sections (7 and 8) are empty on purpose and are filled in after that run. Nothing below is changed after the run, except by adding a new, dated candidate set that is committed again before it is compared (section 6).

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

## 7. Results (to be filled after the 100,000-row run)

### 7.1 Error of each candidate, per target

To be filled after the 100,000-row run.

### 7.2 Comparison with the fits of the first test (version 0.1), including the eight BookStack misses

To be filled after the 100,000-row run.

### 7.3 The two signals that were known beforehand

To be filled after the 100,000-row run.

- the `DELETE` on `references` (range read at 10,000 rows, full read at 100,000 rows): is `plan_changes_at_scale` set?
- the `DELETE` on `deletions` (the plan moved with the statistics): is `plan_unstable` set?

### 7.4 Counts and lists: `plan_unstable`, `plan_changes_at_scale`, `explain_unavailable`

To be filled after the 100,000-row run.

### 7.5 Residuals worth looking at (temporary tables, sorted writes, paging loops)

To be filled after the 100,000-row run.

## 8. Choice and what can be said

### 8.1 The candidate chosen by the rule of section 6

To be filled after the 100,000-row run.

### 8.2 What can and cannot be said

To be filled after the 100,000-row run.

Fixed in advance, to be repeated and completed there:

1. The 100,000-row records were seen before; this is a check during development, not a test with a blind prediction.
2. Pass or fail of version 0.2 is decided at 200,000 rows with the estimates committed first.
3. Two applications, generated data, one cold value per route.
