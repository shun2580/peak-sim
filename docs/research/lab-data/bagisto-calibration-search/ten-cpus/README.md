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
