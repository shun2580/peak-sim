# Searching the rates of the two skewed calibration runs, in the Bagisto lab

This directory holds the first step of the rate search for the calibration of the lab backtest on Bagisto (`docs/simulator-spec.md` section 7.4.7;
decisions D183, D185, D186, D263, D264, D266, D267, D268). The two runs searched here are the ones added by the calibration ruling when the first five cannot be
told apart: **cal-6** (the route ranked 1 in the list at 80%, the other seven together at 20%, evenly) and **cal-7** (the same for the route ranked 2), both at
30,000 rows, a count already seen in the capture (it is not a count of the backtest). Their rates are named **R_3** (cal-6) and **R_4** (cal-7) and are searched
separately, each with the rule that D183 gives for the rate of the 30,000-row even run (D266, decision 1).

**This file is written before the first search run is measured.** What follows the line "Before the first run" was fixed then. The results are added after.

## Before the first run

### The lab as it is set for the search

| What | Value |
| --- | --- |
| Stack | the Bagisto lab (`poc/lab/bin/with-bagisto`): its own project, containers, network and data volume; the BookStack lab is not touched |
| Database | `…_lab_30000` of the lab's volume, built by `bin/build-db --scale=30000` with the 123 N tables of `docs/research/bagisto-prep/n-tables.txt` at 30,000 rows and every other table at the base size (`build-30000/build-output.txt`: 149.72 s), then `ANALYZE TABLE` once on the 152 tables (`build-30000/analyze/`). No ANALYZE is run between runs |
| The app reads | scale 30,000 (`bin/use-db 30000`, the containers made again after the limits were set) |
| CPUs | database 2 (cores 0-1), app 6, as the lab's compose file fixes them |
| Limits | off: PHP `max_execution_time` 0 and `memory_limit` 4G, the pool's `request_terminate_timeout` 0s, nginx `fastcgi_read_timeout` 1800s (the settings of `poc/lab/config/ladder-b-1800s.diff`, put on by `bin/lab-config-b apply`), and the load tool's request timeout 600 s. `bin/check-limits --require=unlimited --request-timeout=600s` said `LIMITS OK` (`limits/off.txt`, `limits/off/`) |
| Host header | `measure.invalid`, the host of APP_URL, as in the capture (D267): `targets-30000.json` carries it as the header of every route |
| Entry check at 30,000 rows | `bin/entry-check` against the capture, one request to each of the 8 routes: 8 of 8 passed (status 200, the login of the route list, latch False before and after), POST answered 405 and the unknown login 400; the N tables were identical before and after (`entry-30000/`, D268) |
| Machine | 15 GiB total, 11 GiB available; 883 GB free; Windows Update paused until 2026-11-28 15:02 UTC |

### The tool

The calibration is measured from one entry only, `bin/measure-calibration`. It calls the calibration gate first, with the plans of **all seven** runs of the set,
and measures nothing if the gate refuses. Its gate call did not pass `--tables`, so the shared-table copy of the estimate (D264, decisions 1 to 3: the
N-axis routes name the pseudo table `n_shared`) gave the degree 0.000000 at every set. The entry now takes `--gate-tables=<a,b>` and gives it to the gate and to
nothing else (the guard of the N tables keeps reading the 123 real tables); without it the entry is what it was. The change and its tests are in the two
commits `fix: pass the gate tables of the calibration entry to the gate alone` and `test: pin the gate tables option of the calibration entry`
(`poc/lab/tests/calibration-gate.sh`: 51 passed, 0 failed, 0 skipped; with the line that passes the option taken out, 7 of them fail).

### The rule for R_3 and R_4 (D183 and its amendment of 2026-11-11 15:12, as D186 applied it)

Each rate is searched on its own run, in the conditions of a run (10 minutes at a constant arrival rate, the first 2 minutes discarded, limits off, database with 2 CPUs),
over the stage values of section 7.2 (0.05, 0.07, 0.1, 0.15, 0.2, 0.3, 0.45, 0.7, 1.0, 1.5, 2.2, 3.3, 5.0), from the top down, the first value that puts the database's
utilization in 20% to 30% being the rate:

1. The first value is the one a desk estimate puts nearest the band from above (below). If it gives more than 30%, the next stage value down is measured.
2. If the first value gives less than 20%, stop and report (D183).
3. If the second value gives less than 20%, the geometric mean of the two (rounded to two decimals) is measured once. If that one is not in the band either, the tried value
   whose utilization is nearest 25% is taken and recorded as "outside the band" (D183, amendment 1).
4. If the second value also gives more than 30%, the rule does not say what to do: stop and report.
5. A search run made in the conditions of a run and found in the band is the run itself and is not measured again (D183, amendment 2.2). cal-7 is searched after
   cal-6, with its own rule and its own rate.

**The desk estimate** (for choosing the first value only; never a pass or a fail). From the capture of Bagisto at 30,000 rows (`statements` and `rows_sum` of each of the eight routes
at that count), with the two coefficients BookStack's last calibration gave (`c_query` 2.84407e-4 s, `c_row` 9.37963e-7 s: another application, so a reference of order only),
the cost of a request of each route is 1.666, 0.769, 0.545, 0.289, 0.288, 0.203, 0.114 and 0.065 s, and the database's utilization on 2 CPUs at the rates:

| rps | 0.2 | 0.3 | 0.37 | 0.45 | 0.7 | 1.0 |
| --- | --- | --- | --- | --- | --- | --- |
| cal-6 (route 1 at 80%) | 14.0% | 21.0% | 25.9% | 31.4% | 48.9% | 69.9% |
| cal-7 (route 2 at 80%) | 7.1% | 10.6% | 13.1% | 15.9% | 24.7% | 35.3% |

So the first value is **0.45 for cal-6** (then 0.3, then 0.37 once) and **1.0 for cal-7** (then 0.7, then 0.84 once). The estimate leaves out the boot read of every request
(about 2N rows of `core_config`, a fixed property of the lab's requests that the capture does not count, so the real utilization is somewhat higher) and uses the coefficients of
another application. A first value that is far from the band is found by the first measurement, and rule 2 then stops the search.

### The gate at a search run, and the stand-in rates it is given

The gate looks at all seven runs, and the rates of cal-1 to cal-5 are not searched yet. For the call that measures a search run, the runs not yet searched are given a
rate they are planned at, the run being searched is given the rate under test, and the gate must open or nothing is measured (D185). The rates given are **not values of
R_0, R_1, R_2 or R_4 and are not kept as such**; they are only what lets the gate be asked. Chosen before the first run, from the table of the gate over the grid (`gate-standin/`):

- R_0 = 1 and R_1 = 4: the rates the two earlier calibrations of BookStack used, and the first value of the search of R_0 (doubling from 1).
- R_2 = 0.45: the first value D183 tries for the 30,000-row even run.
- the other skewed run at 0.7 (cal-7 at its desk-estimated band value while cal-6 is searched), and the searched run at the value under test.

At R_3 = 0.45 with cal-7 at 0.7 the gate opens at 24 of the 504 points of (R_0, R_1, R_2), the one chosen above (degree 0.507784) among them. **At R_3 = 0.3 it opens at no point
for any cal-7 rate tried (0.45, 0.7, 0.84, 1 and 1.5; the largest degree is 0.4965), and at R_3 = 0.37 only with cal-7 at 0.45 (9 points) and at no point with cal-7 at 0.7 or more**
(`gate-standin/grid-pairs-a.tsv`, which covers R_3 in 0.3, 0.37, 0.45 and 0.7 against R_4 in 0.45, 0.7, 0.84, 1 and 1.5). If the search of cal-6 goes down to 0.3 or 0.37, the
gate in front of that run will therefore refuse with the stand-ins above (and, for 0.3, with any cal-7 rate in that range), nothing will be measured, and the case goes to the maintainer.
This is a finding about the gate on inputs and is written here before any run is measured.

### Stops

Any exit of a guard other than 0 (13, 14, 15, 16, 17, 18) stops everything and nothing is retried or repaired. The load tool's own failure, a run whose rate is not reached
(`rps_status` not `valid`), any 503, any statement the write guards refuse, or a utilization that cannot be read: stop and report. No database is removed, no volume is removed, no
`--purge`, no `docker kill`; the database container is stopped with `docker stop -t 180` at the end.
