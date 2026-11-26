# Searching the rates of the eight calibration runs again, after the web server's upstream keepalive was taken out (Bagisto lab)

The searches in `../ten-cpus/` and `../cal6-rps-0.45/` were made while the lab's web server held the php-fpm workers on idle upstream connections (the stall after the load window, decisions D278 and D279).
By D279 (section 2, points 2 to 4) every one of those 22 runs is kept as a record below the fix and none is used: not the runs taken as R, not the invalid runs, not the runs without the mark after the window.
D273's ruling on R_0 and its section 3 are withdrawn by D279 (section 2, point 3). The search of R_0 to R_5 starts again from the beginning, by the same rules. The fix was confirmed by one run (`../ten-cpus/cal8-r32-confirm/`, commit `f7dab84`).

**This file is written before the first run of this search is measured** (the time is in `search-log.txt`). What is under "Before the first run" was fixed then; the results are added below the line "Results", and nothing above it is changed.

## Before the first run

### The lab as it is left by the confirming run

| What | Value |
| --- | --- |
| Lab code | the lab's code is unchanged since `efe5bc2`; this search starts from HEAD `f7dab84` and the commit that follows it (a sentence put by mistake into the diagnostic run's paragraph of `../ten-cpus/README.md` is taken out) |
| The fix | `bin/with-bagisto` mounts `lab-bagisto.conf` (no upstream `keepalive`, no `fastcgi_keep_conn on`); `pm.max_children` 16 as before; the B settings (`bin/lab-config-b apply`) are on the web server's own file and on the PHP ini, left on from the confirming run, so `git status --short -- poc/lab` shows those two files changed |
| CPUs | app and web 10, database 2, load tool 2, tools 2 (as in `../ten-cpus/README.md`) |
| Limits | off (PHP `max_execution_time` 0, `memory_limit` 4G, pool `request_terminate_timeout` 0s, nginx `fastcgi_read_timeout` 1800s); the load tool's request timeout is 1800 s |
| Databases | the one volume's databases of 30,000, 10,000, 3,000 and 1,000 rows, built and analysed before; no ANALYZE is run between runs; the app is switched to a run's count by the entry's `--switch-scale` |
| Machine | nothing else heavy is run while a run is measured; Windows Update is paused until 2026-11-28 15:02 UTC |

### The rules (not changed here; named so that the reading of each is fixed before a run)

- **Bands and stages** (D183 and its amendments, D263, D270, D272): a run is 10 minutes at a constant arrival rate, the first 2 minutes discarded, limits off, request timeout 1800 s. The band of the database's utilization (2 CPUs) is 20% to 30%.
- **R_3 (cal-6, 30,000 rows) and R_4 (cal-7, 30,000 rows), R_2 (cal-5, 30,000 rows): the stage values from the top down** — 0.05, 0.07, 0.1, 0.15, 0.2, 0.3, 0.45, 0.7, 1.0, 1.5, 2.2, 3.3, 5.0 — the first value whose utilization is inside the band is the rate.
  Above 30% → the next stage value down. The first value below 20% → stop and report. The second value below 20% → the geometric mean of the two (rounded to two decimals) once; not in the band → the tried value whose utilization is nearest 25% is taken and recorded as outside the band. The second value also above 30% → stop and report.
  A run whose rate is not reached (`rps_status` not `valid`) is invalid even if its utilization is in the band (D270, decision 2): it is not taken, and the search goes to the next value down.
- **R_0 (cal-1, 10,000 rows), R_1 (cal-4, 3,000 rows), R_5 (cal-8, 1,000 rows): the doubling search from 1 rps** — 1, 2, 4, 8, … — the first rate whose utilization is inside the band is the rate. A first run above 30% stops and reports. If a rate is not reached the geometric mean of the previous rate and this one is tried once; not reached or outside the band → stop and report (D272, decision 1).
  R_0 of 16 or more: the rest is not measured and the search stops with the inputs-only reading D272 (decision 3) asks for. R_5 above 32: the inputs-only gate is asked with that value and recorded before cal-8 is measured (D272, decision 2).
- **A run found inside the band in the conditions of a run is the calibration run itself and is not measured again** (D183 amendment, rule 5; D274 decision 1; D277 B1). It is handed to the formula and the gate by its directory (`--run=<id>:<dir>`) and is not put in `--measure`; scales are written for all eight runs and compared with the first line of each run's `tables-before.tsv` (D274 decision 2).
  Of D274 decision 1 only the way of handing over applies here; its named runs (cal-6 at 0.2, cal-7 at 1.0, cal-1 at 2) are among the 22 and are not used (D279 appendix).
- **The gate in front of a search run**: for the searches of R_3, R_4, R_0 and R_2 the set given to the entry has stand-in rates for the runs not being searched, chosen so that the entry's gate opens, and written before the run (see below). For the searches of R_1 and R_5 the flag `--search-gate-advisory` is used (D275, D277; D276 is withdrawn), by `SEARCH_GATE_ADVISORY=1`: a refusal is recorded and the run goes on. The flag is used for nothing else.
- **R_5 (D279 appendix, B4)**: R_5 is searched with no figure of D277 point 2 (B2) used. After R_1 is decided, with that R_1 and the R_0, R_2, R_3, R_4 found in this search, the grid of R_5 {1, 2, 4, 8, 16, 32} is asked of the inputs-only gate and the result is written before the search of R_5. If no R_5 opens the gate, R_5 is not searched: stop and report.
- **When the rule does not decide R (D279 appendix, B5)**: if the search of an R reaches the stop of D272 decision 1 (the geometric mean tried once, still outside the band or not reached) and that R's search has **one or more valid runs, the type of D183's amendment (a valid run outside the band is taken) is applied, always**. If there is no valid run, or the stop is D277's, the type is not applied and the search stops and reports. The R taken and the name of the run the type chose are written before the effective gate is called and before any calibration value is seen.
- **Same conditions** (D274 decision 3 as D279 appendix B3 reads it): for every run, `settings-ini.txt`, `settings-nginx.txt` and `limits.txt` are compared byte for byte with those of the confirming run (`../ten-cpus/cal8-r32-confirm/cal-8/`), and `settings-fpm.txt` with the time at the start of each line taken off. If any differs: stop and report. `check_run.sh` does this, with the other checks below.
- **The mark of the stall** (a stop, not a repair): after each run `check_run.sh` reads the steps after the window as the confirming run's were read (`read_steps.py`; "have" or "weak" stops), the receive queue of port 9000 once the load tool has ended (not 0 stops) and the time from the window's start to the end of the tail (more than the window and 5 minutes stops; the confirming run took 662 s).
  A stop is not repaired: it is reported.

### The starting values (the rule's own, not moved by anything seen below the fix)

| Rate | Run | Rows | Starts at | By |
| --- | --- | --- | --- | --- |
| R_3 | cal-6 (one route at 80%, the rest even) | 30,000 | 0.45 rps, then down | the desk estimate the first search used, as in `../ten-cpus/README.md` |
| R_4 | cal-7 (the second route at 80%) | 30,000 | 1.0 rps, then down | the same |
| R_0 | cal-1 (even) | 10,000 | 1 rps, doubling | D183 amendment 1, D272 |
| R_2 | cal-5 (even) | 30,000 | 1.5 rps, then down | the desk estimate of `../ten-cpus/README.md` (a mean request of the even mix costs about 0.49 s of the database at 30,000 rows) |
| R_1 | cal-4 (even) | 3,000 | 1 rps, doubling | D183 amendment 1, D272 |
| R_5 | cal-8 (even) | 1,000 | 1 rps, doubling | D183 amendment 1, D272 |

The order is R_3, R_4, then — with the real R_3 and R_4 — the inputs-only gate over the grid of R_0, R_1, R_2 (cal-1 to cal-7, and with cal-8 if every point fails), then R_0, R_2, R_1, R_5. When R_0 to R_5 are all decided this task stops: the effective gate and the calibration runs are not called here.

### The stand-ins of the R_3 runs

The same stand-ins as in the first search, fixed here before any run: R_0 = 1, R_1 = 4, R_2 = 0.2, R_4 = 0.3, and cal-8 at R_5 = 8 (cal-8 is asked of the gate only and is not measured). They are not values of any R and are not kept as such.
Asked of the gate by `gate_only.sh` now (inputs only, nothing measured; `2026-11-26 08:52:37`), with cal-6 at each value the search can reach, the entry's gate opens every time: cal-6 at 0.45 → smallest degree 0.643881 (without cal-6); 0.3 → 0.643881 (without cal-6); 0.2 → 0.591401 (without cal-8); 0.15 → 0.561721 (without cal-8); 0.1 → 0.534800 (without cal-8); all at or above 0.50.
The stand-ins of the R_4 runs are chosen after R_3 is known and written, in a commit of their own, before the first cal-7 run.

### What is kept, and where

Each run's output goes to a directory made new for it; no directory of the earlier searches is named by any run, and a name is checked not to exist before it is used. The records are copied here (`after-fix/<name>/`) after each run, with absolute paths replaced as in `../ten-cpus/README.md`.
Each window is written in `search-log.txt` with its start and end. The scripts are `search8.sh` (a run), `gate_only.sh` and `gate_real.sh` (the gate on inputs alone), `check_run.sh` and `read_steps.py` (the checks).

## Results

### R_3: cal-6 at 0.45 rps, then 0.3 rps (`cal6-r0.45/`, `cal6-r0.3/`; 2026-11-26 08:53 to 09:18)

Run through `run_one.sh` with the stand-ins written above (R_0 = 1, R_1 = 4, R_2 = 0.2, R_4 = 0.3, cal-8 at R_5 = 8 asked of the gate only), no flag, 30,000 rows, request timeout 1800 s. Before each: the lab was left up from the confirming run (HEAD `5bec431`, `git status --short -- poc/lab` = the two B files), 15 GiB total and 7 to 10 available, 882 GB free, the database's log (13 lines) had no recovery or error line.

| Rate asked | Reached (status) | Dropped arrivals | Database | App | Requests in the kept window | Taken |
| --- | --- | --- | --- | --- | --- | --- |
| 0.45 | 0.4250 (**invalid**: `load_tool_dropped_arrivals`) | 11 | 47.6212% | 99.92% | 204, all 200 | no (D270, decision 2) |
| **0.3** | 0.3000 (**valid**) | 0 | **24.6619%** | 58.95% | 144, all 200 | **R_3 = 0.3** |

Both runs passed the entry's guards (the gate opened, the write check, the limits guard, the N tables identical before and after). `check_run.sh` (`cal6-r0.45/check.txt`, `cal6-r0.3/check.txt`): settings of the run the same as the confirming run's in the four files; the count 30,000; steps after the window: at 0.3 none (7 requests finished after the window), at 0.45 one bundle of 13 and a few of 1 to 4, none of 15 to 16 (type iii none); receive queue of port 9000 after the run 0 both times; window start to end of tail 664 s and 685 s (the confirming run 662 s).
By the rule (D270 decision 2: a run whose rate is not reached is invalid even if it is in the band; D183: the next value down) **0.45 is not taken and 0.3 is the first valid run inside 20% to 30%: R_3 = 0.3**, and by rule 5 that run is cal-6's calibration run and is not measured again.
The app was at its limit (99.92%) at 0.45 and not at 0.3 (58.95%). Written at 2026-11-26 09:18:41.
