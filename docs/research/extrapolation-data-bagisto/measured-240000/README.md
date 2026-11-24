# Test A on Bagisto at 240,000 rows: measured outputs

This directory holds the measured outputs of test A on Bagisto at 240,000 rows. The estimates and the marks were committed before the first request, at `207911e` (2026-11-24T13:04:39+09:00); the gate printed `PREDICTION OK` for both prediction files at the start of the measurement and of the third repeat (`prediction_ok_gate_lines.txt`). Nothing here compares the measured numbers with the estimates; the comparison and the verdict are in `../compare/` and in `docs/research/extrapolation-test-bagisto.md`.

## What was measured, and when

| Step | Project | Start | End | Exit | Used for the verdict |
| --- | --- | --- | --- | ---: | --- |
| Every route cold once (`measure/`) | `peak-bagisto-a240k-ms` | 2026-11-24 14:57:57 | 2026-11-24 17:57:56 | 0 | yes |
| Repeat, first database | `peak-bga240k-var-a240k-db1` | 2026-11-24 17:57:56 | 2026-11-24 18:29:20 | 15 | **no, not kept here** |
| Repeat, second database | `peak-bga240k-var-a240kr-db1` | 2026-11-24 18:40:47 | 2026-11-24 20:57:39 | 15 | **no, not kept here** |
| Repeat, third database (`variance_3/`) | `peak-bga240k-var-a240k3-db1` | 2026-11-25 00:50:08 | 2026-11-25 06:15:45 | 0 | yes |

All times are JST. The tool commit and the SHA-256 of the capture copy are in `order_record.txt` and `order_record_3.txt`.

- **Every route cold once.** 120 routes were inspected (Laravel 13.40.0), 112 were tried, 10,782 s. The two prediction files had the gate lines above. The preflight of the isolated network passed (`measure.stdout.log.txt`).
- **Third repeat.** 40 routes, 3 laps each, 120 trials, all with exit 0. The 40 routes are the 41 marked routes without `GET /admin/customers/view/{id}` (D254); the list is `routes_repeated_3.txt`. The database was built in 947 s (2026-11-25 00:50:23 to 01:06:47). The times of all 120 trials are in `trial_times_3.tsv`.
- **Digest gate (D260).** Right after the build, the table-by-table digest of the third database was compared with the one of the first database: 152 tables, equal row counts in all of them, and a different checksum in three tables only (`attributes`, `product_inventory_indices`, `product_price_indices`); three column queries then matched the first database. Result `PASS`; the full record is `digest_gate_3.txt`. The three column queries ran during trial 1 of lap 1 and overlap it (accepted in D260); the times are in the record.

## The first two repeats are not used

The maintainer ruled both repeats invalid before their values were read (D254, D259). Their outputs are on disk but are **not opened and not copied here**; only their names and the times they stopped are written.

- **First database** (project `peak-bga240k-var-a240k-db1`, directory `variance/`): stopped at 2026-11-24 18:29:20 with exit 15. The PHP container was killed for memory at its 6 GB limit while it ran trial 7 of lap 1 (`GET /admin/customers/view/{id}`, exit 137); trials 1 to 6 of lap 1 had finished. This is why that route is not in the third repeat (D254).
- **Second database** (project `peak-bga240k-var-a240kr-db1`, directory `variance_r/`): the run was stopped on purpose at 2026-11-24 20:57:39 (exit 15) at the planned stop of the work; 49 trials had finished (lap 1: 40, lap 2: 9) and trial 50 was running. The maintainer ruled it invalid and ordered a third database built from the start (D259). Its values were never read, never mixed with the third repeat and never compared with it.

The two databases, their containers, volumes and networks were left as they are.

## Which databases are still there with their contents

Checked on 2026-11-25 between 06:22 and 06:23 by starting each database container, reading only the database name and the table count (and the approximate row total for two of them), and stopping it again (`docker stop -t 180`, `Shutdown complete` in the log, exit 0). No row content and no measured value was read. The logs show no crash recovery for any of the three.

| Database | Contents kept | Database name | Tables | Rows (`TABLE_ROWS` sum, approximate) |
| --- | --- | --- | ---: | ---: |
| First repeat, `peak-bga240k-var-a240k-db1` | yes | `peak_sim_measure_b663e6e0f94b_u589f83_240000` | 152 | about 32,255,373 |
| Second repeat, `peak-bga240k-var-a240kr-db1` | yes (existence and table count only) | `peak_sim_measure_2ea83d8d74d6_u589f83_240000` | 152 | not read |
| Third repeat, `peak-bga240k-var-a240k3-db1` | yes | `peak_sim_measure_81f87b7724a1_u589f83_240000` | 152 | about 32,420,565 |

The number of volumes was 52 before and after this check, with no difference.

**The database of the every-route measurement is gone.** Its volume `peak-bagisto-a240k-ms_measure-dbdata` is still there, but the tool dropped the measured database itself at the end of each point (`DROP DATABASE IF EXISTS` in the measure driver), so the volume holds no database. `--keep-stack` keeps only the containers and the volume. This was recorded as a fact in D257; the maintainer accepted it. The causes of single values of the every-route measurement therefore cannot be investigated on the same database.

No volume was removed in any step: each list after differs from the one before only by the volumes that were added in between (`volumes_before.txt` / `volumes_after.txt`: the volumes of the every-route measurement and of the first repeat; `volumes_before_3.txt` / `volumes_after_3.txt`: the volume of the third repeat).

## Files

- `measure/`: outputs of the every-route measurement (`attempts.jsonl`, `routes.jsonl`, `summary.json`, `tasks.json`, `report.md`). `static.json` (10,416,113 bytes, SHA-256 `d04395480720e547910458e9950d8aa86f0ba1f8546a5f9c747673d6ab59b8fa`) is not kept in git because of its size; it is the static analysis output and no verdict script reads it.
- `variance_3/a240k3/db1/`: the third repeat as the tool wrote it (`run.txt` one level up; here `index.tsv`, `plan.*`, `tasks.json`, `g1/` with `build.*`, `content.tsv`, and `plain_lap1` to `plain_lap3`, one directory per trial with `trial.json`, `attempt.jsonl`, `stats_before.tsv`, `stats_after.tsv`).
- `order_record.txt`, `order_record_3.txt`, `preflight.txt`, `preflight_3.txt`, `host_before_measure.txt`, `host_before_repeat_3.txt`, `*.create_options.tsv`, `*.done`, `digest_gate_3.txt`, `trial_times_3.tsv`, `routes_repeated_3.txt`, `volumes_*.txt`: the order and the times of the steps, the memory, disk and Windows Update state before them, the table options, and the volume lists. `order_record.txt` also holds the two lines about the first repeat (its name, the exit and the stop time) and nothing else of it.
- `prediction_ok_gate_lines.txt`: the gate's `PREDICTION OK` lines, copied from the `*.stdout.log` files.
- `measure.stdout.log.txt`, `measure.stderr.log.txt`, `repeat_3.stdout.log.txt`: the three short run logs, renamed because `*.log` is not kept in git.
- `variance_3_logs.sha256`: SHA-256 of the 125 `*.log` files of the third repeat (the 120 per-trial `stderr.log`, `restore_plain_lap{1,2,3}.log`, `run.log`, `watch.log`). The files stay on the maintainer's disk and are not in git (`*.log` is ignored). The timeout scan in `../compare/` read the `stderr.log` files; the lines that end in `/stderr.log`, taken in file order, give SHA-256 `14ab0e86dc00db4c88a41f0372e5a37e2921fee2c42eeaf4ff0acae0fc0b6de6` when hashed again (`grep '/stderr.log$' variance_3_logs.sha256 | sha256sum`).

## Hashes of what the verdict read

- `measure/attempts.jsonl`: `976aae9d1e0ed26d6701c72877059753aa7df5c0a0d43117236d83b4fbc0223c`.
- The 120 `attempt.jsonl` files of the third repeat, hashed as `find variance_3/a240k3/db1 -name attempt.jsonl | sort | xargs sha256sum | sha256sum` from this directory: `371ed9252c6e313403071594cffcf4667d24f066df68525736015c92188b9ce7`.
- The other inputs and the scripts are listed with their SHA-256 in `../compare/sha256.txt`.

## What was changed when the files were copied

Everything is a byte copy of what the tool wrote, with one exception: the names of volumes that belong to other work on the same machine appear in `volumes_before.txt`, `volumes_after.txt`, `volumes_before_3.txt`, `volumes_after_3.txt` and `preflight_3.txt` (three volume names in each list), and are written `<other volume>` here, so the SHA-256 of these five files differ from the originals. The files the verdict read (`measure/attempts.jsonl` and the `attempt.jsonl` files) are unchanged. The home directory, the user name and the host name do not occur in the files copied here.

## Size

`measure/` 0.6 MB, `variance_3/` 11.3 MB (without the `*.log` files, which are not in git; they add 1.0 MB on disk), the records above 0.1 MB: about 12 MB in git.
