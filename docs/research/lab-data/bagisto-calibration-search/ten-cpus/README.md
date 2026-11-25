# Searching the rates of the two skewed calibration runs again, with a ten-CPU app (Bagisto lab)

The first search (`../README.md`, commit `4540aa8`) was made with the app on 6 CPUs. Its only run (cal-6 at 0.45 rps) was invalid: the app's 6 CPUs were exhausted at 99.89%, the rate
was not reached, and the database stayed at 26%. The maintainer ruled that the lab's app gets 10 CPUs and that the search starts again from the beginning (decisions D271, point 2);
the values measured on 6 CPUs are kept as invalid records and are not used. The lab was changed in commit `5bdf38d` (compose file, `bin/with-bagisto`, the app-CPU denominator of the
records). This directory holds the search made after it.

**This file is written before the first run of the new search is measured.** What is under "Before the first run" was fixed then. The results are added after, below the line
"Results", and nothing above that line is changed.

## Before the first run

### The lab as it is set

| What | Value |
| --- | --- |
| Lab code | repository HEAD `5bdf38d` (`feat: give the bagisto lab app ten cpus and take its cpu figure over the quota`); the decision records up to D272 are in `c6d9a33` and `1a0083f` |
| CPUs | app and web 10 (cores 2-7 and 12-15, `nano_cpus` 10e9, read with `docker inspect`), database 2 (0-1), load tool 2 (8-9), tools 2 (10-11); php-fpm `pm.max_children` 16 (unchanged, D191 (4)) |
| Database | `…_lab_30000` of the Bagisto lab's volume, the one built and analysed once for the first search (`../build-30000/`); no ANALYZE is run between runs. It was stopped with `docker stop -t 180` last time (a clean shutdown) and the log of this start shows `ready for connections` with no recovery |
| The app reads | scale 30,000 (`bin/use-db 30000`, containers made again after the limits were set) |
| Limits | off: PHP `max_execution_time` 0, `memory_limit` 4G, the pool's `request_terminate_timeout` 0s, nginx `fastcgi_read_timeout` 1800s (`config/ladder-b-1800s.diff` put on by `bin/lab-config-b apply`); the load tool's request timeout is **1800 s** (D270, decision 3) |
| Machine | 15 GiB total, 11 GiB available, 883 GB free (read just before the stack was started); Windows Update paused until 2026-11-28 15:02 UTC |
| Quiet machine | the cores 12-15 are the app's now; while a run is measured nothing else is run on this machine (D271, condition c) |
| Recorded for every run | the database's utilization and the app's CPU utilization (denominator 10, `app_cpu_utilization` in `record.json`) |

The first search was measured with a request timeout of 600 s. This one uses 1,800 s (D270, decision 3). The difference is recorded here and is not hidden.

### The rule for R_3 and R_4

As in the first search: D183 and its amendment of 2026-11-11 15:12, as D186 and D266 (decision 1) applied them, with D270 and D272.

- cal-6 (the route ranked 1 at 80%, the other seven evenly) has the rate R_3, cal-7 (the route ranked 2 at 80%) has R_4; both at 30,000 rows; each is searched on its own run.
- Conditions of a run: 10 minutes at a constant arrival rate, the first 2 minutes discarded, limits off.
- Stage values (section 7.2): 0.05, 0.07, 0.1, 0.15, 0.2, 0.3, 0.45, 0.7, 1.0, 1.5, 2.2, 3.3, 5.0, tried from the top down; the first value whose database utilization is in 20% to 30% is the rate.
  1. Start at the first value (cal-6: 0.45; cal-7: 1.0, both from the desk estimate of the first search, which looked at the database only). More than 30% → the next stage value down.
  2. The first value below 20% → stop and report.
  3. The second value below 20% → the geometric mean of the two (rounded to two decimals) once; if that is not in the band, the tried value whose utilization is nearest 25% is taken and recorded as "outside the band".
  4. The second value also above 30% → stop and report.
  5. A search run made in the conditions of a run and found in the band is the run itself and is not measured again.
- **A run whose rate is not reached (`rps_status` not `valid`) is invalid even when its utilization is inside the band** (D270, decision 2): it is not taken as R; the search goes to the next stage value down. If the value that is invalid is the lowest one in reach of the rule, stop and report.
- **The gate in front of a search run is a reference** (D270, decision 1): it is called by the entry for every run, recorded, and does not stand as a reason to refuse the search. The effective gate is called after R is decided, with the real plans, and before the calibration is measured.
- Any exit of a guard other than 0 (13 to 18), any 503, any statement the write guards refuse, a utilization that cannot be read, or a run that cannot be measured: stop and report. No database is removed, no volume is removed,
  no `--purge`, no `docker kill`; the database container is stopped with `docker stop -t 180` at the end.

### The stand-in rates of the first search runs (cal-6)

The entry calls the gate with the plans of all seven runs and measures nothing if the gate refuses. Only cal-6 is measured now, so the other six runs are given rates they are not searched at: the entry
needs them to open the gate. They are **stand-ins, not values of R_0, R_1, R_2 or R_4, and are not kept as such**. They are fixed here, before any run, from the inputs-only tables of the gate
(`../gate-input-only/passing-points*.tsv`, the same tool and inputs), and asked of the gate with `--gate-only` (nothing measured; `gate-standin-cal6/`):

| Stand-in | Value | Why |
| --- | --- | --- |
| R_0 (cal-1 to cal-3) | 1 | the first value of the doubling search of R_0 |
| R_1 (cal-4) | 4 | a value on the same grid; the grid points that open the gate at R_3 of 0.3 to 0.45 have R_1 = 4 |
| R_2 (cal-5) | 0.2 | the value, among those that open the gate for all three of R_3 = 0.3, 0.37 and 0.45 with R_4 = 0.3, with the largest smallest degree |
| R_4 (cal-7) | 0.3 | the same |

Asked of the gate with cal-6 at the three rates the search can reach before it stops (0.45, 0.37, 0.3): the smallest degrees are 0.508322 (without cal-6), 0.508322 (without cal-6) and 0.508108 (without cal-4),
all at or above 0.50, so the entry's gate opens at each (`gate-standin-cal6/gate-r3-*.json`). The stand-ins are the same for every cal-6 run of this search.

The stand-ins for the cal-7 runs are chosen after R_3 is known and are written here, in a commit of their own, before the first cal-7 run is measured.

### The search order

1. cal-6 at 0.45 rps (R_3), then by the rule above.
2. cal-7 at 1.0 rps (R_4), then by the rule above.
3. With the real R_3 and R_4: the inputs-only gate over the remaining grid (R_0, R_1, R_2); if every point fails with cal-1 to cal-7, the same with cal-8 (D271, decision 3; the grid of `../gate-input-only/cal-8/`); if every point still fails, stop.
4. If a point passes: the search of the remaining rates (R_0, R_1, R_2, and R_5 if cal-8 is used), then the effective gate with the real plans. Stop there. The calibration itself is not measured by this task.

Each window in which a run is measured is written in `search-log.txt` with its start and end.

## Results

### cal-6 at 0.45 rps (2026-11-25 15:54 to 16:13; `cal6-r0.45/`)

| | |
| --- | --- |
| Rate asked, rate reached | 0.45 asked, **0.3792 reached** (ratio 0.8426); `rps_status` **invalid** (`rps_outside_5_percent`, `load_tool_dropped_arrivals`: 36 arrivals dropped) |
| Requests in the kept window (480 s) | 182 sent, all status 200; route 1 (`GET /api/products`) 140 |
| Route 1 in flight | 22 at the window's start, 56 at its end |
| Database utilization (2 CPUs) | **47.0770%** |
| App CPU utilization (10 CPUs) | **99.73%** (4,787 CPU-seconds over 480 s); the app's per-minute use was 9.96 CPUs from the second minute on |

The gate opened (smallest degree 0.508322), the write check, the limits guard (`LIMITS OK`, request timeout 1800 s) and the guards of the N tables passed. The run is invalid because the rate was not reached;
by D270 (decision 2) it is not taken as R_3, whatever its utilization, and the search goes to the next stage value down, 0.3.

### cal-6 at 0.3 rps (2026-11-25 16:13 to 16:28; `cal6-r0.3/`)

| | |
| --- | --- |
| Rate asked, rate reached | 0.3 asked, **0.2875 reached** (ratio 0.9583, inside 5%); `rps_status` **invalid** (`load_tool_dropped_arrivals`: 6 arrivals dropped) |
| Requests in the kept window | 138 sent, all status 200 |
| Route 1 in flight | 11 at the window's start, 25 at its end |
| Database utilization (2 CPUs) | **45.2421%** |
| App CPU utilization (10 CPUs) | **99.60%** (the app was at 5.7 CPUs in the first minute and at 9.9 or more from the third on) |

Same guards, all passed; invalid by D270 (decision 2) and not taken. These are measurements, not explanations: on 10 CPUs the app was again at its limit and the database was well above 30% at both rates
(on 6 CPUs the one earlier run had the database at 26% at 0.3625 rps reached). The database's CPU per request sent was about 2.5 s at 0.45 and about 3.1 s at 0.3 (6 CPUs, 0.45: about 1.4 s), so the database's
utilization did not fall with the rate between these two runs.

### The next value, 0.2 rps, and the gate it needs (decided before it is measured)

The next stage value down is 0.2. The gate, asked with the same stand-ins as before and cal-6 at 0.2, 0.15 or 0.1, **refuses**: the smallest degree is 0.4265 (R_3 = 0.2), about 0.375 (0.15), about 0.32 (0.1), with R_4
of 0.1, 0.2 or 0.3 and R_2 of 0.2 or 0.3, R_1 of 2 or 4 (36 combinations, all exit 14; the printed list is not kept as a file). By D270 (decision 1) the gate in front of a search run is a reference and must not stop the search,
and the entry has no way to skip it. The set of runs given to the entry is a choice of the measurer; so for the search runs from here the entry is given **cal-8** (1,000 rows, even, at the stand-in rate R_5 = 8) as an
eighth run, **for the gate's question only**: it is not measured, and the stand-in is not a value of R_5. The gate then opens with the stand-ins above (smallest degree 0.591401 at R_3 = 0.2, 0.561721 at 0.15, 0.534800 at 0.1;
`gate-standin-cal6-with-cal8/`). This is not the use of cal-8 that D271 (decision 3) keeps for the effective gate; the effective gate is still called after R is decided, with the real plans, and cal-8 is added there only if
cal-1 to cal-7 fail it. Whether this reading of D270 (decision 1) holds is for the maintainer's side to judge; it is recorded here before the run, and it does not favour the pass of the calibration (it concerns a reference gate
and the search runs only).

### cal-6 at 0.2 rps (2026-11-25 16:30 to 16:42; `cal6-r0.2/`)

| | |
| --- | --- |
| Rate asked, rate reached | 0.2 asked, 0.2021 reached (ratio 1.0104); `rps_status` **valid**, 0 arrivals dropped |
| Requests in the kept window (480 s) | 97 sent, all status 200, none failed; route 1 76 |
| Route 1 in flight | 5 at the window's start, 5 at its end |
| Database utilization (2 CPUs) | **20.7775%** (inside 20% to 30%) |
| App CPU utilization (10 CPUs) | **47.43%** (4.5 to 5.1 CPUs a minute once the load was on) |
| Guards | gate opened (smallest degree 0.591401, with cal-8 as the gate-only stand-in); write check, limits guard (`LIMITS OK`, 1800 s), the N tables identical before and after, `innodb_table_stats` unchanged |

The run is valid and inside the band, it was made in the conditions of a run, and 0.2 is the first stage value from the top whose run is valid and inside the band. By the rule above (rule 5) it is cal-6's run and is not measured again:
**R_3 = 0.2 rps**. The utilization is close to the lower edge of the band (20.78%); that is what was measured and it is not adjusted. The runs at 0.45 and 0.3 stay as invalid records.

### The stand-ins of the cal-7 search (decided before it is measured)

R_3 is 0.2 now, and cal-6 is given to the entry at 0.2 in every cal-7 run. cal-7 is searched at 1.0 first, then by the same rule (0.7, then 0.84 once, and the stage values below if a run is invalid by D270). The other runs are
stand-ins for the gate's question as before: R_0 = 1, R_1 = 4, R_2 = 0.2, and cal-8 at R_5 = 8 (1,000 rows, asked of the gate only). Asked of the gate with cal-7 at 1, 0.84, 0.7, 0.45, 0.37, 0.3 and 0.2 the entry's gate opens at each
(smallest degrees 0.626919, 0.627143, 0.623240, 0.606005, 0.598335, 0.591401, 0.582258; `gate-standin-cal7/`).

### cal-7 at 1.0 rps (2026-11-25 16:42 to 16:54; `cal7-r1/`)

| | |
| --- | --- |
| Rate asked, rate reached | 1.0 asked, 1.0021 reached (ratio 1.0021); `rps_status` **valid**, 0 arrivals dropped |
| Requests in the kept window (480 s) | 481 sent, all status 200, none failed |
| Database utilization (2 CPUs) | **21.0616%** (inside 20% to 30%) |
| App CPU utilization (10 CPUs) | **12.85%** |
| Guards | gate opened (smallest degree 0.626919, cal-8 as the gate-only stand-in); write check, limits guard, the N tables identical before and after, `innodb_table_stats` unchanged |

Valid, inside the band, in the conditions of a run, and the first value tried: it is cal-7's run and is not measured again. **R_4 = 1.0 rps.** The desk estimate of the first search had put the database at 35.3% at 1.0 rps for this run; the
measurement is 21.1%, the other way from what cal-6 showed (estimate 14.0% at 0.2 rps, measured 20.8%).

### The gate on inputs alone, with R_3 = 0.2 and R_4 = 1.0, over the rest of the grid (2026-11-25 16:55; nothing measured)

Before it was called: repository HEAD `a07c74b`; the inputs' sha256: `estimate-shared/estimate.json` 92fd9b7b78a400f6e355c77d7492fa82a204fdb815fa1aaab196c7f4760a194c, `calibration-gate.php` c627e16c0d73d7bc40f7b5e9f6d83bef6d4f74b2222a91b2a86dba00d039a5c5,
`CalibrationScenario.php` e31fdffa6481474af377f4f39c941846f6b7613bdf6ff70ea276315f88f439f6, `CalibrationGate.php` 126dd4347cfda8194710ebfa9d0b58c42c9b4589d70b079f90d23956ccf53137, `CalibrationPoint.php`
d4e26052342bb80448876315b5adac97550c452fcef5836cf80411258adb2005, `gate_batch_real.php` 4888cdcdd9f24fac23469cea3690e9f14bc7d0483ec473901a8f53e695808b85 (the batch form of the gate, as `../gate-input-only/cal-8/gate_batch.php`,
with R_3 and R_4 fixed; the same call for every point: shared-table copy of the estimate, `--tables=n_shared`, `--scale=10000`, cal-4 at 3,000 rows, cal-5 to cal-7 at 30,000, cal-8 at 1,000). Grid: R_0 and R_1 in 1, 2, 4, 8, 16, 32 and R_2 in 14 values
(the stage values and 0.37), 504 points; with cal-8, R_5 in 1, 2, 4, 8, 16, 32 as well, 3,024 points. Tables: `gate-real-r3-r4/`. Two points were also asked of the command line (`bin/measure-calibration --gate-only`) and gave
the batch's degrees (0.644140 and 0.550836).

- **cal-1 to cal-7: 0 of 504 points pass**; the largest smallest degree is 0.380375 (R_0 1, R_1 8, R_2 0.05, without cal-4). The set cal-1 to cal-7 fails at the rates found, so cal-8 is added (D271, decision 3).
- **cal-1 to cal-8: 311 of 3,024 points pass** (10.3%); the largest smallest degree is 0.644140 (R_0 1, R_1 8, R_2 2.2, R_5 8 or 16).
- By rate (points passing / points): R_0 1: 159/504, 2: 127/504, 4: 23/504, 8: 2/504, **16 and 32: 0**. R_1 1: 0/504, 2: 42, 4: 99, 8: 110, 16: 56, 32: 4. R_5 1: 0/504, 2: 26, 4: 63, 8: 77, 16: 82, 32: 63. R_2 17 to 30 of 216 for every value (5 and the low values fewest).
- So with cal-8 the region is open: R_0 up to 8 (nearly none at 8), R_1 from 2, R_5 from 2. R_0 of 16 or more would end the search (D272, decision 3).

What follows: R_0, R_1, R_2 and R_5 are searched one at a time. cal-8 needs a 1,000-row database of its own, which is not built yet.
