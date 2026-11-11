# Putting the statistics of every lab database in order (ANALYZE TABLE at the default sample size)

Every database of the lab's measurement stack (3,000, 10,000, 30,000, 50,000, 70,000, 100,000 and 200,000 rows) had `ANALYZE TABLE`
run on every table, once, at the server's default sample size (`innodb_stats_persistent_sample_pages` = 20; no table has its own
`STATS_SAMPLE_PAGES`). The statistics tables were recorded before and after. **No request was sent, no load was applied, no EXPLAIN was
run, and no table option was changed.** The only statements were `ANALYZE TABLE` and reads of `mysql.innodb_table_stats` and
`mysql.innodb_index_stats` (plus the list of tables from `information_schema`). The application was not re-pointed (it still reads the
10,000-row database), its settings were not touched, and `git diff` of `poc/lab/compose` is empty.

Times in the files are the database server's clock (UTC): 05:17 there is 14:17 local time.

## Result

| Scale | Tables analysed | Status | Wall time | `last_update` before (earliest to latest, UTC) | after (UTC) | Tables whose `n_rows` changed | Index statistics (of 517) whose value changed |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 3,000 | 41 | all OK | 0.6 s | 2026-11-09 23:37:40 to 2026-11-10 00:22:01 | 2026-11-11 05:17:04 | 4 | 9 |
| 10,000 | 41 | all OK | 0.7 s | 2026-11-09 07:24:40 to 2026-11-10 03:06:28 | 05:17:04 to 05:17:05 | 19 | 138 |
| 30,000 | 41 | all OK | 0.9 s | 2026-11-09 23:29:24 to 23:34:00 | 05:17:05 to 05:17:06 | 13 | 46 |
| 50,000 | 41 | all OK | 0.4 s | 2026-11-11 05:06:26 to 05:10:23 | 05:17:06 | 8 | 80 |
| 70,000 | 41 | all OK | 0.4 s | 2026-11-11 05:05:24 to 05:06:38 | 05:17:07 | 10 | 84 |
| 100,000 | 41 | all OK | 1.1 s | 2026-11-10 04:52:04 to 04:54:56 | 05:17:07 to 05:17:08 | 6 | 72 |
| 200,000 | 41 | all OK | 0.4 s | 2026-11-11 04:56:55 to 04:59:34 | 05:17:08 to 05:17:09 | 6 | 66 |

The `last_update` of a table is its own, so the earliest and the latest before the run differ within one database (the cause of the
spread in the 10,000-row database was not looked into).

What `n_rows` was in the tables the estimate reads most (before to after):

| Table | 50,000 | 70,000 | 200,000 |
| --- | --- | --- | --- |
| `entities` | 49,395 to 48,798 | 66,544 to 66,789 | 189,480 to 198,164 |
| `entity_container_data` | 30,234 to 29,129 | 41,598 to 42,360 | 116,069 to 120,080 |
| `images` | 49,714 (unchanged) | 69,542 (unchanged) | 198,567 (unchanged) |
| `references` | 50,116 (unchanged) | 69,948 (unchanged) | 199,392 (unchanged) |

These are the optimizer's estimates, not counts; the real counts are in `docs/research/lab-data/build-70k-50k/` and `build-200k/`.

## The automatic recalculation was left as it is

The tables keep the server's default (`innodb_stats_auto_recalc` = ON; no table carries a `STATS_AUTO_RECALC` option: 0 of 287 tables
have any statistics option). This follows the rule in `docs/simulator-spec.md` ("the automatic recalculation afterwards is left at its
default"). `bin/capture --analyze-after-build=on` differs on purpose: its throw-away databases get `STATS_AUTO_RECALC=0` after the
`ANALYZE TABLE`, so that nothing recalculates between its build and its first statement. The lab's databases are long-lived and are read
under load, and the spec fixes their rule as the default, so the two were not made the same.

## Files

- `table-stats-before.tsv`, `table-stats-after.tsv`: `innodb_table_stats` of every lab database (scale, table, `last_update`, `n_rows`,
  `clustered_index_size`, `sum_of_other_index_sizes`).
- `index-stats-before.tsv`, `index-stats-after.tsv`: `innodb_index_stats` (scale, table, index, stat name, `last_update`, value, sample size).
- `analyze-output.txt`: the output of every `ANALYZE TABLE` (287 lines, all `status OK`).
- `timing.tsv`: per scale, the number of tables, start and end (local time) and seconds.

## What this does not say

That the plans the optimizer picks on these databases are the plans EXPLAIN shows, or that a request on them costs what it did before.
Nothing was run that would show it. A statistics refresh at the default sample size is not deterministic (the sample is of random
pages), so another `ANALYZE TABLE` can give slightly different values.
