# Variance investigation: why did six BookStack routes with one formula measure three different values at 100,000 rows?

This file is the record of an investigation that follows the first run of test A (`docs/research/extrapolation-test.md`). It does not add anything to that file. It says what was run, in which order, what the records show, and what they cannot show. Figures are copied from the records named in each section and are not rounded again by hand. The raw output is outside the repository (raw output kept by the operator); this file gives the aggregates and says which record each comes from. Nothing in this file changes a formula, a fit, the pass line or a rule of the measurer.

## Summary

- **T1 (section 4): all six routes change value inside one database.** Six BookStack routes share one formula (about 8.3 rows per unit of N). Measured three times on each of two databases of 100,000 rows, on the same rows and with the same arguments, their cold rows examined took four values: about 520 thousand, 595 thousand, 775 thousand and 850 thousand. By the rule of the table tool (a gap of more than 5%), every route varies inside at least one database (class (a)); none is only different between the two databases, and none is flat on both.
- **What moves is one statement.** In every route it is `delete from deletions where deletable_type = ? and …`, which runs 5 times per request. The rest of the request reads the same number of rows in every trial. The smallest and the largest of the 5 runs give a form that can be read from the numbers: some runs read about a fifth to a quarter of the table, some about all of it. The plan of each run was not recorded, so this is a reading of sums, minima and maxima, not an observation.
- **`ANALYZE TABLE` before the measurement (section 4.6) did not remove the movement, and it does not settle the cause.** On one database, with `ANALYZE TABLE` run once before the first trial, 4 of the 6 routes still varied inside the database (draft, page, `DELETE /books/{id}`, shelves), the level of about 850 thousand did not appear, and the recycle-bin route read about 775 thousand in all three laps. But the stored statistics of `deletions` were recalculated automatically inside the very first trial, and again inside every later one, so stage 3 is not a measurement with fixed statistics. A movement that remains does not show that the statistics are unrelated, and a movement that is smaller does not show that they are the cause. One database and 18 trials are also a smaller sample than stage 2.
- **T2 (section 5):** for the two `PUT` routes, the extra rows between 10,000 and 100,000 come from two statements on `references`; the plan of one of them (a `delete`) changed from a range on an index to a full scan of the table. The stored statistics of `references` were the same at both scales.
- **T3 (section 6):** the twelve routes with no empty-system point are left out because the record they address lives in the table that the plan empties at N = 0. Four options are set out; this file does not choose among them. Three of them need a ruling by the project owner.
- **Not done here:** a test that stops the automatic recalculation of statistics (for example per table) was not run, because it changes the conditions of the measurement. Whether to run it is left to the maintainer (section 7).

## 1. Question and scope

In the first run of test A, BookStack missed the pass line on rows examined (15 of 23 routes within ±20%, 65.22%; `docs/research/extrapolation-test.md` sections 2 and 3). Three observations in that record are the reason for this investigation:

1. **Six routes with the same fitted formula gave different values at 100,000 rows.** `DELETE /books/{bookSlug}/chapter/{chapterSlug}`, `DELETE /books/{bookSlug}/draft/{pageId}`, `DELETE /books/{bookSlug}/page/{pageSlug}`, `DELETE /books/{id}`, `DELETE /shelves/{slug}` and `POST /settings/recycle-bin/empty` have a formula of about 8.3 rows per unit of N. The cold value measured at 100,000 was about 5.2N, 5.95N or 8.5N, and for five of the six the warm-up request and the cold request gave different values (`extrapolation-test.md` section 3, first group). No `EXPLAIN` was taken then.
2. **Two `PUT` routes were measured at about 1.15N where the prediction was about 0.35N** (`PUT /books/{bookSlug}/chapter/{chapterSlug}` and `PUT /books/{bookSlug}/page/{pageSlug}`; third group of the same section). The cause was not observed.
3. **Twelve routes that address one record had no empty-system point** and were left out of every denominator (`insufficient_scales`; section 4.2 of the same file).

The project owner ruled on the result of test A on 2026-11-08, and the two rulings fix the scope here:

- **D103**: the result of test A (verification app pass, BookStack fail) is not moved and is not scored again.
- **D104**: if a formula is changed, it is checked by predicting a scale not yet measured (200,000 rows), committing the prediction, and only then measuring. The pass line (D71, D95) is not changed.

So this investigation only separates causes; it does not repair anything. The questions, one per part:

| Part | Question | Section |
| --- | --- | --- |
| T1 | Do the six routes change value inside one database, only between two freshly built databases (the way the data are built), or neither? | 4 |
| T2 | For the two `PUT` routes, which statements change between 10,000 and 100,000 rows, and did their plan change? | 5 |
| T3 | Is "a route that shows or updates one record cannot find it in an empty system" the cause of the twelve missing empty-system points, and what could be done about it within the order of test A? | 6 |

What the investigation does not do: it does not change the cold definition or the steps of one trial, it does not touch `docs/research/extrapolation-test.md`, and it does not choose among the options of section 6.

## 2. Method

### 2.1 The outside runner (`variance-run`)

The measurer builds a database, measures each route once, and drops the database. It has no way to measure the same route several times on the same database, and a second call builds a different database. So the repeats are made by a runner outside it (`poc/runtime/bin/variance-run`, commit `d6557f2`; `bin/variance-watch` is its watcher). It calls the existing parts, `build-db` and `measure-one`, and does not change them.

- Each database has its own compose project, run id, application key and passwords, decided once. The database is built once; `measure-one --phases=warmup,cold` then runs route 1 to 6, lap 1 to 3, on it. Nothing is rebuilt between trials. A request that cannot be rolled back (exit code 14) stops the run of that database.
- The arguments of each route are those recorded for the step-5 run of test A (`tasks.json`), unchanged. The order of the routes within a lap is the order of that run: chapter, draft, page, `DELETE /books/{id}`, recycle-bin empty, shelves.
- With `--parallel`, two databases run at once. Nothing in the stack publishes a port, and the compose project, run id and output directory are separate, so the two do not collide.
- The measurer is started from a copy of HEAD made with `git archive` outside the repository, never from the working tree. The runner records the commit of that copy; commit `1d820f7` makes it refuse to record HEAD for a working tree with uncommitted changes. T1 and T2 used the copy of `1d820f7`.
- The watcher reads `SHOW FULL PROCESSLIST` and the server's row counters. It writes an alert file and calls a notification program once when a statement has run for 900 s or more, or when there has been no statement and no output for 1,800 s. It never stops anything.
- Between the commit of test A's measurer (`305c928`) and `c492cd8`, the code under `poc` and `src` is identical (the diff is empty). The runner (`d6557f2`), the tap flag (`e944520`) and the guard (`1d820f7`) were added after `c492cd8`.

### 2.2 What was not changed

The cold definition and the steps of one trial are those of test A:

- A trial is one `measure-one` process: a warm-up request, then the cold request. The warm request is left out (`--warm=off`, D92). The value compared is the cold one.
- "Cold" is one thing in the code: the application's cache store is flushed just before the second request. The database server is not restarted, its buffer pool is not dropped, and the PHP process is not restarted (the cold request is the second request of the warm-up's process, on the same connection).
- Each request runs inside a transaction and is rolled back. The row count of every table is compared before and after, and a difference ends the run as contaminated (exit code 14). The record each route addresses is the one with the smallest primary key, and it is not re-picked between trials. The rows are therefore the same at every trial. What can still differ between two trials on one database is state that stays outside the rollback, for example the stored statistics and the auto-increment counters. The buffer pool does not change the number of rows a statement reads, so it is not a candidate for the change of value in rows examined; it matters for elapsed time, which is not used here. The application cache is different: it can change which statements run at all (a hit means no query to the database), which is why it is flushed before every cold request (see the point above).

### 2.3 The per-statement tap (`--tap-out`)

To see which statement changes, `measure-one` has a flag `--tap-out=<file>` (commit `e944520`). Without the flag, no listener is attached. A test checks that the output with the flag and the output without it are the same. That is all the test fixes: it compares the two modes of the same code, and it is not a check that the output without the flag equals the output of the code before the flag was added.

- It acts only inside the cold window. The file holds one JSON object on one line per trial (`tap.jsonl`).
- Per SQL text (kept with `?` in place of values), it records the number of runs, the rows read in total, the smallest and the largest per run, the first and the last binding, and the `EXPLAIN` for the first and the last binding. "Rows read" is the difference of the seven `Handler_read_*` counters before and after the statement, less the one-read baseline; this is the same definition as rows examined of test A (D96), and it is not the number of rows returned.
- The `EXPLAIN` is taken right after the cold window closes, before the rollback, in the same session. It is the plan chosen right after the request, not a trace of the plan used at the moment the statement ran. Taking it for both the first and the last binding is a way to see whether the plan moved during the window.
- The window totals are in the file: `cold_rows_examined` (as in the trial's record), `rows_sum` (the sum over statements) and `unaccounted` (the difference; 0 is normal).
- The plan of an `insert`, `update` or `delete` is only in the tap file. It is not put in the `plan` field of the attempt, because the classification of the check of rows examined (section 5 of `extrapolation-test.md`) reads that field, and its result must not move.
- The tap of the earlier check, `tests/Docker/Support/tap_statements.php`, was not used for the trials: it sends the request once more in another process, without the login or the body that the six routes need, and takes `EXPLAIN` only for `select`. A second request is not the trial's request.

### 2.4 The record of statistics and of content

Before and after every trial, the runner records the stored statistics of InnoDB (`innodb_table_stats`: `n_rows`, `last_update`, index sizes) and the cardinality of every index (`stats_before.tsv`, `stats_after.tsv`). For each database it also records the row count and a checksum of every table (`content.tsv`, `content.sha256`); equal digests mean equal data. These are read-only observations.

An `ANALYZE TABLE` stage exists in the runner (`--analyze=rerun`: laps, one `ANALYZE TABLE` outside any request, laps again, on the same database; `--analyze=before`: `ANALYZE TABLE` right after the build). It was to be run only if T1 read as "a change inside one database". T1 reads that way (section 4.7), so `--analyze=before` was run; its result is in section 4.6. `--analyze=rerun` was not run.

Elapsed time is not one of the measured quantities (D97, note), and it is not used in any reading here. It is recorded in section 3 because it differs with the tap.

## 3. Tap calibration (10,000 rows)

Before the tap was used on 100,000 rows, it was run on a 10,000-row BookStack database to see what it costs and whether it changes the figure.

Setup: one database (generator data, profile `bookstack`), the copy of `e944520`, two routes (`DELETE /books/{id}`, `DELETE /shelves/{slug}`), `--phases=warmup,cold`. On that one database, `measure-one` was run with and without `--tap-out`, alternating, three times per route and mode, in two orders (without then with, and with then without): 24 process runs. Records: `tap-cal/alt.tsv` (first order) and `tap-cal/alt_reversed.tsv` (second order), outside the repository.

**Cost.**

| | Without the flag | With the flag |
| --- | --- | --- |
| Elapsed per process (warm-up and cold) | 21.1 to 22.2 s | 32.2 to 33.2 s |
| Statements in the cold request | 20,107 (route 1), 20,100 (route 2) | the same |
| Peak memory, route 1 | 26,054,336 bytes | 26,089,408 bytes |
| Peak memory, route 2 | 26,046,528 bytes | 26,078,144 bytes |

The ratio of the process times is about 1.51. The warm-up was not timed on its own; it takes about as long as the cold request without the flag, so the cold request alone is estimated at about 10.8 s without and about 21.8 s with the flag, a factor of about 2.0 (an estimate, not a measurement). The increase is about 11 s over about 20,000 statements, about 0.54 ms per statement (estimate). The tap file of one cold request is 44,738 bytes (route 1), holding 43 SQL texts.

**Rows read are fully accounted for.** `unaccounted` is 0 and `unread_events` is 0 in the 12 tapped runs: the sum of the statements equals the cold rows examined (for example 85,527 and 85,527 for route 1, first run).

**Values move without the flag.** Without the flag, two successive processes on the same database, with the same arguments, gave different cold values. Rows examined of the cold request (`alt.tsv`, `alt_reversed.tsv`):

| Route | Order | Run | Without the flag | With the flag |
| --- | --- | ---: | ---: | ---: |
| `DELETE /books/{id}` | without, then with | 1 | 85,527 | 85,527 |
| | | 2 | 73,035 | 85,535 |
| | | 3 | 85,535 | 85,535 |
| `DELETE /shelves/{slug}` | without, then with | 1 | 73,023 | 73,023 |
| | | 2 | 66,020 | 66,020 |
| | | 3 | 58,517 | 66,020 |
| `DELETE /books/{id}` | with, then without | 1 | 58,537 | 66,040 |
| | | 2 | 85,535 | 85,535 |
| | | 3 | 85,535 | 85,535 |
| `DELETE /shelves/{slug}` | with, then without | 1 | 79,526 | 58,506 |
| | | 2 | 85,505 | 66,020 |
| | | 3 | 85,456 | 85,485 |

What this shows and does not show:

- The change of value from one request to the next on one database is there without the tap (for example 85,527, 73,035, 85,535 for route 1 in the first order). The tap did not cause it.
- Run by run, the figure with the flag and the figure without it do not always agree (route 1, run 2 of the first order: 73,035 and 85,535). Because the values move by themselves, a match run by run cannot be demanded, and it is not found. The values with the flag lie in the same family as those without (about 58.5 thousand, 66 thousand, 73 thousand, 79.5 thousand and 85.5 thousand); the flag neither removed the movement nor added a new value.
- That the tap does not change the figure is shown on the verification app, whose data are deterministic: the cold rows examined with and without the flag were equal on every route, in the tests of commit `e944520`. For BookStack the evidence is `unaccounted` equal to 0, not a match with an untapped run.
- The costs are for 10,000 rows. They were not measured at 100,000.

## 4. T1: the six routes on two databases, three laps

Design (from section 2): 100,000 rows; two databases built separately; on each, the six routes in the order of section 2.1, three laps; 36 trials, each with the per-statement tap; the stored statistics and the content of each database recorded. The run took 99 minutes and ended with exit code 0 on both databases; all 36 trials have status 302 and class `ok`. The watcher raised no alert. The raw records are outside the repository; the figures below are taken from the aggregate tables made from the 36 tap files, and the sums and ranges in 4.2 and 4.3 were recomputed from the tap files themselves.

**Conclusion in one line (details in 4.7): all six routes change value inside one database, by the tool's rule (a gap of more than 5%).** The next sections give the table that shows it, the statement that moves, and what the records say and do not say about why.

### 4.1 Cold rows examined per route, database and lap

The rule of the table tool: two values differ when their relative gap is more than 5%. "Inside" means the three cold values of one database differ by that rule; "across" means the two databases differ while neither varies inside itself. Class (a): at least one database varies inside itself; (b): only across databases; (c): neither.

| Route | db1 lap 1 | db1 lap 2 | db1 lap 3 | db2 lap 1 | db2 lap 2 | db2 lap 3 | Inside db1 | Inside db2 | Across | Class |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | --- | --- | --- | --- |
| `DELETE /books/{bookSlug}/chapter/{chapterSlug}` | 520,510 | 775,487 | 850,461 | 520,510 | 520,458 | 775,487 | yes | yes | no | a |
| `DELETE /books/{bookSlug}/draft/{pageId}` | 520,454 | 850,455 | 520,454 | 520,454 | 850,515 | 850,455 | yes | yes | no | a |
| `DELETE /books/{bookSlug}/page/{pageSlug}` | 775,458 | 595,461 | 520,459 | 520,459 | 850,460 | 520,459 | yes | yes | no | a |
| `DELETE /books/{id}` | 520,500 | 850,475 | 520,500 | 850,475 | 850,535 | 775,501 | yes | yes | no | a |
| `DELETE /shelves/{slug}` | 595,454 | 850,456 | 520,451 | 850,456 | 775,482 | 850,516 | yes | yes | no | a |
| `POST /settings/recycle-bin/empty` | 520,438 | 520,467 | 520,438 | 595,440 | 520,467 | 520,467 | no | yes | no | a |

- Tally: class (a) for 6 routes, class (b) for none, class (c) for none. Inside a database, the value varies in 11 of the 12 pairs of (route, database); the exception is `POST /settings/recycle-bin/empty` on db1 (520,438, 520,467, 520,438).
- The number of queries is the same in every trial of a route (200,103; 200,101; 200,102; 200,107; 200,100; 200,096 for the six routes in the table's order). The value that moves is the rows read, not the number of statements.
- The values fall in four groups (levels), which both databases show:

| Level | Cold rows examined (smallest to largest) | Trials | In db1 | In db2 |
| --- | --- | ---: | ---: | ---: |
| L1 | 520,438 to 520,510 | 17 | 10 | 7 |
| L2 | 595,440 to 595,461 | 3 | 2 | 1 |
| L3 | 775,458 to 775,501 | 5 | 2 | 3 |
| L4 | 850,455 to 850,535 | 11 | 4 | 7 |

Records: `T1_tables.md` section 1 and B1 (the table), `T1_values.tsv` (the values; the two agree).

### 4.2 The statement that moves

The same statement is the one that moves in every route:

```
delete from deletions where deletable_type = ? and deletable_id = ? and …
```

(identifier quoting left out). It runs 5 times in each request. Its total rows read is, by level, about 120 thousand (L1: 120,001 to 120,003), 195 thousand (L2: 195,003 to 195,005), 375 thousand (L3: 375,001 to 375,004) and 450 thousand (L4: 450,003 to 450,007).

**What is left is the same in every trial.** Take out this statement, the `delete from joint_permissions …` statement and the `select * from webhooks where exists (…)` statement. What remains of the cold total, summed over all other statements, is the same in all six trials of each route (recomputed from the 36 tap files):

| Route | Rows read by all other statements, in every trial |
| --- | ---: |
| `DELETE /books/{bookSlug}/chapter/{chapterSlug}` | 400,356 |
| `DELETE /books/{bookSlug}/draft/{pageId}` | 400,351 |
| `DELETE /books/{bookSlug}/page/{pageSlug}` | 400,357 |
| `DELETE /books/{id}` | 400,369 |
| `DELETE /shelves/{slug}` | 400,349 |
| `POST /settings/recycle-bin/empty` | 400,337 |

The two statements taken out move a little: the `joint_permissions` delete read 90, 119 or 150 rows over its 5 runs in the different trials, and the `webhooks` select read 2 or 10 rows (it moved in the chapter route only). Their effect is at most 68 rows. The steps between levels are therefore the steps of the `deletions` delete: the gap between two levels of one route equals the gap of that statement (for instance L1 to L4 in the draft route, a gap of 330,001 in the total and of 330,001 in the statement; section B2d of the same tables lists all steps; the other-statements part of a step is 0 or 29 rows).

Records: `T1_tables.md` B2a to B2f (and the sums over the tap files, which agree with them).

### 4.3 `EXPLAIN`, and what the per-run minimum and maximum show

**`EXPLAIN` does not follow the level.** The `EXPLAIN` of the `deletions` delete is taken right after the cold window, for the first and the last binding (section 2.3), so each trial has two. Over the 36 trials (72 plans) it shows a range read on `deletions_deletable_type_index` in 56 and a full scan (type `ALL`, no key) in 16. The full scan appears at L1, the level with the least rows read (4 trials), and at L4, the level with the most (4 trials); L2 and L3 show only ranges. The first and the last binding gave the same plan in every trial. The row estimate of `EXPLAIN` is 1,246 to 2,969 for the `ALL` plans and 1 to 328 for the range plans; these are estimates, not rows read. So the plan that `EXPLAIN` shows after the window does not tell the levels apart, and it cannot show whether the 5 runs inside one request used one plan or several.

**What the numbers say.** The tap keeps, per statement text, the number of runs, the sum, the smallest and the largest rows read per run, not each run. For the `deletions` delete (5 runs), the smallest and the largest per run are:

| Level | Sum of the 5 runs | Smallest run | Largest run | The 3 runs between (sum) |
| --- | ---: | ---: | ---: | ---: |
| L1 | about 120 thousand | 20,001 to 20,002 | 25,001 to 25,002 | 74,999 to 75,000 |
| L2 | about 195 thousand | 20,001 | 100,003 to 100,004 | 74,999 to 75,000 |
| L3 | about 375 thousand | 25,001 to 25,002 | 95,003 to 95,004 | 254,996 to 254,999 |
| L4 | about 450 thousand | 79,990 to 79,991 | 100,004 | 270,009 to 270,012 |

One reading of these numbers, offered as a reading and not as a finding. A run that reads 20 to 25 thousand rows reads about a fifth to a quarter of the table, the size one would expect of a read of part of an index; a run that reads about 100 thousand rows reads the size of the whole table (100,000 rows), the size of a scan. At L1, all 5 runs are 20 to 25 thousand; at L4, all 5 runs are between about 80 and 100 thousand; at L2, one run is table-sized (about 100 thousand) and the other four sum to about 95 thousand. At L3 the smallest run is range-sized and the largest is near table-sized (95 thousand). So the form of the numbers is that, inside one request, among the 5 runs of this statement, some read a part of the table and some read about all of it. This file does not say why the 5 runs differ, and the record does not show it: the sizes are read from sums, minima and maxima, and the plan of each run was not recorded.

Records: `T1_tables.md` section 3 (plans that differ between trials), B2b and B2c (`EXPLAIN` by level); the per-run minima and maxima are `rows_min` and `rows_max` of the statement in each `tap.jsonl`.

### 4.4 Stored statistics around each trial

The stored statistics of InnoDB (section 2.4) were read before and after each trial. `deletions`, the table grown to 100,000 rows, had a stored `n_rows` of 0 right after the build. The other tables are small (122 rows at most).

- **`n_rows` of `deletions` is not the true count and it moves between trials.** The values read after the 36 trials are 0, 1,198, 1,714, 3,728, 3,729, 3,745, 3,746, 91,391 and 99,900; none is 100,000, the number of rows the table was grown to. In the first lap of db1 the stored value went 0, 3,729, 91,391, 3,746, 99,900, and after the recycle-bin trial 0 again. The cardinality of the index `deletions_deletable_type_index` read 1, 3 or 0 in the same records (section B3).
- **The time of the last recalculation is inside the trial in all 36 trials.** `last_update` of `deletions` after a trial is inside the trial's window (the start to the end of the trial) in 36 of 36. It is 0 to 11 seconds before the end of the trial. A trial is two requests, a warm-up and the cold one, so the window is not the cold request alone: whether a recalculation came before the cold request or during it is not known from the record.
- **Small tables are also recalculated during trials.** In every trial the `last_update` of 17 to 28 of the 40 small tables moved, always inside the window; their stored `n_rows` changed for 0 to 27 of them (for example `activities` 0 to 6 and `entities` 5 to 0 in the first trial of db1).

What this does not say. The times of recalculation and the trials overlap; the file does not say that the recalculation changed the plan, and it does not say that the two are unrelated. The record has no trace of the plan used at the moment of each run (section 2.3), so the two cannot be tied by this data. The `ANALYZE TABLE` stage (4.6) was designed to look at this; it did not settle it, because the statistics were recalculated again inside its trials.

Records: `T1_tables.md` section 4 and B3; `stats_before.tsv` and `stats_after.tsv` of each trial; `trial.json` for the window.

### 4.5 The two databases, and the checks of the record

**The two databases are not identical. The routes read one of the three tables that differ (`role_permissions`), and `roles`, which can differ in the same way, but not through the columns that differ, so the differences fall in the constant remainder of 4.2.** The row counts of the 41 tables are equal in the two databases. The content digest of the two databases is different. Three tables differ in checksum (`role_permissions`, `settings`, `users`); the other 38 are equal. The saved row counts right after the build differ in no table.

| Table | What differs between two builds of the same recipe |
| --- | --- |
| `settings` | only the value of the key `instance-id` (a 36-character identifier); the 5 other keys are equal |
| `users` | only the `password` column (7 rows); every other column is equal |
| `role_permissions` | only `created_at` and `updated_at`, which hold the clock time of the build; equal for two builds in the same second, different for a build 40 seconds later (`roles` differs in the same way in that case) |

**Why the differences do not reach the rows read.** In the cold window of the 36 trials, two statements name these tables, one each per request, and they read the same rows in every trial of both databases:

- `select distinct role_permissions.name … from role_user … left join permission_role … left join role_permissions … where ru.user_id = ?` reads 190 rows. It returns only `name`; its joins and its condition use ids and `user_id`, not `created_at` or `updated_at`.
- `select roles.*, … from roles inner join role_user on roles.id = role_user.role_id where role_user.user_id = ?` reads 3 rows (chapter, draft, page, `DELETE /books/{id}` and shelves routes; the recycle-bin route has no such statement). It returns every column of `roles`, so the creation times are returned, but they are in no condition or join, so they cannot change how many rows are read.

No statement in the cold window names `settings` or `users`, so the instance id and the password column are not read in the cold request of these six routes. (The tap sees only the cold request, not the warm-up request.) These two statements are part of what 4.2 calls the other statements, whose total is the same in all six trials of each route (400,337 to 400,369 by route), across both databases. That is the check that the differences between the databases did not move the rows read. It is a reading of the statements and the sums; the columns were not compared on the measured databases (next paragraph).

**This was not read from the measured databases.** They were torn down when the run ended. The difference was checked on two new databases of the same recipe, built at 10,000 rows, and compared read-only. The measured databases of 100,000 rows themselves were not compared column by column; only their digests and checksums (which they have) say that three tables differ.

**Checks of the record.** In all 36 trials: `unaccounted` is 0 and `unread_events` is 0 in the tap, and the sum of the rows read by the statements equals the cold rows examined. One request takes about 165 seconds (a trial of two requests takes 324 to 334 seconds). The tap about doubles the time of a cold request in the calibration of section 3 (an estimate), and time is not used in any reading here (D97, note).

Records: `T1_tables.md` section 5, B2f and B4; the notes of the runner (checks of the run, the three tables).

### 4.6 `ANALYZE TABLE` before the measurement (stage 3)

Stage 3 is the stage of section 2.4 (`--analyze=before`): one database built, `ANALYZE TABLE` run once on all 41 tables right after the build and before the first trial (all 41 returned status OK), then the six routes in the order of section 2.1, three laps: 18 trials, one database. Stage 2 (sections 4.1 to 4.5) is the same design without `ANALYZE TABLE`, on two databases and 36 trials. The 18 trials have status 302 and class `ok`; in all 18, `unaccounted` and `unread_events` are 0 and the sum of the statements equals the cold total. The measurer was the copy of `1d820f7`, as in stage 2.

**Cold rows examined, stage 3.** Same rule as 4.1: two values differ when the gap is more than 5%.

| Route | Lap 1 | Lap 2 | Lap 3 | Varies inside the database |
| --- | ---: | ---: | ---: | --- |
| `DELETE /books/{bookSlug}/chapter/{chapterSlug}` | 520,518 | 520,458 | 520,458 | no |
| `DELETE /books/{bookSlug}/draft/{pageId}` | 595,456 | 520,454 | 520,454 | yes |
| `DELETE /books/{bookSlug}/page/{pageSlug}` | 595,461 | 520,459 | 520,459 | yes |
| `DELETE /books/{id}` | 595,503 | 520,500 | 520,500 | yes |
| `DELETE /shelves/{slug}` | 595,454 | 595,454 | 520,451 | yes |
| `POST /settings/recycle-bin/empty` | 775,466 | 775,466 | 775,466 | no |

- **After `ANALYZE TABLE`, 4 of the 6 routes still change value inside the same database** (draft, page, `DELETE /books/{id}`, shelves). `DELETE /books/{bookSlug}/chapter/{chapterSlug}` and the recycle-bin route took the same level in all three laps. This must be read together with the next fact: after `ANALYZE TABLE` the stored statistics were recalculated again during the trials (see "Stored statistics in stage 3" and the limit below), so stage 3 is not a measurement on fixed statistics.
- **The level of about 850 thousand (L4) did not appear in stage 3.** The values are at about 520 thousand (L1), 595 thousand (L2) and, for the recycle-bin route, 775 thousand (L3). The recycle-bin route read about 775 thousand in all three laps; in stage 2 it took 520,438 to 595,440 and never a value at the L3 level. The number of queries per trial is as in stage 2 (200,096 to 200,107).
- The number of trials is half that of stage 2 and there is one database, so the levels not seen (for example L4) may be absent for lack of trials. This file does not count the absence of L4 as an effect of `ANALYZE TABLE`.

**The form of the numbers is the one seen in stage 2.** For the `deletions` delete (5 runs per request), the smallest and the largest rows read by one run, and what the 3 runs between read together (`T1a_tables.md` section 2):

| Cold total | Smallest run | Largest run | The 3 runs between (sum) | Reading |
| --- | ---: | ---: | ---: | --- |
| about 520 thousand | 20,001 to 20,002 | 25,001 to 25,002 | 74,999 to 75,000 | all 5 runs range-sized |
| about 595 thousand | 20,001 to 20,002 | 100,004 | 74,999 to 75,000 | one run about the size of the table |
| about 775 thousand (recycle-bin) | 25,001 | 95,003 | 254,996 | mixed, as the L3 trials of stage 2 |

Per statement, the axis statement is again `delete from deletions where deletable_type = ? and deletable_id = ? and …` (the same statement id as in stage 2), and its sum over the 5 runs is 120,002 to 120,003, 195,004 to 195,005 or 375,000 depending on the level. As in 4.3, this is a reading of sums, minima and maxima from the numbers; the plan of each run was not recorded.

**Stored statistics in stage 3** (`T1a_tables.md` sections 4 and 5; `deletions`, the table grown to 100,000 rows):

| Point | `n_rows` | Cardinality of `deletions_deletable_type_index` |
| --- | ---: | ---: |
| After the build, and before `ANALYZE TABLE` | 0 | 1 |
| Right after `ANALYZE TABLE` | 99,900 | 3 |

- `ANALYZE TABLE` moved the stored `n_rows` of `deletions` from 0 to 99,900. 99,900 is close to, but not equal to, the number of rows (100,000). The saved row counts of most small tables moved too (30 of 40 differ between the points).
- **The statistics were recalculated again inside the first trial.** Inside the first trial (chapter, lap 1) `n_rows` of `deletions` went from 99,900 to 37,429. After the trials it read 37,429 (trial 1), 99,900 (trial 2, the draft route in lap 1), then 0 (trial 3) and, from trial 4 on, values between 0 and 3,746 (0, 3,728, 3,729, 3,745 or 3,746). From the second lap on the values stay between 0 and 3,746. So the large stored count that `ANALYZE TABLE` produced did not last past the first two trials.
- **All 18 recalculations of `deletions` are inside a trial window.** `last_update` of `deletions` after a trial is inside the trial's window in 18 of 18; in 17 of the 18 trials 17 of the 40 small tables were refreshed inside the window as well (30 of 40 in the first trial). As in 4.4, the window holds the warm-up and the cold request, so it is not known whether a recalculation came before the cold request or during it.

**Limit: stage 3 is not a measurement with fixed statistics.** The aim of the stage was to measure on a database whose statistics had been computed. `ANALYZE TABLE` did compute them, but InnoDB recalculated them on its own inside the first trial and inside every later one, so the statistics were not the same from trial to trial, and the trials were not run on the statistics `ANALYZE TABLE` had left. Two things follow, and neither is concluded:

1. That a movement remained after `ANALYZE TABLE` does not show that the stored statistics have no part in it.
2. That the movement is smaller or different in shape (no L4; the recycle-bin route flat) does not show that the stored statistics are its cause.

The data can say that the movement was there with the statistics recalculated inside every trial; it cannot say what it would be if the statistics were held fixed.

Records: `T1a_tables.md` sections 1 to 5 (the table of values, the smallest and largest run per trial, the statistics before and after `ANALYZE TABLE` and during each trial); `T1a_values.tsv` (the values); `analyze.log`, `stats_after_analyze.tsv`, and per trial `tap.jsonl`, `stats_before.tsv`, `stats_after.tsv`, `trial.json`.

### 4.7 Conclusion: (a) a change inside one database

By the rule of the tool (a gap of more than 5% inside one database), **all six routes change value inside one database: class (a) for every route** (4.1). On the same rows (the data are rolled back and their counts checked, section 2.2), with the same arguments, the cold rows examined of a route took values at four levels (about 520 thousand, 595 thousand, 775 thousand and 850 thousand), and both databases show all four. 11 of the 12 pairs of (route, database) vary inside; the twelfth (recycle-bin, db1) is flat. No route is only different between the two databases, and none is flat on both.

What moves is one statement in every route, the `delete from deletions where deletable_type …` (5 runs per request); the rest of the request reads the same rows in every trial (4.2). Its rows read, by level, are about 120, 195, 375 and 450 thousand. The smallest and the largest run of the 5 give a form that can be read from the numbers: runs of about a fifth to a quarter of the table and runs of about the whole table inside one request (4.3). `EXPLAIN` after the window shows both a range and a full scan at the lowest and the highest level, so it does not tell the levels apart. The stored statistics of `deletions` are not the true count and are recalculated inside the trials (4.4); the times overlap with the trials.

What is not concluded: why the 5 runs of one request differ in what they read; whether the stored statistics have a part in it; whether `ANALYZE TABLE` changes the picture (4.6: the movement remained in 4 of 6 routes and the highest level did not appear, but the statistics were recalculated inside every trial, so neither "unrelated" nor "the cause" can be said). The change of plan inside a request is a reading of sums, minima and maxima, not an observation of the plans.

## 5. T2: the two `PUT` routes at 10,000 and 100,000 rows

Question: `PUT /books/{bookSlug}/chapter/{chapterSlug}` and `PUT /books/{bookSlug}/page/{pageSlug}` read 3,730 and 3,755 rows at 10,000 and 115,232 and 115,257 rows at 100,000, while test A predicted 34,480.9 and 34,505.9. Which statements changed, and did their plan change?

**What was run.** `variance-run` from the copy of `1d820f7`, one database per scale (10,000 and 100,000), one lap, the tap on, no `ANALYZE`. The two routes were run in that order. The arguments are those of the earlier runs of test A (10,000: step 1; 100,000: step 5). Both runs ended with exit code 0, each trial had 2 successful requests, and the watcher raised no alert (the longest statement was 0 s). The records are outside the repository: per run, `run.log`, `index.tsv`, the build record, `content.tsv`, and per trial `attempt.jsonl`, `tap.jsonl`, `trial.json`, `stats_before.tsv`, `stats_after.tsv`. The tables below were made from the four `tap.jsonl` files.

Two things about the inputs:

1. In the recorded `tasks.json` of both earlier runs, these two routes carry an empty body, and a request with an empty body is refused with HTTP 422 (`The name field is required`; 1 query, 1 row read). A first pair of runs with the original files recorded only that (no successful attempt, no tap) and was set aside, unused. The runs reported here use copies of the two files in which only these two routes carry the body `{"name": "x_1"}`; the original files were not touched. With this body, the figures reproduce those of test A exactly (3,730 and 3,755 at 10,000; 115,232 and 115,257 at 100,000). That is the check that the request is the same one.
2. Only the `references` table was filled to the scale (10,000 and 100,000 rows); the other large tables hold 5 rows. This is the scale axis of `references` alone.

**Result.** Cold trial of each route; the sum of the rows read by the statements equals the cold rows examined.

| Route | Rows read at 10,000 | Rows read at 100,000 | Queries at 10,000 | Queries at 100,000 |
| --- | ---: | ---: | ---: | ---: |
| `PUT /books/{bookSlug}/chapter/{chapterSlug}` | 3,730 | 115,232 | 20 | 20 |
| `PUT /books/{bookSlug}/page/{pageSlug}` | 3,755 | 115,257 | 29 | 29 |

Every statement ran the same number of times at both scales. Only two statements read a different number of rows, and both are on `references` (identifier quoting left out):

| Statement | Rows read at 10,000 | Rows read at 100,000 | Plan at 10,000 | Plan at 100,000 |
| --- | ---: | ---: | --- | --- |
| `delete from references where from_type = ? and from_id in (?)` | 2,001 | 100,003 | `range`, key `references_from_id_index` | `ALL`, no key |
| `select * from references where to_type = ? and to_id = ? and (…)` | 1,501 | 15,001 | `ref`, key `references_to_id_index` | `ref`, key `references_to_id_index` |

- **The plan changed for one statement, the `delete`**: from a range on an index to a full scan of the table (type `ALL`, no key). The scan read 100,003 rows. This is the same in both routes, and for the first and the last binding of the statement.
- **The `select` kept its plan.** Its rows read grew tenfold with the table (1,501 to 15,001).
- The other statements (17 in the chapter route, 27 in the page route) read the same rows at both scales (at most 190 each), and none changed plan.
- The extra 111,502 rows in each route are the `delete` (+98,002) and the `select` (+13,500); the rest is 0. For the chapter route, 3,730 + 98,002 + 13,500 = 115,232; the page route has the same two differences (3,755 to 115,257).

The row estimates of `EXPLAIN` at 100,000 are about 90,000 (chapter route) and 80,000 (page route) for the `delete`, and 34,704 for the `select`, against 2,000 for both statements at 10,000. They are estimates, and they differ between the two routes for the same statement; the rows actually read (100,003) are the figure to use.

**Stored statistics at the time.** The first trial's `stats_before.tsv` shows the same stored statistics for `references` at both scales: `n_rows` 0, cluster index size 1, other indexes size 4, and every index cardinality 1 (the values stored at table creation, before the fill). They did not change during the trials (`stats_after.tsv`). The stored statistics therefore do not tell the two scales apart, yet the plan of the `delete` differs. This is recorded as a fact; it does not say why the optimizer chose as it did.

**Limits of this reading.**

- The `EXPLAIN` is taken after the request window closes (section 2.3). The agreement between the plan (`ALL`) and the rows read (100,003, about the size of the table) supports that the scan happened; the `EXPLAIN` alone would not prove it.
- One lap and one database per scale, no repeat. T2 identifies the changed statement; it does not measure run-to-run variation (that is T1).
- Two scales only, so the point where the plan changes is not located.
- Only `references` was scaled.

## 6. T3: the twelve routes with no empty-system point

Test A lists twelve BookStack routes whose request at scale 0 returned `not_found`, so no formula could be made for them (`extrapolation-test.md` section 4.2): `DELETE /import/{id}`, `DELETE /settings/sorting/rules/{id}`, `GET /books/{bookSlug}/chapter/{chapterSlug}`, `GET /books/{bookSlug}/copy`, `GET /books/{bookSlug}/export/zip`, `GET /books/{bookSlug}/page/{pageSlug}`, `GET /books/{slug}`, `GET /settings/sorting/rules/{id}`, `GET /shelves/{slug}`, `POST /books/{bookSlug}/draft/{pageId}`, `PUT /books/{slug}`, `PUT /settings/sorting/rules/{id}`. This part checks whether the cause is that a route that shows or updates one record cannot find it in an empty system, and sets out options. Reading only: nothing was measured and nothing in the repository was changed. The records are those of the step-1 run of test A (`s1b`: `attempts.jsonl`, `tasks.json`, `routes.jsonl`), outside the repository; code lines are those of commit `c492cd8`.

### 6.1 Is the cause "the record is not there at scale 0"?

**Yes for all 12, with one refinement: the record is missing only when the table the route grows (its `n_table`) is the table that holds the record.** The links:

1. **The request at scale 0 was answered "not found" on all 12.** The scale-0 row of every one of them has class `not_found` and status 404 (`POST /books/{bookSlug}/draft/{pageId}` and `PUT /settings/sorting/rules/{id}` first got a 422 for a missing body; the retry with a body got the 404). For the routes on a sort rule or an import, the application names the record: `No query results for model [BookStack\Sorting\SortRule] 1` and `No query results for model [BookStack\Exports\Import] 1`. The other nine give a 404 with no message.
2. **The table that holds the record was read and gave no row.** At scale 0 the table is in the attempt's `tables` and absent from its `rows_read`:

   | Routes | Table of the record | Rows read from it at scale 100 (attempt 2) |
   | --- | --- | --- |
   | `DELETE /import/{id}` | `imports` | 1 |
   | `DELETE`, `GET`, `PUT /settings/sorting/rules/{id}` | `sort_rules` | 1 |
   | `GET /books/{bookSlug}/chapter/{chapterSlug}`, `GET /books/{bookSlug}/page/{pageSlug}`, `GET /books/{slug}`, `GET /shelves/{slug}`, `GET /books/{bookSlug}/copy`, `GET /books/{bookSlug}/export/zip`, `PUT /books/{slug}`, `POST /books/{bookSlug}/draft/{pageId}` | `entities` | 62, 63, 153, 3, 1, 61, 108, 10 (in the order of the routes of this row) |

   What the scale-0 request did read is only the tables that every request reads: `permission_role` 62, `role_permissions` 62, `role_user` 62 or 63, `settings` 6, `roles` 1, and `entity_permissions` 1 for the routes that look an entity up.
3. **The measurer does not look for the record; it writes the value in the address.** The 12 routes have parameters with no model binding (`model: null` in `tasks.json`), so the resolver reads no row. It takes the value from the application profile, which states a literal (`poc/runtime/profiles/bookstack.php`, lines 16 to 26: `id` = 1, `slug` = '1', `bookSlug` = '1', `chapterSlug` = '2', `pageSlug` = '3', `pageId` = 4), reached through `declared()` in `poc/runtime/src/Route/ArgumentResolver.php`. The value is used whether or not the row exists (for example `/books/1/chapter/2`, `/books/1/page/3`, `/shelves/1`, `/import/1`, `/settings/sorting/rules/1`).
4. **At scale 0 the data plan makes no such record.** The plan for these routes is the targeted plan of `n_table` (`entities` for the eight entity routes, `imports` for `DELETE /import/{id}`, `sort_rules` for the three sorting-rule routes). At N = 0 the `n_table` is empty (`poc/runtime/src/Data/ScalePlan.php`, lines 13 to 17), and the scale list is `[0, ...scales]` (`poc/runtime/src/Measure/GeneratorPlanner.php`, line 60). The profile's fix-ups that give a row the slug and the type a route looks for are `UPDATE` statements over rows that exist (`bookstack.php`, lines 33 and 38), and the `INSERT … SELECT` fix-ups read `entities` (lines 63 and 83); with `entities` empty they change or write nothing for the entity routes.
5. **What separates these 12 from routes that address a record and were measured at scale 0.** `DELETE /books/{id}`, `DELETE /shelves/{slug}`, `DELETE /books/{bookSlug}/chapter/{chapterSlug}`, `DELETE /books/{bookSlug}/page/{pageSlug}` and `GET /books/{bookSlug}/draft/{pageId}` use the same literals and returned `ok` at scale 0 (for example `DELETE /books/{id}`: status 302, 18 queries, `rows_read.entities` = 5). Their `n_table` is another table (`deletions`, or `page_revisions` for the draft route), so `entities` keeps the base size of 5 rows (`GeneratorPlanner::BASE = 5`) and the fix-ups give those five rows the slugs '1', '2', '3' and a draft with id 4. The cause is therefore more exactly: the record the address names lives in the table the plan empties at N = 0.

**Two things that sharpen it.**

- Five of the 12 do not grow in the measured range. Cold request at 100, 1,000 and 10,000: `DELETE /import/{id}` 5 queries and 194 rows examined at all three scales; `DELETE /settings/sorting/rules/{id}` 6 and 197; `GET /settings/sorting/rules/{id}` 4 and 196; `PUT /settings/sorting/rules/{id}` 2 and 191; `GET /books/{bookSlug}/copy` 4 and 198. The seven others grow (rows examined at 100 / 1,000 / 10,000): chapter 467 / 2,442 / 42,240; page 467 / 2,442 / 42,240; `GET /books/{slug}` 814 / 4,455 / 78,454; shelves 427 / 2,227 / 20,227; `export/zip` 533 / 3,548 / 53,696 (queries 124 / 1,204 / 12,004); `POST` draft 709 / 2,149 / 32,540; `PUT /books/{slug}` 1,475 / 10,379 / 127,725 (queries 246 / 2,361 / 23,511).
- `POST /books/{bookSlug}/copy`, which test A lists apart, has the same scale-0 cause (404, 9 queries, 212 rows examined). At 10,000 it was stopped after the 600-second limit (class `exception`, 344,125 queries and 264,195,251 rows examined at the stop), a second reason for being out that a smaller scale would not remove.

**Where the rule that leaves them out is.** `CostFit::quantity` (`poc/runtime/src/Judge/CostFit.php`, lines 129 to 139) returns `insufficient_scales` when any scale the run measured is missing for the route, and the empty system counts as one of them. The routes have three measured scales (100, 1,000, 10,000), so the other rule, a minimum of three (`MIN_SCALES`), is not what stops them.

**Cause not observed directly.** No one looked at the scale-0 database itself; no row count of `entities` at N = 0 was taken. The reading rests on the records of the request (tables queried, rows read) and on the code that builds the database. It is consistent with all 12 and with the five counter-examples in item 5; it is not a measurement of the table.

### 6.2 Options

Whatever is chosen, the order of test A does not have to change. Each option changes only what is measured in step 1 or what the data hold at the small scale. The prediction at 200,000 is written from the step-1 records and committed before 200,000 is measured (D104), as at 100,000 (`extrapolation-test.md` section 1). None of the options needs a measurement at 200,000 in step 1.

**Option A. A smallest non-zero scale for these routes only, with `a` free.** The 12 routes (or the 7 that grow) are measured at N = 10 instead of N = 0, and at 100, 1,000 and 10,000, and the formula is fitted on those four points and evaluated at 200,000. The empty-system point stays for every other route. At N = 10 every record the routes address exists: ids 1 to 10 of `entities` give a book (1, 6), a chapter (2, 7), pages (3, 4, 8, 9) and shelves (5, 10) under the fix-up at `bookstack.php` line 33; `imports` and `sort_rules` need row 1. (N = 5, the base size, is enough for the five routes of item 5 by the same fix-up; that is not a test of these 12 routes.)

- With the order of the test: compatible. Step 1 measures N = 10 with the other scales; step 3 predicts 200,000; step 4 commits; step 5 measures 200,000.
- Rules it touches: the scale list of a route is no longer the list of the run (the rule at `CostFit.php` lines 129 to 139), and "the empty system pins `a`" (D79) does not hold for them: `a` is only free. D81 (the pairs exclude scale 0) needs a line on what the smallest scale is. D95 (the denominator): the 12 routes would enter the denominators of the test run at 200,000. `PUT /settings/sorting/rules/{id}` has a static lower bound O(N) for queries and a flat query count (3, 2, 2), so by D80 and D84 it would be set aside for queries and stay in rows examined. **A ruling by the project owner is needed**, including whether the 80% line applies to the enlarged denominator or only to the old routes.
- Existing results: no effect on D103. The new small-scale points are new measurements, and the 200,000 test is a new test (D104). The committed fits of the 23 routes stay as they are.
- For: the meaning of scale 0 and every committed fit of the other routes stay unchanged; the change is limited to a list of 12 routes; the pass line is unchanged.
- Against: "all routes are measured at the same scales" is given up for a group. `a` is less well determined than with the empty system (the committed fits use an unweighted sum of squares, which `extrapolation-test.md` section 6 says is dominated by the 10,000-row point), and a route whose cost jumps between 10 and 100 rows is not seen. It has not been checked that the 12 routes return `ok` at N = 10 (shown only for the five routes of item 5, at 5 rows).
- Effort (estimate, not measured): a short list of routes and a per-route scale list in the runner; one run of step 1 on 12 routes (the small scales cost seconds; 10,000 costs up to the figures above, for instance 23,511 queries for `PUT /books/{slug}`); a smoke run at N = 10. Measuring these routes at 200,000 is the long part: `PUT /books/{slug}` made 23,511 queries and `GET /books/{bookSlug}/export/zip` 12,004 at 10,000, and `POST /books/{bookSlug}/copy` was stopped at 600 seconds at 10,000. Some may end as "formula but not measured" at 200,000, which counts toward the 20% limit for "undecidable" (D95; `extrapolation-test.md` section 0).

**Option B. Keep scale 0 for everyone, and put the addressed record into the empty system.** The profile gets a fix-up that, when `entities` has no row, writes the few rows the routes address (one book, one chapter, two pages with the second a draft, one shelf; one `imports` row; one `sort_rules` row), so that scale 0 means "empty except for the records the routes name". The 12 routes then return `ok` at 0 and are fitted with the same four scales as the rest.

- With the order of the test: compatible, but step 1 has to be run again for every route whose scale-0 point changes. The empty-system database is shared by the routes of one plan shape (the tag is made from the n tables and the m table, `GeneratorPlanner.php` lines 48 to 63), so other routes whose plan empties `entities` would get a different scale-0 point too. Their fits and predictions are made anew before 200,000 is measured.
- Rules it touches: the meaning of scale 0 (D79: the empty system pins `a`; with a few rows, `a` includes the cost of those rows), and D95 as in A. The data profile is a decision delegated under D72 (the precedent is D100), but it changes the input of every committed fit of the routes sharing that database. **A ruling by the project owner is needed.**
- Existing results: D103 is not touched; nothing is scored again. The committed fits and predictions of 100,000 stay in the record, but no longer match the scale-0 point of the routes whose plan empties `entities`. In the committed fits (`fits.json`, field `table`), two of the 23 routes of the rows-examined denominator are on `entities`: `GET /images/edit/{id}` (k = 0) and `POST /settings/maintenance/regenerate-references` (k = 1.68, one of the eight misses). Whether routes whose `n_table` is `references` also have `entities` empty at N = 0 depends on the plan's m table and was not read, so the number of affected fits could be larger.
- For: one scale list for all; no per-route exception in the code; the 12 routes are tested exactly like the others.
- Against: it changes the meaning of the zero point for every route on that database; the rows written at 0 are the first thing the fix-ups would have to protect (`NOT EXISTS`, so that databases of N ≥ 100 are unchanged, as for `login_is_admin` at `bookstack.php` line 43); "the empty system" is no longer empty; the scale-0 requests of the other routes in that group have to be measured again, and their committed fits are no longer the ones the new test starts from.
- Effort (estimate): one fix-up, a re-run of step 1 for the routes on `entities` (10,000 dominates; the whole step 1 took 1,791 s at the last run), and the same 200,000 cost as A.

**Option C. Fit the three scales that exist (100, 1,000, 10,000), with no empty-system or small point.** For these routes the formula is fitted on three points with `a`, `b` and `k` free.

- With the order of the test: compatible.
- Rules it touches: `CostFit.php` lines 129 to 139 and D79; the fit has as many free numbers as points, so no residual is left to show a bad form. D95 as in A. **A ruling by the project owner is needed.**
- Existing results: not affected (D103).
- For: no new measurement before the prediction; nothing in the data changes.
- Against: three parameters on three points always fit exactly (the sum of squares is 0), so the fit cannot disagree with its own data. The prediction at 200,000 still tests the form, but the formula carries no check of its own. For the five flat routes this changes nothing (a constant); for the seven that grow, `k` is read off two tenfold steps.
- Effort: the least; the cost at 200,000 is as in A.

**Option D. Leave the 12 out, and say so as a limit.** The 12 stay in `insufficient_scales` as they are now, and are listed again for the 200,000 test as routes that address one record in the grown table, which the test cannot reach by its own form. No code or data change.

- With the order of the test: nothing to reconcile.
- Rules it touches: none. D79, D81, D95 and the pass line are unchanged; the denominators for 200,000 stay at the routes with an empty-system point. **No ruling is needed.**
- Existing results: not affected.
- For: no cost and no new rule. It states the limit already written in `extrapolation-test.md` section 6 (the 12 routes are out because they could not be measured).
- Against: the test says nothing about the routes whose cost grows fastest in this application (`PUT /books/{slug}` with 23,511 queries at 10,000; `GET /books/{bookSlug}/export/zip` with 12,004). 12 of the 63 routes in the step-1 set stay out, and the share of the application that the test covers does not grow.

**Variant for A and B: reported only.** The 12 routes are predicted and compared at 200,000 but are not counted in the denominator of the pass line (as peak memory is reported and does not decide, D95). The denominator, and so the 80% line, stay as they are. It still needs the ruling on the scale list (A) or on the meaning of scale 0 (B), and it gives the owner the result without a change to what "pass" means. Whether a group that is predicted and compared but does not count is acceptable is for the owner.

**What needs the owner.** A, B and C each need a ruling on the scale list of a route or on the meaning of scale 0 (D79, D81 and the rule at `CostFit.php` lines 129 to 139), and on whether the 12 routes enter the denominator (D95). D does not. This file does not choose.

**What was not done in T3.** No measurement, no database built, no `EXPLAIN` taken, no file in the repository changed. The estimates for 200,000 are linear extrapolations of records at 10,000, marked as such.

## 7. Limits and open items

- **T1.** The conclusion (section 4.7) is that the six routes change value inside one database; it does not say why the runs of the moving statement inside one request read different numbers of rows, and it does not say whether the stored statistics have a part in it. The split of the 5 runs into part-table and whole-table reads is read from sums, minima and maxima: the tap keeps no figure per run, and the `EXPLAIN` is taken after the window (section 2.3), so the plan of each run was not observed. Two databases and three laps are a small sample of the levels (four levels were seen).
- **The `ANALYZE TABLE` stage (section 4.6) is not a measurement with fixed statistics.** The stored statistics of `deletions` were recalculated automatically inside the first trial and inside all 18 trials, so what remained (or changed) after `ANALYZE TABLE` can be neither read as "the statistics are unrelated" nor as "the statistics are the cause". It ran on one database and 18 trials, half the trials of stage 2, so the absence of the highest level (about 850 thousand) may be a matter of sample size. `--analyze=rerun` was not run.
- **Not done, and left to the maintainer: a measurement with the automatic recalculation of statistics stopped** (for example `STATS_AUTO_RECALC=0` per table). It changes a condition of the measurement, so this investigation did not do it. Whether it is worth doing is the maintainer's judgment; this file does not recommend it.
- **The two databases of T1 differ in three tables** (`settings`: the instance id; `users`: the password column; `role_permissions`: the creation times; section 4.5). That the routes do not read through the differing columns, and that the statements on these tables read the same rows in every trial, is shown in section 4.5; the column differences themselves were checked on two rebuilt databases of the same recipe at 10,000 rows, not on the measured databases of 100,000 rows, which were torn down when the run ended; for the measured ones only the digests and checksums exist.
- **`EXPLAIN` is a plan after the window, not during it** (section 2.3). A statement whose plan changes during a request would show the later plan. Taking the first and the last binding is a partial check only.
- **The tap slows the cold request** by a factor of about 2 on 10,000 rows (section 3, an estimate), so a tapped trial is not the same in elapsed time as a trial of test A. Rows examined are not measured by time (D97, note), and no reading here uses elapsed time. That the tap leaves the figure unchanged is shown on the verification app, not run by run on BookStack, where the figure moves by itself.
- **T2 is one lap, one database per scale, two scales, and only `references` was scaled** (section 5). It names the statement whose plan changed; it does not say why the optimizer chose the full scan, and it does not locate the scale where the choice flips.
- **T3 is a reading of records and code** (section 6): the table at N = 0 was not looked at, no option was tried, and the options were not compared by a measurement. The behaviour of the 12 routes at N = 10 is not checked.
- **Test A is not changed by any of this** (D103). If a formula is later changed, D104 fixes how it is checked: a prediction at 200,000 rows, committed before the measurement, with the pass line unchanged.
