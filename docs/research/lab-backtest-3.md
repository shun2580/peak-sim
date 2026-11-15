# Lab backtest (B) and the mark, third round: 300,000 rows

Status: **SKELETON. No result is in this file.** What is written here does not depend on a result: the purpose, the rules, the order of work, the way of measuring and the limits. Every place that a result, a commit, a time or a value chosen later has to fill is marked `TBD`. When a `TBD` is filled, the section says where the figure comes from. Nothing in the rules below is changed when the figures are added.

This file is the record of the third round of test B and of the mark `app_limit_risk` (`docs/simulator-spec.md`, version 0.2, sections 6.2, 6.4, 7.2, 7.4, 7.4.1 and 7.4.2). It follows `docs/research/lab-backtest.md` (the first round, which did not pass) and `docs/research/lab-backtest-2.md` (the second round: B passed at the lowest standard and missed the goal on item 4 by one scenario; the mark failed with one miss). It says what was changed, what is done in which order, what is measured, and what the numbers can and cannot support. The pass lines, the tolerances and the rule for "undecidable" are those of the spec and are not moved by this file.

What is checked is a **check against a busy period reproduced in the lab**: a prediction compared with a measurement made in the lab. Nothing here is observed on a real deployment, and this file does not claim any precision on one.

The third run of test A (the extrapolation test at the same row count) is in `docs/research/extrapolation-test-3.md`.

**Result in brief:** `TBD` (written after section 5; one line each for B items 1 to 4, for the mark, and for the multiple of the capture's largest size, which is 10).

## 1. What was changed

The second round left two things open, and the project owner decided on 2026-11-16 (D194, written before any 300,000-row prediction was made) how the third round deals with them.

### 1.1 The mark gets a memory limit

In the second round the book page at 200,000 rows answered HTTP 500 in every window of the three 200,000-row scenarios, and the application's log shows that it ran out of its 512M memory limit (`lab-backtest-2.md`, section 4.4). The mark `app_limit_risk` looked at the application's CPU time against the run-time limit only and had no input for the memory limit, so it gave no warning for a route that fails by memory first. That was the one miss.

The change (spec section 7.4.2):

- **What the mark adds.** A route is also marked when its predicted peak memory may reach PHP's `memory_limit`. The basis is the route's peak-memory estimate (spec section 6.2, the route-level fit that test A has reported since version 0.1).
- **The limit is an input.** The value of the memory limit is given as an input of the prediction (name: `TBD`, fixed with the implementation). The tool has no default; without the input no memory mark is output and the output is byte for byte what it is without this change.
- **The CPU-time rule is not changed.** The factor 9.11 (D178) stays.
- **The output says which limit the mark came from**, the CPU time or the memory.
- **The threshold** (what is multiplied by the peak-memory estimate before it is compared with the limit): `TBD`. It is chosen **only from records of row counts already seen** (10,000, 30,000, 50,000, 70,000, 100,000 and 200,000) and committed before the 300,000-row prediction (section 3.3). Record of the rule and of how it catches the 200,000-row book page: `TBD` (decision number and commit).
- **Routes whose peak-memory estimate is outside ±30% in test A** (spec section 6.2 says such routes are not output): how the mark treats them is decided and recorded before the prediction. `TBD` (decision number and commit).
- **Where the new part is called from:** `TBD` (the call path from the prediction's entry point, written when the part exists).
- **Tests:** `TBD` (a route over the limit is marked and one under it is not; the mark follows the input; without the input the output is unchanged).

### 1.2 Nothing else is changed

The calibration is the seven runs `cal-1` to `cal-7` of the second round, unchanged (`docs/research/lab-data/calibration-r4/fit-7runs/calibration.yaml`, SHA-256 `56adb832dba42ee9b24fcf22ca716f963e7c25e6e8b287a61f8333b99ea26793`, two coefficients, `c_query` 2.84407e-4 and `c_row` 9.37963e-7 in seconds). The formula, the lab's entry and its four guards (`lab-backtest-2.md`, section 1.6), the threshold of the database (0.8 with 2 CPUs) and the rules of the spec are as they were. The records of the first and second rounds and their verdicts are not changed.

### 1.3 The premise of the estimate

As in the second round (D180): every lab database is analyzed with `ANALYZE TABLE` after it is built and before it is used, and the `last_update` of the statistics tables is recorded before use. The estimate assumes a database whose statistics are in order; it is not claimed for one whose statistics are stale or missing. The prediction's output carries that note.

## 2. What is tested

### 2.1 B: the prediction of the database's utilization

Same settings as the second round (spec section 7.4.1): PHP's `max_execution_time` 0; php-fpm's `request_terminate_timeout` 0s; the web server's read limit and the load tool's time limit for one request 600 s. The memory limit is not among them and is not touched. The record of every step carries the three values.

N_0 = 10,000. The N tables of the eight routes are grown together to 300,000 rows (30 times N_0; the three tables `entities`, `images` and `references` in the second round). Five scenarios, all at 300,000 rows (the mixes are defined as in section 7.2 of the spec):

| id | rows | mix of routes |
| --- | --- | --- |
| s-1 | 300,000 | even |
| s-2 | 300,000 | first four routes 80%, last four 20% |
| s-3 | 300,000 | last four routes 80%, first four 20% |
| s-4 | 300,000 | odd-numbered routes (1, 3, 5, 7) 80%, the others 20% (the definition of `cal-3`) |
| s-5 | 300,000 | even-numbered routes (2, 4, 6, 8) 80%, the others 20% |

**The ladder is 15 steps** of total rps: 0.025, 0.035, then the 13 of section 7.2 of the spec (0.05, 0.07, 0.1, 0.15, 0.2, 0.3, 0.45, 0.7, 1.0, 1.5, 2.2, 3.3, 5.0). The two lowest were added because at 300,000 rows the limit rps is expected near the lowest step, and a scenario whose limit cannot be measured would make the test undecidable. A step discards its first minute and keeps a window of the smallest multiple of 40 ÷ rps seconds that is at least 300 seconds. A scenario stops after the first step whose database utilization is 80% or more, or whose intended rps did not come out (outside ±5%). These are the rules of section 7.2; only the two steps are new.

### 2.2 The verdict of B

The rules are those of section 7.4 of the spec (B) and are not moved. Over the valid steps whose measured utilization is from 10% to 90% (the steps used for the difference), and the scenarios whose limit rps could be measured:

| item | lowest standard | goal |
| --- | --- | --- |
| 1. Mean absolute difference of utilization, smaller than the naive estimate's | required | required |
| 2. Mean absolute difference of utilization | 15 points or less | 5 points or less |
| 3. Share of valid steps where "threshold exceeded or not" agrees with the measurement | 80% or more | 80% or more |
| 4. Share of scenarios whose predicted limit rps (point) is inside the measured one | ±20%, 80% or more | ±10%, 80% or more |

B is **undecidable**, and not a pass, when fewer than 10 steps can be used for the difference or when the limit rps could be measured for fewer than 4 scenarios. A ratio on the boundary of a tolerance passes. The limit rps of a measurement is found as in section 7.2: a straight line between the two valid steps that straddle 80%; if the step at or above 80% was invalid, a line carried on from the two highest valid steps, and the extension is recorded.

### 2.3 The mark

Measured after B, with the application's limits put back at their defaults (run-time limit 30 s; memory limit as the lab has it, 512M in the second round), at 300,000 rows: one cold request, with no load, for each of the eight routes. For each route the record holds the HTTP status, the application's CPU time, and **which limit stopped the request**, read from the application's log (the PHP fatal error and the php-fpm log). The log is copied out **before any container is rebuilt or the stack is taken down**: in the second round the log was gone when it was needed, and the cause of the book page's failure there is an inference (`lab-backtest-2.md`, section 5.3).

Rules of section 7.4.1 of the spec, with the memory limit counted among the limits that stop a request:

- **hit**: marked and stopped by a limit;
- **miss**: not marked and stopped by a limit;
- **false alarm**: marked and not stopped.

The line for passing is **no miss**. The number of false alarms is always shown. For a route that is stopped, the log says whether the CPU-time limit or the memory limit did it, and the table says whether the mark came from the same limit.

### 2.4 The multiple of the capture's largest size

The estimates are made from captures at 100, 1,000, 10,000 and 30,000 rows. The row count of the test is 300,000, **10 times the capture's largest size**. In the second round the multiples were 1.667 (50,000 rows) and 6.667 (200,000 rows). A reader should take every agreement or disagreement below as the result of carrying the estimate 10 times past what it was captured from. The result is not a statement about 3,000,000 rows.

## 3. Prediction

`TBD` (filled after the prediction is committed, from the committed file; no figure typed by hand).

### 3.1 What the prediction is made from

| input | what | identity |
| --- | --- | --- |
| calibration | `cal-1` to `cal-7` of the second round, two coefficients | `calibration-r4/fit-7runs/calibration.yaml`, SHA-256 as in section 1.2 |
| row estimate, 300,000 rows | the point of every step, the marks, the upper side | `TBD` (path, SHA-256, commit) |
| scenario file | the five scenarios of section 2.1 with the limits and the threshold | `TBD` |
| naive estimate | the utilization of the one of `cal-1`, `cal-2`, `cal-3` chosen by section 7.3 of the spec, scaled by the total rps | `TBD` (which run, its utilization) |
| the limits of the application | the run-time limit and the memory limit, as inputs | `TBD` |

The estimate at 300,000 rows is made in the same way as at 200,000 rows in the second round: `ANALYZE` and `EXPLAIN` only, no request and no load. It is made with the capture of the lab's own way of building data (the N tables of the eight routes grown together), at 100, 1,000, 10,000 and 30,000 rows, on databases analyzed after they were built.

### 3.2 The predicted utilization, step by step

For each scenario, a table of the total rps against the point, the safe side and the naive estimate, and the limit rps for each. `TBD` (written out by a script from the committed prediction file).

### 3.3 What was fixed before the prediction, in order

| time | commit | what |
| --- | --- | --- |
| 2026-11-16 00:57 | D194 | the ruling: the memory limit in the mark; 300,000 rows for B, the mark and A; Bagisto later |
| 2026-11-16 01:03 | `6114fba` | spec section 7.4.2 |
| `TBD` | `TBD` | the memory part of the mark: rule, threshold, treatment of routes outside ±30%, tests |
| `TBD` | `TBD` | the check that 300,000-row databases can be built (count only) |
| `TBD` | `TBD` | the estimates and the list of marked routes at 300,000 rows |
| `TBD` | `TBD` | the prediction file and its input |

### 3.4 The order that makes the test valid

1. The memory part of the mark, with its threshold, is committed first. The threshold is chosen from records of the counts already seen and from nothing at 300,000 rows.
2. Until the prediction is committed, a 300,000-row database is only **built and counted**, and read for the prediction's inputs (`ANALYZE`, the statistics tables, `EXPLAIN`). No request, no load, no timing, no `CHECKSUM TABLE`. A single request or a single load step at 300,000 rows would use the count up.
3. The prediction file is committed. The measuring entries open only when the prediction file is in HEAD, has no difference in the working tree, and the directory of predictions holds no other changed or untracked file. The same gate stands in front of the measurement of test A. The entry that takes the count and `CHECKSUM TABLE` of the N tables refused the 50,000- and 200,000-row databases in the second round unless the prediction file passed that gate and held a scenario with that row count; the same for 300,000 rows: `TBD` (confirmed when the prediction is committed).
4. Only then is anything measured at 300,000 rows. The prediction, the estimate, the calibration, the formula, the ladder, the scenarios, the threshold of the mark and the rules of the spec are **not changed** after the commit. If a step is later found to be wrong, it is recorded and reported, and not repaired.

| | |
| --- | --- |
| Commit of the prediction file | `TBD` |
| Commit time | `TBD` |
| SHA-256 of the prediction file as committed | `TBD` |
| First step of a new scenario started | `TBD` (read from the step's own record) |
| Interval between the two | `TBD` |

## 4. Measurement

`TBD`. The records are written to `docs/research/lab-data/` (directory names `TBD`) as the tools wrote them, with no pass or fail in them. This file is where the verdict is first written.

### 4.1 The order of work

`TBD` (a table of times, as in section 4.1 of `lab-backtest-2.md`). The order planned: B first (s-1 to s-5 at 300,000 rows), then the settings are put back, then the mark. Where a measurement is interrupted (a stop of the host or anything else), the databases' statistics tables, the counts and `CHECKSUM TABLE` of the N tables and the real values of the limits are read before and after the restart, the interrupted scenario is measured again from its first step, and the interrupted run is kept apart and enters no count.

### 4.2 The settings, and how they were changed and put back

For every change of the lab's settings: what was changed, how it was put back, and the value read back **from the side of the application server** (one request through php-fpm, the web server's configuration, the php-fpm configuration), not from the command line. `TBD`. If a setting cannot be put back, the work stops and the stop is reported.

### 4.3 The steps of B

`TBD`. One table per scenario: intended rps, achieved rps, ratio, valid or not, database utilization, application CPU, requests in the window, HTTP statuses in the window, start and end. The three settings are in every step's record.

### 4.4 Requests that did not complete

`TBD`. The number of HTTP 500 per scenario, counted from the web server's access log, and the cause of each kind read from the application's log. In the second round the book page ran out of memory in every window at 200,000 rows while B's memory limit stayed at 512M; at 300,000 rows the same can happen again and, if it does, it is reported here with a bound on how far it can move the measured utilization, as in section 7 of `lab-backtest-2.md`.

### 4.5 The mark

`TBD`. For each of the eight routes: the HTTP status, the application's CPU time, the limit that stopped the request, and the mark (and which limit it came from). The log copy and the time it was taken.

## 5. Verdict

`TBD`. Written after section 4, from the records' raw data. A second reader recomputes the three verdicts (B, the mark and A) from the raw records without using the first reader's figures, and the two are compared; the comparison is recorded.

### 5.1 What was checked before the verdict

`TBD`: that the prediction's commit is earlier than the first measurement (from the history and from the records' times); that the prediction, the estimate, the calibration and the threshold of the mark in HEAD are the files committed; that the three settings were in force in every step; that no `last_update` of the statistics changed during a ladder (or which did).

### 5.2 B

`TBD`. Items 1 to 4 against the lowest standard and the goal; the table of steps used; the measured limit rps of each scenario and whether it was extended; the verdict (pass, fail or undecidable).

### 5.3 The mark

`TBD`. Hits, misses and false alarms, the limit that stopped each stopped request, and whether the mark came from that limit.

### 5.4 What is not repaired

If anything fails, nothing is repaired in this round. Candidates for a repair, if any, are listed here as candidates only; each would change the spec and is for the project owner to decide. `TBD`.

### 5.5 Readings of the rules that could have gone another way

`TBD`. Where a rule can be read in two ways, the reading used and the effect of the other one, as in section 5.5 of `lab-backtest-2.md`.

### 5.6 What is left before version 1.0

Conditions 1 to 3 of section 0 of the spec, counted as D153 counts them (with the change of D167). `TBD`, with numbers.

## 6. Comparison with the naive estimate

The naive estimate (spec section 7.3) scales the utilization of the chosen run at 10,000 rows by the total rps and knows nothing of the row count or the mix of routes. It is in the step tables. `TBD`: the mean absolute difference per scenario for the point and for the naive estimate, item 3 for both, and item 4 for both (item 4 against the naive estimate is shown and is not part of the pass rule).

## 7. What the lab cannot say

The limits of the lab are those of section 6 of `lab-backtest.md` and section 7 of `lab-backtest-2.md`:

- **Cache behaviour of a real deployment**, data that does not fit in the database's memory, contention with other work on the same machine, and the load of writes. The lab's entry writes the `views` table and nothing else, and the entry takes GET only (D187); the counter is committed and grows.
- **The lab's data is the generator's.** For example how many books a shelf holds, and that every book has the same slug, are properties of the data built for the lab. Whether the 300,000-row database has the same properties is read when it is built (`TBD`).
- **B is measured with the application's time limits lifted and its memory limit left as it was.** B says nothing about the application stopping early by time. It says nothing about the application stopping early by memory either, except where a request is recorded to have failed that way (section 4.4).
- **The mark is one cold request per route**, one at a time, without load. It says nothing about the application under load or about a second request to the same route.
- **The estimate assumes a database whose statistics are in order** (section 1.3) and is carried to 300,000 rows from a capture whose largest size is 30,000 rows, 10 times (section 2.4). The calibration was measured at 3,000, 10,000 and 30,000 rows, and its coefficients are used at 300,000. No check of the estimate beyond 30,000 rows exists at 300,000 rows other than this one.
- **One row count, once.** 300,000 rows is a count not seen before this round and is used up by it: it cannot be used again as a test of anything repaired afterwards (spec section 7.4.1 applies the same rule).
- **One application.** B is run on BookStack only, with its eight routes chosen by the rule of section 7.2 of the spec. Nothing here says how the tool does on another application; the third application (Bagisto) is a later step.
- **php-fpm's `pm.max_children` is 16**, as before. A step may stop climbing because all the workers are busy and not because of the database; the application's CPU and the waits in each step's record are where this is looked for.

Items found by the measurement are added to this list after section 5, each with the section it comes from. `TBD`.

This file does not claim any precision on a real deployment.
