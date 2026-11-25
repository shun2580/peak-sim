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

## The search of R_0, R_1, R_2 and R_5 (rules and stand-ins fixed before the first of these runs)

The gate on inputs alone with R_3 = 0.2 and R_4 = 1.0 fails for cal-1 to cal-7 at every point and passes at 311 points with cal-8 (above). So the search goes on with the set cal-1 to cal-8, and each remaining rate is searched on its own run:

- **R_0** (cal-1, even mix, 10,000 rows), **R_1** (cal-4, even, 3,000 rows), **R_5** (cal-8, even, 1,000 rows): the doubling search of the specification, from 1 rps: 1, 2, 4, 8, …; the first rate whose database utilization is in 20% to 30% is the rate. A first run above 30% stops the search
  and reports (D183, amendment 1). If a rate is not reached (`rps_status` not `valid`, D270 decision 2), the geometric mean of the previous rate and this one is tried once; if that is not reached or is outside the band, stop and report (D272, decision 1).
  A value of R_5 above 32, or of R_0 of 16 or more, is handled as D272 decisions 2 and 3 say.
- **R_2** (cal-5, even, 30,000 rows): the stage values from the top down (D183; D263 gives cal-5 the rule of D183). The first value is the one the desk estimate of the first search puts nearest the band from above: the cost of a request of the even mix at 30,000 rows is
  the mean of the eight routes' 1.666, 0.769, 0.545, 0.289, 0.288, 0.203, 0.114, 0.065 s = 0.4924 s, so the database's utilization on 2 CPUs would be 36.9% at 1.5 rps and 24.6% at 1.0 rps: **the first value is 1.5**. Then the same rule as for R_3 (1 to 5 of "The rule for R_3 and R_4").
  The desk estimate was off by a factor of 0.6 to 1.5 in the two skewed runs, so it is a way of choosing the first value and nothing more.
- The order of the search: R_0 first (a value of 16 or more would end the search at once), then R_2, R_1, R_5. Each run is given the rates found so far and stand-ins for the rest; the stand-in is chosen from `gate-real-r3-r4/grid-real-set8.tsv` so that the entry's gate opens, and it is written here before the run.
- cal-8 needs a database of 1,000 rows and the databases of 10,000 and 3,000 rows exist already (built for the first search); the 1,000-row database is built and analysed once with the tools of the first search before the R_5 run, and its record is added here.

### cal-1 (R_0): the stand-ins

For the cal-1 runs: R_1 = 16, R_2 = 5, R_5 = 32 as stand-ins (R_3 0.2 and R_4 1.0 are the real ones). With them the gate opens at cal-1 at 1, 1.41, 2, 2.83, 4 and 5.66 (the smallest degrees 0.562302, 0.572432, 0.588850, 0.610578, 0.593375,
0.548233; `gate-standin-cal1/`); it does not open at 8 with these stand-ins, and if the search reaches 8 the stand-ins for that run are chosen from the table and written here first. The geometric means 1.41, 2.83 and 5.66 are the ones the rule would try after a rate that is not reached.

### cal-1 (R_0) at 1, 2, 4 and 2.83 rps (2026-11-25 16:58 to 18:10; `cal1-r1/`, `cal1-r2/`, `cal1-r4/`, `cal1-r2.83/`)

All four were measured on the 10,000-row database with the stand-ins above (R_1 = 16, R_2 = 5, R_5 = 32; R_3 = 0.2 and R_4 = 1.0 real); the gate opened each time, the write check, the limits guard (`LIMITS OK`, 1800 s) and the guards of the
N tables passed, and `innodb_table_stats` was unchanged in each. 10 minutes at a constant rate, the first 2 discarded, the kept window 480 s.

| Rate asked | Rate reached (ratio) | `rps_status` | Database (2 CPUs) | App (10 CPUs) | Requests sent, all status 200 | Arrivals dropped | In flight at start / end |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | 0.9979 (0.9979) | valid | **8.9048%** | 15.77% | 479 | 0 | 9 / 9 |
| 2 | 2.0000 (1.0000) | valid | **19.3278%** | 35.50% | 960 | 0 | 18 / 35 |
| 4 | 3.6625 (0.9156) | invalid (`rps_outside_5_percent`, `load_tool_dropped_arrivals`) | 45.9734% | 90.61% | 1,758 | 161 | 105 / 319 |
| 2.83 | 2.8146 (0.9946) | invalid (`load_tool_dropped_arrivals`) | 31.5948% | 61.61% | 1,351 | 9 | 22 / 84 |

(For the run at 1 rps the file `tables-after.tsv` lists the write counters of some tables as extra lines that `tables-before.tsv` does not, all with zero writes; the guard compared the counts and the checksums, found no difference, and its own `.diff` file is empty.)

By the rule above: 1 rps is valid and below the band, so the rate is doubled; 2 rps is valid and just below the band (19.33%), so the rate is doubled; 4 rps is not reached (D270 decision 2: invalid, and the utilization of an invalid run is not read), so the geometric mean of 2 and 4, 2.83 rps, is tried once
(D272 decision 1); 2.83 rps was reached to within 5% but 9 arrivals were dropped by the load tool, so it is invalid, and its utilization, 31.59%, is also above the band. D272 decision 1 says: if that is not reached or is outside the band, **stop and report**. The search of R_0 therefore stops here.
R_0 is **not found**; R_1, R_2 and R_5 were not searched, and the effective gate was not called. The 1,000-row database was not built.

What the four runs say, as measurements: the database's utilization rose from 8.9% at 1 rps to 19.3% at 2 rps, 31.6% at 2.83 rps and 46.0% at 4 rps, so the band of 20% to 30% lies between 2 and 2.83 rps, and no value of the doubling (1, 2, 4, …) or of the one geometric mean the rule allows falls in it. This is the same
shape as the 0.45 and 0.3 rps runs of cal-6, in the other direction: the stage value above the band and the one below it both miss. The record for the maintainer's side is the choice between (a) a further geometric mean of 2 and 2.83 (2.38 rps), which the rule does not allow,
and (b) taking the tried value whose utilization is nearest 25% among the valid runs, 2 rps (19.33%), and recording it as outside the band, as D183's amendment (1) does for a rate of the 30,000-row run; neither is done here.

## State left

The compose files were put back to the committed bytes (`lab-config-b restore`), the database container was stopped with `docker stop -t 180` and the stack taken down without `-v`; the data volume (with the 3,000-, 10,000- and 30,000-row databases) is kept, as is the other lab's.

## The search goes on after the maintainer's ruling on R_0 (second session; written before any run of it is measured)

### What is fixed by the ruling (decisions D273)

R_0 is **2 rps**, the valid run of cal-1 at 2 rps (database 19.33%, "just below the band"); the runs at 4 and 2.83 rps stay as invalid records and are not used for the calibration. No further run of cal-1 is made: the calibration run of cal-1 will use the search run at 2 rps
(the same reading as for R_3 and R_4). The real rates now are R_0 = 2, R_3 = 0.2 and R_4 = 1.0. The remaining searches are R_2, R_1 and R_5, in this order; each is searched on its own run, and each run is given the real rates found so far and stand-ins for the rest.

### The lab as it is started again (2026-11-26 00:29, read before any run)

- Repository HEAD `0fa8924`; the lab files are as committed; the app and web containers have 10 CPUs as before (`5bdf38d`).
- Machine just before the stack was started: 15 GiB total, 11 GiB available, 883 GB free on the disk; Windows Update is paused until 2026-11-28 15:02 UTC (read from the registry).
- Database container start log (`docker logs` of the container, the whole log: 13 lines). The database was stopped cleanly last time (`Shutdown complete`). The log shows `InnoDB initialization has started` and `has ended` 0.7 s later, then `X Plugin ready for connections` and
  `mysqld: ready for connections. Version: '8.4.7'`. There is **no line about crash recovery, redo log application or an error**; the only two warnings are the ones every start of this image prints (the CA certificate is self signed; the pid-file directory is
  accessible to all OS users). The closed-network check passed for all five containers (`PREFLIGHT OK`).
- The limits are put on as before (B's settings: PHP time 0, `memory_limit` 4G, nginx 1800 s; the load tool's request timeout 1,800 s) and read back with `bin/check-limits` before the first run.
- Every run records the database's utilization (2 CPUs) and the app's CPU utilization (10 CPUs, `app_cpu_utilization` in `record.json`). While a run is measured nothing else is run on this machine (D271, condition c).

### cal-5 (R_2) at 1.5 rps: the first run and the stand-ins

The rule is the one written above for R_3 (stage values from the top down; first value 1.5 from the desk estimate; more than 30% → the next value down; the first value below 20% → stop and report; a run whose rate is not reached is invalid and not taken).
The set given to the entry is cal-1 to cal-8 (cal-8 asked of the gate only, not measured); R_0 = 2, R_3 = 0.2 and R_4 = 1.0 are real; the stand-ins are R_1 = 8 and R_5 = 8. They are chosen from the inputs-only table
(`gate-real-r3-r4/grid-real-set8.tsv`, the points with R_0 = 2 and R_2 = 1.5): R_1 = 8 and R_5 = 8 has the smallest degree 0.594549, the largest of the points with R_5 = 8, and the same pair opens the gate at every stage value of R_2 from 0.05 to 5
(smallest degrees 0.570351 at 0.05, 0.578212 at 0.7, 0.584551 at 1.0, 0.594549 at 1.5). They are stand-ins for the gate's question, not values of R_1 or R_5, and are not kept as such. The stand-ins are the same for every cal-5 run of this search.

### cal-5 (R_2) at 1.5 and 1.0 rps (2026-11-26 00:31 to 01:04; `cal5-r1.5/`, `cal5-r1/`)

Both runs were measured on the 30,000-row database with R_0 = 2, R_3 = 0.2 and R_4 = 1.0 real and the stand-ins R_1 = 8 and R_5 = 8 given to the gate; the gate opened each time, the write check, the limits guard (`LIMITS OK`, 1800 s) and the guards of the N tables passed.
10 minutes at a constant rate, the first 2 discarded, the kept window 480 s. The database's start log (above) had no recovery line.

| Rate asked | Rate reached (ratio) | `rps_status` | Database (2 CPUs) | App (10 CPUs) | Requests sent, all status 200 | Arrivals dropped |
| --- | --- | --- | --- | --- | --- | --- |
| 1.5 | 1.4667 (0.9778) | invalid (`load_tool_dropped_arrivals`) | 40.1903% | 77.01% | 704 | 16 |
| 1.0 | 1.0000 (1.0000) | valid | **21.5445%** | 41.19% | 480 | 0 |

By the rule: 1.5 is invalid (arrivals dropped by the load tool), and its utilization is also above 30%, so the search goes to the next stage value down; 1.0 is valid and inside 20% to 30%, it is the first value from the top that is, and it was made in the
conditions of a run, so it is cal-5's run and is not measured again: **R_2 = 1.0 rps** (database 21.54%, app 41.2%). The run at 1.5 stays as an invalid record. (The first start of the 1.0 run failed in 1 second, before anything was measured, because the plan file was named
with `1.0` instead of `1`; it was started again with the right name, and the line of the failed start is in `search-log.txt`.)

### The search of R_1 stops before its first run: the gate refuses at 1 rps and at 2 rps

R_1 is searched on cal-4 (even mix, 3,000 rows) by doubling from 1 rps. The entry calls the gate with the plans of the whole set before it measures, and the set has R_0 = 2, R_2 = 1.0, R_3 = 0.2 and R_4 = 1.0 real now. Asked of the gate with the command line
(`bin/measure-calibration --gate-only`, cal-1 to cal-8, nothing measured; `gate-r1-stop/`), R_5 = 8 as the stand-in:

| R_1 (cal-4) | Smallest degree | Gate |
| --- | --- | --- |
| 1 | 0.378826 (read from the table `gate-real-r3-r4/grid-real-set8.tsv`; it is 0.374260 to 0.378826 for R_5 from 1 to 32) | refuses (exit 14) |
| 2 | 0.429264 (table; 0.386401 to 0.429264 for R_5 from 1 to 32) | refuses (exit 14) |
| 4 | 0.522605 (table) | opens (exit 0) |

The table says the same for every R_5 from 1 to 32: at R_0 = 2 and R_2 = 1.0, no R_1 of 1 or 2 opens the gate, whatever stand-in is given to cal-8 (over the whole grid of the table, R_1 = 1 passes at 0 of 504 points and R_1 = 2 at 42 of 504; at R_0 = 2 and R_2 = 1.0 neither passes at any R_5).
By D270 (decision 1) the gate in front of a search run is a reference and must not stop the search, but the entry has no way to skip it and a stand-in cannot open it here. The first two runs of the doubling (1 and 2 rps) therefore cannot be entered,
and the search of R_5 (cal-8, from 1 rps) has the same shape (R_5 of 1 passes at 0 of 504 points). The rule does not say what to do; nothing was measured for R_1 or R_5, the effective gate was not called, and the search stops here.

## The search of R_1 and R_5 with the entry's gate as a reference (written before either is measured)

The entry's gate refuses the first runs of the doubling searches of R_1 (cal-4) and R_5 (cal-8) whatever stand-ins are given (section above, "The search of R_1 stops before its first run"). The lead of the implementation took the way that keeps the rule of D270 (decision 1:
the gate in front of a search run is a reference), under the standing delegation of D258 and as recorded in D275 and D277 (the maintainer is told afterwards): the entry has a new flag, `--search-gate-advisory` (first committed as `--gate-advisory` in `d223190` and renamed with "search" in its name so that it shows up in a command line; `bin/measure-calibration` and `tests/calibration-gate.sh`, 67 tests with the 16 new ones). Written here before the first of these runs is measured:

- **The searches of R_1 and R_5 are measured with the flag, from 1 rps: 1, 2, 4, 8, ...** by the rule written above (the doubling search of the specification, D183 amendment 1, D272 decision 1; no value is skipped to reach a rate the gate would open). The search of R_2 was made without it and is not measured again.
- **What the flag does:** the gate is called with the same inputs and the same arithmetic. If it refuses (exit 14), the entry prints `GATE ADVISORY`, keeps the degrees in `calibration-gate.json` (also in the run's directory) and, in `search-gate-advisory.txt` (also in the run's directory; written whenever the flag is given, also when the gate opens), the flag, the gate's exit code, whether it passed, its smallest degree and the run left out, and the threshold, and measures. Every other guard is unchanged (the write check, the
  closed-network check, the limits guard, the guards of the N tables). Any other failure of the gate (an unusable plan or estimate) still stops the entry; the flag cannot be given with `--gate-only`.
- **The gate's result of a run made with the flag is a reference and is kept as such** in the run's record; it does not stand as a reason to take or to refuse the run's rate.
- **The flag is not used for anything else.** The effective gate, called after R_0 to R_5 are decided, with the real plans, and before the calibration is measured, is called without the flag (D264 decision 6; D270 decision 1).
- **The rates the entry is given for the other runs of the set** (a plan is needed for each run, because the gate looks at the whole set): R_0 = 2, R_2 = 1.0, R_3 = 0.2 and R_4 = 1.0 are real. For the run of R_1 the rate of cal-8 is given as **1**, and for the run of R_5 the rate of cal-4 is the R_1 found; the rate of the run not yet searched is the first value of its own search, a rule that is not aimed at
  any gate or any band, and only an input of the reference gate. They are not values of R_1 or R_5.
- The helper `search8.sh` passes the flag when `SEARCH_GATE_ADVISORY=1` is set in the environment; without it the call is what it was.
- R_1 and R_5 are not measured by this record. After them, the effective gate (input only) is called and the search stops there, as the task says.

## The search of R_1 (cal-4), measured with `--search-gate-advisory` (2026-11-26 01:43 to 02:33; `cal4-r1/`, `cal4-r2/`, `cal4-r4/`, `cal4-r8/`)

Written after the four runs; the rule and the rates were fixed above before the first of them.

**Before the first run** (read at 01:43, `start-r1-r5.txt`, `search-log.txt`): repository HEAD `73aa37e`; `git status --short -- poc/lab` empty; 15 GiB total, 11 GiB available, 883 GB free; Windows Update paused until 2026-11-28 15:02 UTC (read from the registry).
The database container's start log (the whole log, 13 lines) shows `InnoDB initialization has started` and `has ended` 0.6 s later and `ready for connections`, with **no line about crash recovery, redo log application or an error** (the two usual warnings only: the CA certificate is self signed, the pid-file directory). The closed-network check passed;
B's settings were put on (`lab-config-b apply`), the app and web containers were made again on 3,000 rows, `bin/check-limits --require=unlimited --request-timeout=1800s` said `LIMITS OK`; the app and web have 10 CPUs (cores 2-7 and 12-15), the database 2 (0-1), the load tool 2 (8-9). While a run was measured nothing else was run on this machine.

Rates given to the entry: R_0 = 2, R_2 = 1.0, R_3 = 0.2, R_4 = 1.0 real; cal-8 at **1** (a rate it is not searched at, given to the gate's question only); cal-4 at the value tried. cal-4 is the 3,000-row database, even mix, request timeout 1,800 s, 10 minutes at a constant rate, the first 2 discarded (kept window 480 s).

| Rate asked | Rate reached (ratio) | `rps_status` | Database (2 CPUs) | App (10 CPUs) | Requests sent, all status 200 | Arrivals dropped | Gate (reference), smallest degree |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | 0.9938 (0.9938) | valid | **2.9030%** | 4.33% | 477 | 0 | 0.374260 (without cal-7), refused |
| 2 | 1.9979 (0.9990) | valid | **4.9735%** | 8.63% | 959 | 0 | 0.386401 (without cal-4), refused |
| 4 | 4.0000 (1.0000) | valid | **9.4082%** | 17.58% | 1,920 | 0 | 0.386401 (without cal-4), refused |
| 8 | 8.0000 (1.0000) | valid | **20.2227%** | 38.59% | 3,840 | 0 | 0.386401 (without cal-4), refused |

In every run the gate refused (exit 14), the entry printed `GATE ADVISORY` and measured (`search-gate-advisory.txt` in each run's directory: the flag, exit code 14, not passed, the degree, the run left out, the threshold 0.50). The write check (`WRITE CHECK OK`), the limits guard (`LIMITS OK`, 1800 s) and the guards of the N tables (`TABLES SAME`) passed, and no status code other than 200 was seen.
(As for cal-1 at 1 rps, `tables-after.tsv` of the run at 1 rps lists some write counters as extra lines that `tables-before.tsv` does not; the guard's own `.diff` is empty.) The gate's result is a reference and was not used to take or to refuse a rate.

By the rule (D183 amendment 1, D272 decision 1): 1, 2 and 4 rps are valid and below the band, so the rate is doubled; **8 rps is valid and its utilization, 20.2227%, is inside 20% to 30%, and it is the first value of the doubling that is: R_1 = 8 rps**. It was made in the conditions of a run (with the flag, which changes nothing but the entry's treatment of the gate), so by D274 and D277 (B1) it is cal-4's run and is not measured again.
The first run (1 rps) was not above 30%, so the stop for an over-band first run (D183 amendment 1) did not apply. The utilization is close to the lower edge (20.22%); that is what was measured and it is not adjusted.
R_1 is 8: not 1, 2 or 32 (D277, B2), and not outside the grid, so the search of R_5 goes on, with cal-4 given to the gate at 8.

Times (start of the entry's call, `search-log.txt`): 1 rps 01:43:58, 2 rps 01:55:55, 4 rps 02:07:50, 8 rps 02:19:46.

### The database of 1,000 rows for cal-8 (built and analysed once, 2026-11-26 02:34; `build-1000/`)

Before the search of R_5, as the plan above says. `bin/build-db --scale=1000` with the 123 N tables of `docs/research/bagisto-prep/n-tables.txt` at 1,000 rows and every other table at the base size, as for the other scales (`build-output.txt`: 24.74 s, rc 0), then `bin/analyze-db --scale=1000` once on the 152 tables
(`analyze/`: 152 of 152 `status OK`, 1 s). The first start of the analysis, inside the helper script, failed before it ran (its output directory did not exist, rc 1; nothing was analysed; `steps.txt` says so, and `analyze.out` and `analyze.err2` are the two empty files of that start — the two empty files were removed by mistake and
made them again empty, 02:35, before this commit). The second start is the one that ran (`analyze.out2`). No ANALYZE is run between runs.

### The search of R_5 (cal-8): the rule and the rates, fixed before the first run of it

cal-8 is even mix on the 1,000-row database; it is searched by the doubling search from 1 rps (1, 2, 4, 8, …; D183 amendment 1, D272 decision 1 and 2), with the flag `--search-gate-advisory` as written above. Rates given to the entry: R_0 = 2, R_1 = 8, R_2 = 1.0, R_3 = 0.2, R_4 = 1.0 real; cal-8 at the value tried. A first run above 30% stops the search and reports; a rate not reached (`rps_status` not `valid`) → the geometric mean of the previous value and this one once, and then stop if that is not reached or outside the band; a value above 32 is handled as D272 decision 2 says.

### The search of R_5 (cal-8) with `--search-gate-advisory` (2026-11-26 02:36 to 04:18; `cal8-r1b/` to `cal8-r32/`)

Written after the runs; the rule and the rates were fixed above before the first of them. The lab was started again at 01:43 (start log: 13 lines, no recovery line, no error) and was left running between the runs of R_1 and R_5; the database of 1,000 rows was built and analysed once before the first of these (`build-1000/`). The app reads 1,000 rows (`--switch-scale`).
Rates given to the entry: R_0 = 2, R_1 = 8, R_2 = 1.0, R_3 = 0.2, R_4 = 1.0 real; cal-8 at the value tried. Same conditions as before: 10 minutes at a constant rate, the first 2 discarded (kept window 480 s), request timeout 1,800 s, 10 CPUs for the app, 2 for the database.

**A mistaken start, not a run:** the first start (`cal8-r1`, 02:36:23) stopped in 13 s with exit 2, before anything was measured, because `targets-1000.json` (the paths of the eight routes at 1,000 rows, which `--switch-scale` needs) had not been made. It was made at 02:37 with `bin/targets` (`build-1000/targets.out`) and the run at 1 rps was started again as `cal8-r1b`. The mistaken start's `.out`, `.err` and directory are kept (`cal8-r1/`).

| Rate asked | Rate reached (ratio) | `rps_status` | Database (2 CPUs) | App (10 CPUs) | Requests sent | Arrivals dropped | Status codes | Gate (reference) |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1 (02:37) | 1.0000 (1.0000) | valid | **1.9406%** | 1.87% | 480 | 0 | 200 only | refused, 0.386401 |
| 2 (02:49) | 1.9917 (0.9958) | valid | **2.8579%** | 3.59% | 956 | 0 | 200 only | refused, 0.453646 |
| 4 (03:01) | 3.9937 (0.9984) | valid | **4.8513%** | 7.23% | 1,917 | 0 | 200 only | opened, 0.568429 |
| 8 (03:13) | 7.9979 (0.9997) | valid | **8.8546%** | 14.33% | 3,839 | 0 | 200 only | opened, 0.584551 |
| 16 (03:25) | 15.9979 (0.9999) | valid | **17.7310%** | 30.09% | 7,679 | 0 | 200 only | opened, 0.584551 |
| 32 (03:37) | 31.2083 (0.9753) | **invalid** (`load_tool_dropped_arrivals`) | 40.8905% (not read as a rate: the run is invalid) | 75.33% | 14,980 | 450 | 200: 14,889; 0: 88; 504: 3 | opened, 0.584551 |

The write check, the limits guard (`LIMITS OK`, 1800 s) and the guards of the N tables passed in every run. The gate is a reference (D270, decision 1) and was not used to take or to refuse a rate. 1, 2, 4, 8 and 16 rps are valid and below the band, so the rate was doubled each time.
**32 rps is not reached (dropped arrivals) and is above the band, so by D270 (decision 2) it is invalid**, and by D272 (decision 1) the next step would be the geometric mean of 16 and 32 (22.63 rps), once. That was not done: the run showed something that is not a rate that was not reached (next section), and the search stops here for the lead of the implementation to decide.
R_5 is **not found**. The effective gate was not called.

#### What was seen at 32 rps after the load window (facts only; no cause is given here)

- The load window ran 03:38:27 to 03:48:27; the samplers stop at 03:49:28. In the window the app used 7.5 CPUs a minute from the second minute on (of 10), the database 0.8 (of 2) (`cal8-r32/clues/cpu-timeline.txt`). The requests in flight (sent, not answered), by the load tool: 164 at the window's start and 568 at its end (289 of them `GET /api/categories`; `record.json`, `running_requests`).
- After the window the load tool kept 555 virtual users waiting at 601 s, and the number fell by about 31 in each 120 s; the completed iterations rose by about 31 in each 120 s, from 18,203 to 18,686 over the 1,680 s that followed (`cal8-r32/clues/k6-vus-timeline.txt`). In the window the same count rose by about 30 a second.
- At 04:00 (12 minutes after the window ended) 379 requests were unanswered; `docker stats` showed the app at 0.00% CPU, the web server 0.00%, the load tool 0.30%, the database 0.29%. In the database no statement was running (the process list held only the reading query), 1 connection was open (mine), `Innodb_row_lock_current_waits` 0 (`clues/db-at-0400.txt`).
- The nginx error log has 5 lines `upstream timed out (110: Operation timed out) while reading response header from upstream` at 04:17:02 to 04:17:14 (the log is in UTC, 19:17), when `fastcgi_read_timeout` (1,800 s) ran out for requests sent at about 03:47, near the end of the window. The record: 434 requests finished after the window, the longest about 1,792 s (`longest_after_window_ms` in `record.json`); 88 had no status (0) and 3 were 504. The run ended at 04:18:24 and the load tool left no request in flight.
- No container was restarted or killed by the memory limit (`RestartCount` 0 and `OOMKilled` false for all five, `clues/containers.txt`). The php-fpm log of the app container has no line from 03:30 on (no `max_children` or slow-log line). At 04:19, after the load tool had ended, all 16 php-fpm workers were idle in `accept` (`clues/procs-app.txt`). The database's `Max_used_connections` is 16, the pool's `pm.max_children`; `Aborted_connects` is 48 since the database's start (it was not read before the run, so how many belong to this run is not known); `Connection_errors_max_connections` 0 (`clues/db-status.txt`).
- What is **not** known: why requests stayed unanswered for about 30 minutes while the app and the database were idle. The samplers stopped at 03:49 and no process-level view was taken during the stall; the one look at the workers is from after the load tool had ended. This is not a rate that was not reached; it may be a property of the lab's set-up and may bear on the calibration runs and on a real application; it is for the lead of the implementation to have looked at.

#### State left

The compose files were put back (`lab-config-b restore`; SHA-256 `604aedff…` and `f495eab5…`, as committed; `git status --short -- poc/lab` empty), the load tool, tools, web and app containers were stopped, and the database was stopped with `docker stop -t 180` (**`Shutdown complete`**, 2026-11-25T19:21:43Z) and the stack taken down without `-v` (`shutdown-r1-r5.txt`). The data volume is kept (54 volumes, none removed); no `kill`, no `prune`, no `--purge`. The other lab was not touched.

## A diagnostic run at 32 rps, to look at the stall after the load window (written at 2026-11-26 04:31, before it is measured; `cal8-r32-diag/`)

At 32 rps the run of cal-8 (`cal8-r32/`) left requests unanswered for about 30 minutes after the load window while the app and the database were idle, and a reading of the lab's files gives a candidate cause that nobody has observed: the upstream keepalive of the web server's FastCGI connections (`keepalive 64`, `fastcgi_keep_conn on`; two nginx workers keep up to 128 idle connections) may hold the 16 php-fpm workers (`pm = static`, `pm.max_children = 16`) on idle connections, so that a 17th connection waits in the listen backlog until the web server closes an idle one (the upstream `keepalive_timeout` default is 60 s).

**This run only looks. It is not a search run.** Its one purpose is to observe the stall; its value is used for nothing: not for R_5, not for any calibration, and it is not a run of the search rule. The rule of the search (the geometric mean of 16 and 32, 22.63 rps) is **not** measured here; it waits until the cause is known and the maintainer has ruled.

- Plan and rate are those of `cal8-r32/`: cal-8, even mix, 1,000 rows, 32 rps, 10 minutes at a constant rate with the first 2 discarded, request timeout 1,800 s, the flag `--search-gate-advisory`, the same rates for the other runs. The directory is `cal8-r32-diag/`.
- The lab's settings are changed in no way: `lab.conf`, the php-fpm settings and the ini are as committed (with the B diff on the two compose files, as for every run of this search).
- Observation only: nothing is put into a container, no setting is changed, nothing is killed. At about one minute before the end of the window, right after it, and 2, 5 and 10 minutes after it, and right after the tail ends, the app container's listen queue on port 9000 (`ss -ltn` or `/proc/net/tcp`), its established connections and their peers, each php-fpm worker's `wchan` and state, and the web container's established connections to port 9000 are read, with the time from `date`.
- The cause counts as confirmed only if three things hold together: the receive queue of port 9000 is above 0, more than 16 connections from the web container are established, and the workers wait in a read on a connection rather than in `accept`. What is seen is written down as it is, and a cause is not argued from less.
- The tail ends by itself when the load tool's 1,800 s request timeout runs out; it is not stopped.

### The diagnostic run at 32 rps (2026-11-26 04:34 to 04:59; `cal8-r32-diag/`): what was seen (facts first, then the three conditions)

Run as written above: cal-8, even mix, 1,000 rows (the first line of `tables-before.tsv` is the table `addresses` at 1000 rows; the start log's `scale=10000` is only the start's default, before `bin/use-db 1000`), 32 rps, `--search-gate-advisory`, `--out` a new directory, the B diff on the two compose files, nothing else changed. Before the run: `git status --short -- poc/lab` was empty (before the B diff went on), HEAD `44c0125`, free 15 GiB total and 11 available, 883 GB free on the disk, Windows Update paused until 2026-11-28 15:02 UTC; the database's start log has no recovery or error line (`start2.txt`). The tail was not stopped; it ended by itself.
**Its value is used for nothing.** The run itself: rate reached 31.84 (ratio 0.9951), `rps_status` invalid (`load_tool_dropped_arrivals`, 85 dropped arrivals), database 40.70% (not read as a rate), app 75.20%, 15,285 requests in the window, all status 200 (no 504 this time), 136 requests finished after the window, the longest after 842.8 s; requests in flight at the window's start 124, at its end 231. The load tool had no request left at 04:58:41.

Looks taken by `observe.sh` (read only; `driver.sh` times them from the load tool's start 04:34:32; the window ended 04:44:32). One reading per row, as in `obs/*.txt`; `obs/observe-log.txt` has the times of writing. The table is made by `summarize_obs.py` from the files. "Recv-Q" is the receive queue of the listening socket of port 9000 inside the app container (the number of connections waiting to be accepted). "ESTAB in app" and "ESTAB web->:9000" are the established connections of port 9000 read from the app side and from the web side; "workers by wchan" counts the 16 php-fpm workers by the kernel function they sleep in (`0` = running; `do_sys_poll` = in a poll; `wait_woken` = asleep in a wait on a socket; `inet_csk_accept` = waiting in `accept`). The sockets held by workers are read from `/proc/<pid>/fd` and matched by inode to `/proc/net/tcp` (listed in the looks from "end+2m" on; the first three looks were taken before this was added).

| look | time | Recv-Q (:9000) | ESTAB in app | ESTAB web->:9000 | workers by wchan | worker sockets with data waiting | unaccepted ESTAB | stub_status Active | k6 running line | app% | db% |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| w-1min | 2026-11-26 04:43:32 | 213 | 229 | 229 | 9×0; 3×do_sys_poll; 4×wait_woken | - | - | 238 | 08m56.8s VUs 0221/0238 complete 16885 | 637.50 | 80.72 |
| end+5s | 2026-11-26 04:44:37 | 217 | 233 | 234 | 7×0; 2×do_sys_poll; 7×wait_woken | - | - | 246 | 10m00.8s VUs 0219/0245 complete 18902 | 210.67 | 5.03 |
| end+1m | 2026-11-26 04:45:32 | 218 | 234 | 234 | 16×wait_woken | - | - | 246 | 10m56.8s VUs 0218/0245 complete 18903 | 1.92 | 0.32 |
| end+2m | 2026-11-26 04:46:32 | 202 | 218 | 218 | 16×wait_woken | 16 held by workers (0 with unread bytes) | 202 | 219 | 11m56.8s VUs 0202/0245 complete 18919 | 0.00 | 0.30 |
| end+5m | 2026-11-26 04:49:32 | 154 | 170 | 170 | 16×wait_woken | 16 held by workers (0 with unread bytes) | 154 | 171 | 14m56.8s VUs 0154/0245 complete 18967 | 0.00 | 0.31 |
| end+10m | 2026-11-26 04:54:32 | 74 | 90 | 90 | 16×wait_woken | 16 held by workers (0 with unread bytes) | 74 | 91 | 19m57.8s VUs 0074/0245 complete 19047 | 0.00 | 0.28 |
| tail-end | 2026-11-26 04:58:41 | 0 | 10 | 10 | 6×inet_csk_accept; 10×wait_woken | 10 held by workers (0 with unread bytes) | 0 | 1 | 24m02.8s VUs 0000/0245 complete 19121 | 0.00 | 22.26 |

What the table shows, as numbers:
- After the window ended and the app and database went idle (CPU 0.00% and 0.30%), the listen queue of port 9000 held 202 to 218 connections for 12 minutes and then fell to 0 at the end of the tail. Each time, "ESTAB in app" is exactly Recv-Q + 16 (234 = 218 + 16; 218 = 202 + 16; 170 = 154 + 16; 90 = 74 + 16), and the sockets held by workers are 16 each time in the three looks that list them.
- Recv-Q fell by 16 each minute: 218 (04:45:32) to 202 (04:46:32), 154 at 04:49:32 (48 in 3 minutes), 74 at 04:54:32 (80 in 5 minutes); the load tool's count of completed iterations rose in the same steps (18,903 to 18,919 to 18,967 to 19,047).
- In every look after the window all 16 workers slept in `wait_woken`, none in `accept`. In the 16 sockets they held, the receive queue was 0 (no unread bytes); the connections not yet taken (inode 0, `unaccepted ESTAB` above) are the ones with bytes waiting.
- At the last look (04:58:41, Recv-Q 0, 10 connections left), 6 workers were in `inet_csk_accept` and 10 still held a connection; the app and database CPU had started again (22.26% for the database) for the last requests.
- The web server's `stub_status` (port 8081) read 246 active connections for the whole stall and 1 at the end.
- No container restarted or was killed by the memory limit (`clues/containers.txt`); the php-fpm log has no `max_children` or slow line (`clues/app.log`); the web server's error log has no line (`clues/web-error.log`, empty); `Connection_errors_max_connections` 0 (`clues/db-status.txt`; `Max_used_connections` 28 and `Aborted_connects` 9 count since the database was started at 04:32, with the sessions of the start and of the looks).

The three things that confirm the cause, read against the looks after the window (end+2m, end+5m, end+10m):
1. Recv-Q above 0: **yes** (202, 154, 74; also 213 to 218 in the first three looks).
2. More than 16 connections from the web container to port 9000: **yes** (218, 170, 90; 229 to 234 in the first three looks).
3. The workers wait on a connection, not in `accept`: **yes as far as the kernel's wait function shows**: 16 of 16 in `wait_woken`, each holding one established connection with nothing to read, none in `accept`. What was not seen: the system call itself (`/proc/<pid>/syscall` is not readable by the app's user, so `read` could not be told from another socket wait by name), and the web server closing its idle connections every 60 s (not observed; the drain of exactly 16 a minute fits the 60 s default of the upstream `keepalive_timeout`, but that figure is not read from a log).

So the three conditions held together at each of the three looks after the window (and the first two conditions in the three looks before them); the queue was empty only at the last look. The reading that the 16 php-fpm workers are held by idle upstream connections of the web server while other requests wait in the listen queue is what the looks show; the look at 32 rps in `cal8-r32/` (a stall of about 30 minutes, 5 timed-out requests) and this one (24 minutes, none timed out) are two runs of one rate and are not the same length, so the length of the tail is not a number to rely on.
Nothing was changed in the lab to look; the settings were not changed to test the cause, and no run was made to see that a change removes it.

#### State left

The compose files were put back (`lab-config-b restore`, `git status --short -- poc/lab` empty, SHA-256 `604aedff…` and `f495eab5…`), the load tool, tools, web and app containers were stopped with `docker stop`, the database with `docker stop -t 180` (**`Shutdown complete`**, 2026-11-25T19:59:40Z) and the stack taken down without `-v` (`cal8-r32-diag/shutdown.txt`). The volumes are kept (54); no `kill`, `prune` or `--purge`.

Two things were changed in the copies kept here before the commit, not in what the run wrote: the absolute paths in `obs/observe-log.txt` and `cal8-r32-diag.out.txt` were replaced by `<obs>` and `<out>`, and the last line of `run_diag.sh` (a one-line notice to the person who started the run) was left out; in `start.txt` the two CPU limits in nanoCPUs were written as `10e9` and `2e9`.

## A confirming run at 32 rps, after the web server's upstream keepalive was taken out (written at 2026-11-26 08:22, before it is measured; `cal8-r32-confirm/`)

The diagnostic run (`cal8-r32-diag/`) showed the stall after the load window; the Bagisto lab's web server file was then changed (commit `efe5bc2`: its own `lab-bagisto.conf`, the shared `lab.conf` without `keepalive 64;` and `fastcgi_keep_conn on;`, nothing else; decisions D279 section 2 and its appendix). This run asks one question: is the stall gone?

**This run only confirms. It is not a search run.** Its value (rate reached, database and app use, dropped arrivals, valid or not) is used for nothing: not for any R, not for the calibration, not for the gate that takes effect, not for any pass or fail. Whether the rate is reached at 32 rps does not decide the question either (decisions D279 section 4).

- Conditions are those of the diagnostic run (`cal8-r32-diag/`): cal-8, even mix, 1,000 rows, 32 rps, 10 minutes at a constant rate with the first 2 discarded, request timeout 1,800 s, the flag `--search-gate-advisory`, the same plan and the same rates for the other runs (R_0 = 2, R_1 = 8, R_2 = 1, R_3 = 0.2, R_4 = 1; the one measured is cal-8). The only change in the lab is the web server's file.
- The output directory is `cal8-r32-confirm/`, new; it did not exist before the run, and no existing directory is named by it.
- Looks (read only, the same tool and the same times as the diagnostic run): one minute before the end of the window, 5 seconds, 1, 2, 5 and 10 minutes after it, and right after the tail ends. The lab is **not taken down before the look at 10 minutes after the window**.
- How it is judged is written in decisions D279 section 4 and its appendix (section 8, B1 and B2) and is not restated or changed here: the settings of the run are compared with the change first (`settings-nginx.txt` has no upstream `keepalive` and no `fastcgi_keep_conn on` and has `fastcgi_read_timeout 1800s`; `settings-fpm.txt` has `pm.max_children` 16); then (i) no steps of 15 to 16 requests about every 60 seconds after the window, read as the diagnostic run's steps were, and (ii) the receive queue of port 9000 is 0 at 5 and at 10 minutes after the window. A reading that is split or cannot be taken counts as not fixed.

### The confirming run at 32 rps (2026-11-26 08:22 to 08:43; `cal8-r32-confirm/`): what was seen, then the conditions

Run as written above, after the commit that fixed the conditions (`a2f749b`): cal-8, even mix, 1,000 rows (the first line of `tables-before.tsv` is the table `addresses` at 1000 rows), 32 rps, `--search-gate-advisory`, `--out` a new directory, the B settings on the web server's own file (`lab-config-b apply`; SHA-256 of `lab-bagisto.conf` with B on `4efb82d3…`) and on the PHP ini. Before the run: `git status --short -- poc/lab` was empty (before B went on), HEAD `a2f749b`, free 15 GiB total and 10 available, 882 GB free on the disk, Windows Update paused until 2026-11-28 15:02 UTC; the database's start log (the whole log, 13 lines, `start.txt`) has no recovery or error line; `check-limits --require=unlimited --request-timeout=1800s` read `LIMITS OK`; the observation tool's self-check before the run is `observe-selftest-before-run.txt`. The load window ran 08:22:42 to 08:32:42.
**Its value is used for nothing.** The run itself: rate reached 31.9958 (ratio 0.9999), `rps_status` invalid (`load_tool_dropped_arrivals`, 16 dropped arrivals), database 41.97% (not read as a rate), app 78.16%, 15,358 requests in the window, all status 200, 12 requests finished after the window, the longest after 925 ms; requests in flight at the window's start 14, at its end 12 (the diagnostic run: 124 and 231; `cal8-r32/`: 164 and 568).

**One change in how the looks were taken.** `driver.sh` (the diagnostic run's, unchanged) skips every look once the load tool's tail has ended. The tail ended at 08:32:54, 12 seconds after the window, so `driver.sh` took only the looks before the window's end, 5 s after it and at the tail's end (`obs/`). A second observer, `driver2.sh` (the same `observe.sh`, the looks 15 seconds after the times of `driver.sh`, and it goes on after the tail has ended), had been started at 08:23:44 for this case; the looks 1, 2, 5 and 10 minutes after the window are its (`obs2/`). Both are inside the ±30 s the decision allows. The lab was not taken down before the look 10 minutes after the window (08:42:57).

Looks (`obs2/`; `observation-table.md` is made by `summarize_obs.py`; the `app%` and `db%` columns are `docker stats` readings where one core is 100%; the load tool had no request left from the look at 5 s on):

| look | time | Recv-Q (:9000) | ESTAB in app | ESTAB web->:9000 | workers by wchan | worker sockets with data waiting | unaccepted ESTAB | stub_status Active | k6 running line | app% | db% |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| w-1min | 2026-11-26 08:31:57 | 0 | 6 | 15 | 8×0; 1×do_sys_poll; 7×inet_csk_accept | 2 held by workers (2 with unread bytes) | 0 | 177 | 09m13.8s VUs 0013/0176 complete 17699 | 835.26 | 92.69 |
| end+5s | 2026-11-26 08:33:02 | 0 | 0 | 0 | 16×inet_csk_accept | 0 held by workers (0 with unread bytes) | 0 | 1 | 10m00.9s VUs 0000/0176 complete 19190 | 0.00 | 0.28 |
| end+1m | 2026-11-26 08:33:57 | 0 | 0 | 0 | 16×inet_csk_accept | 0 held by workers (0 with unread bytes) | 0 | 1 | 10m00.9s VUs 0000/0176 complete 19190 | 0.00 | 0.29 |
| end+2m | 2026-11-26 08:34:57 | 0 | 0 | 0 | 16×inet_csk_accept | 0 held by workers (0 with unread bytes) | 0 | 1 | 10m00.9s VUs 0000/0176 complete 19190 | 0.00 | 3.45 |
| end+5m | 2026-11-26 08:37:57 | 0 | 0 | 0 | 16×inet_csk_accept | 0 held by workers (0 with unread bytes) | 0 | 1 | 10m00.9s VUs 0000/0176 complete 19190 | 0.00 | 0.28 |
| end+10m | 2026-11-26 08:42:57 | 0 | 0 | 0 | 16×inet_csk_accept | 0 held by workers (0 with unread bytes) | 0 | 1 | 10m00.9s VUs 0000/0176 complete 19190 | 0.00 | 0.73 |

The same looks from `driver.sh` (`obs/`): w-1min 08:31:42 Recv-Q 0, 6 established in the app, 15 from the web; end+5s 08:32:47 Recv-Q 0, 3 in the app, 0 from the web, 16 of 16 workers in `inet_csk_accept`; tail-end 08:32:51 Recv-Q 0, 0 and 0, 16 in `inet_csk_accept`.

Checks against the decision (D279 section 4 and its appendix, section 8):
- **Settings of the run (B2)**: `cal-8/settings-nginx.txt` has no `keepalive <number>;` inside an upstream and no `fastcgi_keep_conn on` (the one line with `keepalive` is the client side's `keepalive_timeout 65;`) and has `fastcgi_read_timeout 1800s`; `cal-8/settings-fpm.txt` has `pm.max_children = 16`. The run was made with the change.
- **(i) the steps after the window**: read with `read_steps.py` (the diagnostic run's reading, checked on `cal8-r32-diag/cal-8/k6-output.txt`, where it gives `have`: 13 steps of 16, 60 s apart). Here the load tool ended 12 seconds after the window (`k6.exit` 0, last line `10m00.9s, VUs 0000/0176, 19190 complete`), with no rise of `complete` after 19,190 beyond one: no step of 13 to 18, none of 15 to 16 (`steps-reading.txt`): type iii **none**. The load tool ended within 5 minutes of the window, and no request was unanswered at 10 minutes. (i) **holds**.
- **(ii) the receive queue of port 9000**: 0 at 5 minutes after the window (08:37:57, aimed at 08:37:42) and 0 at 10 minutes after (08:42:57, aimed at 08:42:42); at 2 minutes (not used in the reading) also 0. Both looks were taken within 30 s of their aim and the columns read. (ii) **holds**.
- Neither condition failed, none was split, and every look needed was taken: by the rule written before the run, **the stall is gone** (the conclusion is the rule's; this README adds no other).

What the numbers do not show, written because it limits the reading: in this run no queue formed in the window (Recv-Q 0 at the look a minute before the window's end, 15 connections from the web server, 12 requests in flight at the end), while the diagnostic run had Recv-Q 213 and 231 in flight at the same point and `cal8-r32/` had 568 in flight. So the run shows that the stall did not follow here; it does not show that the same load would have made a queue under the old file, and the two earlier runs at this rate did not tail alike either (about 30 minutes and about 24 minutes). The rate was not reached (16 arrivals dropped) in this run too; by the decision that is not used. The php-fpm log has one line from the run, a PHP warning at 23:22:47 UTC (`mkdir(): File exists` in the scratch directory's boot code) at the start of the load; the web server's log (`clues/web-error.log`, the tail of `docker logs`) has only the warnings that a response was buffered to a temporary file, and no `error`, `emerg` or `crit` line. No container restarted or was killed by the memory limit (`clues/containers.txt`).

#### State left

The stack is **left up** (the conclusion is "fixed"; the search starts again after the check): the five containers run, the database of 1,000 rows is read by the app, the B settings are still on the two files (`git status --short -- poc/lab` shows `lab-bagisto.conf` and `zz-lab.ini` modified by the B diff; `lab-config-b restore` is not run). No `kill`, `prune`, `down -v` or `--purge`; 54 volumes. The other lab was not touched. In the copies kept here the absolute paths in `obs/observe-log.txt`, `obs2/observe-log.txt` and `cal8-r32-confirm.out.txt` were replaced by `<obs>`, `<obs2>`, `<out>`, `<state>`, `<repo>`, `<home>`, and the last line of `run_confirm.sh` (a one-line notice to the person who started the run) was left out; in `start.txt` the two CPU limits in nanoCPUs were written as `10e9` and `2e9`.
