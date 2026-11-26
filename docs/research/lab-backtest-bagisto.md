# Lab backtest (B) on Bagisto: B at 190,000 rows

Status: **draft. The prediction was committed at 2026-11-27 02:25:59 (`87e8f6ec`) and has not been changed. The measurement chain stopped on 2026-11-27 at 06:35:09, at the 7th step of s-1, when the application container ran out of memory. Sections 1 to 7 are written. The verdict, the scenarios s-2 to s-5 and the closing of condition 2 are not written yet (section 8).**

This file is the record of test B on the third application, Bagisto (`docs/simulator-spec.md`, sections 7.2, 7.4.4 and 7.4.7). It follows `lab-backtest-6.md`, the sixth round on BookStack. The pass lines, the tolerances and the rule for "undecidable" are those of the spec and are not moved by this file.

What is checked is a **check against a busy period reproduced in the lab**: a prediction compared with a measurement made in the lab. Nothing here is observed on a real deployment, and this file does not claim any precision on one.

**In brief:**

1. B is the second half of condition 2 (the first half is the extrapolation test A on Bagisto, `extrapolation-test-bagisto.md`). It was set at **190,000 rows**, 6.33 times the largest size the statements were captured at (30,000 rows), on eight routes chosen by a rule that does not use the static analysis (section 1).
2. **The lab had a gap** (the web server held the application's workers on idle connections after a load window). It was found during the search of the calibration rates, fixed in the Bagisto lab only, and confirmed by one run. Every one of the 22 runs measured before the fix (14 valid, 8 invalid) was set aside and none is used (section 2).
3. The calibration has eight runs. Six rates were taken from the search, two runs were measured, and the effective gate opened (smallest degree 0.546895). **The residuals are large** (down to −14.0438 points at cal-7) and are on the side of not passing (section 3).
4. Two rehearsals were made at a count already seen in the capture (30,000 rows). They are not tests (section 4).
5. The request timeout of this B is **2,400 s, not 1,800 s** (section 5). The change was ruled after the upper bound of one request at this count had been seen and before any measured value was seen. It opens a way to measure, and that direction is disclosed (section 5.2).
6. The prediction was committed before the first measured step, by git and by the record (section 6).
7. **s-1 was measured to its 6th step; the 7th step (0.2 rps) ended with the application container killed for memory, is not judged and has no record. By the rule, the limit rps of s-1 is "not measured".** Whether to go on with s-2 to s-5 waits for the maintainer's ruling (section 7).

## 1. What was tested, and why

### 1.1 What, and why this count

B is the test of the database prediction in the lab (spec 7.2): a prediction of the database utilization of each step of five scenarios, made before any step is measured, compared with the utilization measured in the lab. The second half of condition 2 asks for the same test as BookStack's sixth round, on a second application, at a count never seen, with the prediction made before the measurement (spec 7.4.7, D261).

- **Row count: 190,000**, 6.33 times the capture's largest size (30,000 rows). The rule (spec 7.4.7) is the smallest multiple of 10,000 that is at least 5 times the capture's largest size and has not appeared as a scale in any earlier record of Bagisto (build, estimate, measurement or trial output). Two people searched separately, without seeing each other's results, by the method of D223 and D248, and the two agreed (D265, commit `0953fdbb`, 2026-11-25 12:35:32). The four candidate counts below it were counted by both as having appeared, because each has an appearance that could be read as a scale; where an appearance is ambiguous it was counted as having appeared, which makes the count larger and the test harder. The chosen count has no appearance as a scale: its literal matches are a passage of the BookStack decision record that names it as a candidate, a timestamp in a migration file name and constants in third-party code, and none is a count. The search was made up to 2026-11-25 12:28 and was read again before the prediction was made (the database of this count did not exist before 2026-11-26 19:12; `docs/research/lab-data/bagisto-b-prediction/lab/unused-190000-check.txt`).
- **Not done in that search**: no database was built or started, the row counts inside volumes were not counted, work typed directly and not recorded was not looked at.

### 1.2 What is the same as the sixth round on BookStack, and what is not

**Same** (spec 7.4.7): two CPUs for the database, an internal network with no published port, php-fpm, k6, the automatic refresh of statistics left on; the settings with the limits lifted (PHP `max_execution_time` 0, `memory_limit` 4G, php-fpm `request_terminate_timeout` 0s); the mechanism that takes a route out of the mix; the warm-up of each step; the 15 steps; the window; the stopping rules; the rule for "undecidable"; the pass lines.

**Different**, each decided and recorded before the value it could have moved was seen:

| | BookStack, sixth round | Bagisto |
| --- | --- | --- |
| Application | BookStack | Bagisto (`bagisto-prep`, the third capture) |
| Cores for the application | 6 | **10** (cores 12 to 15 added; the database's 2, the load tool's and the tools' are unchanged). Decided by the maintainer on 2026-11-25 (D271, commit `c6d9a339`). The aim of spec 7.2 is that the application does not saturate before the database; 6 is only the means. With 6 cores, the first search run of the calibration (cal-6, 0.45 rps, 30,000 rows) used 99.89% of the application's CPU, reached 0.3625 rps of the 0.45 asked, dropped 47 arrivals and left the database at 26.1014% (D270). That run is invalid |
| How the routes are chosen | the order of the lower bound of the static analysis | the rows read in the third capture (section 1.3) |
| Calibration | BookStack's own | made again for Bagisto (section 3) |
| Web server configuration | with the upstream keepalive | **without it** (section 2) |
| Request timeout | 1,800 s | **2,400 s** (section 5) |
| Row count | the count of `lab-backtest-6.md` | the count of section 1.1 |

The lab for Bagisto is a separate stack with its own containers, network and data volume (`poc/lab/bin/with-bagisto`); it shares nothing with the BookStack lab. Before and after it was started, the lists of BookStack's containers, networks and volumes were the same (`poc/lab/tests/bagisto-stack.sh`, commit `0807e6d1`). The stack's network is internal with no published port, and its five containers have no privileged mode.

### 1.3 The routes

#### Why the routes are chosen differently from BookStack

For BookStack, spec 7.2 orders the candidate routes by the order of the lower bound that the static analysis gives. That order needs the static analysis to say something about the routes. For Bagisto it says little: the count of routes in which it found at least one place that reads the database is 15 of 543 for Bagisto, against 264 of 341 for BookStack (D245). The analysis was not fitted to Bagisto, because fitting it after looking at the application would weaken the condition that the application was not seen before.

The maintainer therefore replaced the rule for this test (spec 7.4.7, D261) by one that does not use the static analysis: the candidates are ordered by the rows that the third capture saw them read at 30,000 rows. The ruling was recorded before any value of the route list, the calibration or the row count was seen (`c0b66755`, 2026-11-25 11:33:30).

#### The rule

1. **Candidates** are the routes of the extrapolation test A (57 routes) that are GET, that are among the 19 routes whose data is seen to arrive in the third capture (the "arrived list"), and that pass the write guard below. Of the 19 routes of the arrived list, 17 are GET.
2. **Order**: by `rows_sum` at 30,000 rows in `capture.json` of the third capture, largest first; a tie is broken by the string "METHOD URI". That column is the quantity the row estimate fits as "rows read" (D262, decision 2, fixed before the script was run; `8dad9821`, 2026-11-25 11:45:45). The orders by the other two columns (`cold_rows_examined`; the `rows.examined` of `routes.jsonl`) are listed for reading only and decide nothing.
3. **Selection**: the first 8. If there are fewer than 8, all of them. If there are fewer than 6, the work stops and nothing is built.
4. **The list is made by a script** (`select_lab_routes.py`, with a self-test on small made-up captures whose answers are known by hand) and committed before any load is put on the lab. It is not changed after that commit; a different list would be a different test. A missing 30,000 entry, a null `rows_sum` or unread events stop the script; a stop writes nothing.

The list, the candidates, the routes taken out and the allowed tables are in `docs/research/lab-data/bagisto-route-list/` (commit `24ba1c63`, 2026-11-25 11:49:02).

#### The routes selected

Numbered in the order of the rule. The numbers are the ones the scenarios use (route 1 is the first of the list, and so on).

| number | route |
| --- | --- |
| 1 | `GET /api/products` |
| 2 | `GET /customer/account/addresses/create` |
| 3 | `GET /admin/customers/view/{id}` |
| 4 | `GET /admin/marketing/communications/campaigns/edit/{id}` |
| 5 | `GET /admin/settings/inventory-sources/edit/{id}` |
| 6 | `GET /admin/customers/search` |
| 7 | `GET /api/categories` |
| 8 | `GET /api/categories/attributes/{attribute_id}/options` |

Candidates: 17 (GET routes of the arrived list). Taken out by the write guard: 0. Selected: 8 (the cap). The first 8 by each of the three columns are the same set, in the same order. Where a route's counts and shares are given in later sections, they are for these eight. The five scenarios (s-1 to s-5) are the ones of spec 7.4.2 over these eight routes: s-1 an even mix; s-2 the first four routes at 80%; s-3 the last four at 80%; s-4 routes 1, 2, 3 and 5 at 80%; s-5 routes 1, 3, 4 and 5 at 80% (with n = 8, "first half" is routes 1 to 4 and "second half" 5 to 8; fixed in D262, decision 3, before the list).

### 1.4 The write guard

The routes are GET, but a GET can write (a visit counter, a session). Which tables a selected route may write was therefore read from the capture's statements before any load.

- **Taken out**: a candidate that writes one of the 123 tables that grow with N is taken out of the list. A candidate that the profile marks as changing state, a write statement of empty kind, a kind of statement the rule does not cover and a pattern the script cannot read are each a stop, not a guess.
- **The allowed list is by table**, not by kind of statement. It is committed with the route list: `poc/lab/bagisto/allowed-write-tables.txt`.
- **The list for Bagisto is empty.** The statements the capture recorded for the eight selected routes are all reads, so no table is allowed. **An empty list means that no write is allowed at all**; it never falls back to the one table that the BookStack lab allows (`views`). A list that is named and cannot be read is a refusal, not a fall back (commit `0807e6d1`, 2026-11-25 12:37:09).
- **The four guards of the BookStack lab** (`lab-backtest-2.md`, section 1.6) are applied in the same form, with the table list read from the file: (1) before measuring, the capture's record of statements is checked for the selected routes; (2) at the entry, each statement is looked at before it is sent, and a write outside the allowed list stops the lab (every later request answers 503 until it is rebuilt); (3) around each scenario, the row count and `CHECKSUM TABLE` of the 123 tables, and the server's write counters, are compared with those taken before (the wrapper `poc/lab/bin/with-bagisto` passes the 123 table names of `docs/research/bagisto-prep/n-tables.txt` to the check of counts and checksums); (4) only GET and HEAD are accepted, and a statement on another schema is detected.
- **In the real run**: s-1 went through the wrapper (`second/scripts/run-chain.sh`). Its output has `WRITE CHECK OK 8 routes, 90 statement types, every write is to nothing: the list allows no write only`, `TABLES SAME as the baseline of this scenario` (126 write counters), and `WRITES SAME` after each of the six judged steps.
- **What this does not say**: the capture fixes session, cache and queue to fakes, so writes to their tables do not appear in it, and the lab entry uses the same fakes. A write that the lab sees and the capture did not is for the guard to refuse; it is not a reason to change the list. If one is found after load is put on the lab, the run stops and the maintainer is asked, because changing the list would change the routes.

### 1.5 The checks of the guards on Bagisto (before any load, at counts seen in the capture only)

- **Made-up tests** (stand-in containers, no real database of this count): RuntimeUnit 3,125, `allowed-writes.sh` 171, `write-guards.sh` 265 and `bagisto-stack.sh` (real stack, 64 checks), all passed with no failure and no skip (run for the commit `4df6fd3a`, 2026-11-25 13:45:25).
- **The real entry check** sends one request to each of the eight routes at 10,000 and at 3,000 rows. At 10,000 rows, the first time, route 3 (admin) tried after its answer to insert into the table `notifications`, which is one of the 123 tables that grow with N. The entry refused it before it was sent and set its latch; the database was not changed (counts and `CHECKSUM TABLE` the same before and after, write counters 0). By the pre-set rule the work stopped and the list was not changed (D262, decision 7). The reading (D267): the application's health check compares the host of the request with the host in its URL setting; the capture has both equal and the lab sent a different host, so the check failed and wrote a notice. **The lab's requests were given the capture's host** (D267, commit `ac982bc2`; the change itself is `4df6fd3a`), and all eight routes then passed at both counts with the state 200, the same login as the capture, no latch and no write outside the list (`1fe3a0e2`). That the insert disappeared when only the host was changed is what supports the reading. The work the host change removed (the health check's failing path: an insert and its read) is the lab's own and not the application's; the change takes work out of the database, not adds to it (D267, condition 4).
- **A reading recorded before the prediction** (D269, `33ab420a`, 2026-11-25 14:01:33): at every request Bagisto reads the table `core_config` twice, with a full scan, as part of its start-up; php-fpm starts it for each request, so this is work a real deployment has too. The capture counts statements only after the start-up, so the estimate does not contain these reads and the lab's measurement does. This works to make the measured utilization higher than the predicted one, the more so the larger N is. It was added to neither the prediction nor the verdict. The calibration, which fits measured utilization, may take up a part of it; how much is read in section 3.3 against this paragraph.

## 2. The gap in the lab, the fix, and the runs that were not used

### 2.1 The gap

While the rate of cal-8 (1,000 rows) was searched upward, the run at 32 rps ended its load window with the application and the database at almost 0% CPU, and requests stayed unanswered for about 30 minutes (D278). A diagnostic run of the same rate and the same settings (cal-8, 32 rps, 1,000 rows, nothing changed in the lab) was observed after its window at 2, 5 and 10 minutes (D278 section 9):

- the receive queue of the php-fpm port (`ss -ltn`, Recv-Q of :9000) was 202, 154 and 74;
- the established connections from the web server to that port were 218, 170 and 90, each equal to the queue plus 16;
- all 16 workers of the pool were waiting in `wait_woken`, not in accepting a connection;
- the load tool's completed count rose by 16 per minute; the queue was empty about 14 minutes after the window's end.

The reading, which the three observations settled: the web server's upstream keepalive (`keepalive 64`) together with `fastcgi_keep_conn on` tied the 16 workers (`pm static`, 16) to idle connections, so that the workers did not serve the queued requests until the web server closed those connections. D278 (section 3) read two ways in which this can hurt a measurement. A run could end invalid at a rate below the application's limit, because stuck requests held the load tool's virtual users and arrivals were dropped. A run could also show a database utilization that is too low, because work queued inside the window was done after it. The record cannot say which way each early run went.

### 2.2 The fix, and how it was judged

The maintainer ruled (2026-11-26; D279) that the fix is to take the two lines (`keepalive 64;` and `fastcgi_keep_conn on;`) out of the **Bagisto lab's** web server configuration only. The number of workers (16) was not changed. The fix is the commit `efe5bc2f` (2026-11-26 08:11:20): the Bagisto lab starts with `poc/lab/compose/nginx/lab-bagisto.conf`, which is the BookStack lab's `lab.conf` with those two lines removed and nothing else different; the BookStack lab keeps `lab.conf` (its `keepalive 64` and `fastcgi_keep_conn on` are still there).

**So the two labs' web server configurations differ**, and the BookStack records are not changed by this. This file does not say whether the BookStack runs were touched by the same gap.

How "fixed" was judged was written before the confirming run (D279 section 4, with section 8): one run of cal-8 at 32 rps and 1,000 rows, the same plan as the diagnostic run, in a new output place, with these two conditions both required:

- (i) after the window, no steps of 15 to 16 completed requests about every 60 seconds in the load tool's progress lines;
- (ii) the receive queue of :9000 is 0 at both 5 minutes and 10 minutes after the window.

and with the run's own settings files checked: no upstream `keepalive` and no `fastcgi_keep_conn`, `fastcgi_read_timeout 1800s`, `pm.max_children` 16. A run that did not meet them, could not be observed, or left the reading split would count as "not fixed" and the work would stop and ask. The rate, the utilization and the validity of this run were not used for anything.

Result (commit `f7dab84a`, 2026-11-26 08:44:45; `docs/research/lab-data/bagisto-calibration-search/ten-cpus/cal8-r32-confirm/`): at 1 minute before the window's end the queue was 0; at 5 seconds, 1, 2, 5 and 10 minutes after it, the queue was 0 and the established connections from the web server were 0, and the 16 workers were in `inet_csk_accept`; the load tool's completed count went from 19,189 to 19,190 across the end, and the reading of the steps found none (steps of 15 to 16: 0; runs of 13 to 18: 0). The settings files showed the lines required. Both conditions were met.

### 2.3 What was thrown away

By the ruling, **every run measured in the Bagisto lab before the fix is kept and none is used**: not the runs that had been taken as a rate, not the invalid ones, not the ones without the mark after the window (D279, section 2, point 2). The count (D279, section 3, taken from the committed records that have a `record.json`):

| place | runs measured | valid | invalid |
| --- | ---: | ---: | ---: |
| `bagisto-calibration-search/cal6-rps-0.45/` (6-core application, set aside in D271) | 1 | 0 | 1 |
| `bagisto-calibration-search/ten-cpus/` (10-core application) | 21 | 14 | 7 |
| **total** | **22** | **14** | **8** |

Of the 21 in `ten-cpus/`: 5 had been taken as rates (cal-1 at 2, cal-4 at 8, cal-5 at 1, cal-6 at 0.2, cal-7 at 1); 9 were valid and not taken; 7 were invalid (one of them the diagnostic run). Twelve of the 22 showed a mark after the window (a step of 16 requests a minute and the like) and 10 did not; **all 22 are set aside whichever they showed**. The run `ten-cpus/cal8-r1/` stopped before it was measured (no `record.json`) and is not counted. The ruling of D273 that took cal-1 at 2 rps as R_0 rested on those runs, and it was withdrawn with them (D279, point 3); the rest of D273 (the treatment of two lines of a local path in the history) stands. The search of all six rates was made again from the start, by the same rules (section 3.1). The records of the new search are in a separate place, `bagisto-calibration-search/after-fix/`.

## 3. The calibration

### 3.1 The set, the gate, and the rules of the search

BookStack's coefficients are not used: the tool is used by calibrating per application. The calibration for Bagisto is made at **N_0 = 10,000** rows, a count already seen in Bagisto's capture; it is not a test count.

The set was decided by the maintainer on 2026-11-25 12:10 (D263), before any value of the calibration was seen (commit `70423692`, 12:12:01):

- **cal-1 to cal-5**, all with an even mix of the routes. cal-1, cal-2 and cal-3 are at N_0 rows; cal-4 is at 0.3 × N_0 = 3,000 rows; cal-5 is at 3 × N_0 = 30,000 rows. How R_2 is chosen for cal-5 is as in D183.
- **The gate** on how the set is put together (D185, D186) is called. It reads the inputs only and acts before anything is measured. If it passes, the runs calibrate.
- **Only if the gate fails**, cal-6 and cal-7 are added and the gate is called again. cal-6 puts 80% of the load on the route numbered 1, and the remaining 20% evenly on the others; cal-7 does the same with the route numbered 2. Both are at 3 × N_0 rows, and R is chosen as for cal-5. If the gate still fails, nothing is measured and the maintainer is asked.
- **A further step, ruled in advance** (D271, point 3, before any rate was seen): if the effective gate fails on cal-1 to cal-7, cal-8 is added (1,000 rows, 0.1 × N_0, an even mix, R chosen as for cal-4) and the gate is called again.

**What happened to the gate before measuring** (inputs only, nothing measured, for reading and not for pass or fail; D264, decisions 4 to 6): on the grid of rates the search can produce, cal-1 to cal-5 passed at 0 of 504 points (largest degree 0.318796, threshold 0.5), so cal-6 and cal-7 were added at once (`3147dac7`, 2026-11-25 13:06:47); cal-1 to cal-7 passed at 338 of 7,056 points (0.571967 at best) (D266). Later cal-8 was added to the grid (D272, record `b950a6f4`, 2026-11-25 15:29:07): cal-1 to cal-7 passed at 315 of 28,224 points (1.1%) and cal-1 to cal-8 at 13.3% of a larger grid (`b950a6f4`). The set used is therefore **cal-1 to cal-8**. Following D266, cal-6 and cal-7 have rates of their own (R_3 for cal-6, R_4 for cal-7), searched by the same rule as cal-5.

**The rule of the search** (D183 and its amendments, D270, D272; as stated in `after-fix/README.md` before the first run):

- R_3, R_4 and R_2 (cal-6, cal-7, cal-5) are searched over the step values of spec 7.2, together with the geometric mean that the revision of D183 (2026-11-11 15:10) allows; R_0, R_1 and R_5 (cal-1, cal-4, cal-8) are searched from 1 rps by doubling. The band is 20% to 30% of the database's utilization (two cores). A run counts for the search only if the rate asked was reached (D270, decision 2).
- **When no run falls in the band** (D283, the maintainer's ruling of 2026-11-26 11:58, for every R still open): the valid run whose utilization is nearest to 25% is taken and recorded as outside the band, with its value, and nothing more is measured. If that utilization is below 10% or above 40%, or there is no valid run, the work stops and asks.
- The search of R_1 and R_5 was run with a flag that lets a run go on when the entry's gate (read over the stand-in rates of the runs not yet searched) refuses (D275, D277). The flag is used for nothing else, and the effective gate (section 3.2) was called without it.
- A run that is taken is the calibration run itself and is not measured again (D274, decision 1; D183 amendment 2.2). For each run measured, the lab's settings files were compared with the confirming run's: byte for byte, with the two notice lines of the web server's configuration test read as the rule of D282 says (each as a whole line exactly once, the rest byte for byte); the row count of each run's tables was read from its first record; and the receive queue and the marks after the window were checked.

### 3.2 The rates, run by run

The record is `docs/research/lab-data/bagisto-calibration-search/after-fix/README.md` and `search-log.txt` (the table is the one written before the effective gate and before any calibration run, commit `20a3e407`, 2026-11-26 13:24:25). "Inside" or "outside" is read against the band of 20% to 30%. Every run listed is valid unless marked.

| R | rate (rps) | run taken | inside or outside the band | database utilization of the run taken | runs measured by the rule (rps: database utilization) |
| --- | ---: | --- | --- | ---: | --- |
| R_0 | 2.83 | cal-1 | inside | 20.4348% | 1: 6.8501%; 2: 13.5006%; 4: 33.8993%; 2.83: 20.4348% |
| R_1 | 8 | cal-4 | inside | 20.1003% | 1: 3.0625%; 2: 5.0240%; 4: 9.5525%; 8: 20.1003% |
| R_2 | 1.0 | cal-5 | inside | 23.8785% | 1.5: 45.4000%; 1.0: 23.8785% |
| R_3 | 0.3 | cal-6 | inside | 24.6619% | 0.45: 47.6212% (invalid: 0.4250 reached); 0.3: 24.6619% |
| R_4 | 1.0 | cal-7 | **outside, below the band** | **18.2450%** | 1.0: 18.2450% (no other value measured) |
| R_5 | 16 | cal-8 | **outside, below the band** | **17.6836%** | 1: 1.9045%; 2: 2.9873%; 4: 4.8429%; 8: 8.9485%; 16: 17.6836%; 32: 42.4615% (nearest 25%: 16, 7.3164 points away; 32 is 17.4615 points away) |

How the two outside runs came to be taken:

- **R_4.** The first and only run of cal-7 (1.0 rps, the first step value) came out at 18.2450%, below the band. The rule as then written (D280) stopped there. The implementer's proposal to take it as just below the band was put to the maintainer, who adopted it on 2026-11-26 11:58 (D283, point 1). **That decision was made after 18.2450% had been seen** (D280 says so in its own text). No run of cal-7 at a higher rate was measured.
- **R_5.** The doubling crossed the band (16 rps below it, 32 rps above it), no run fell inside, and by D283 (point 2, with its addendum point 2) the geometric mean was not measured; the run nearest to 25% was taken. 17.6836% is at least 10% and at most 40%, so the stop of that ruling did not apply. That ruling's rule was fixed before any R_5 run was measured.
- **R_0, R_1, R_2, R_3** were decided by the rule as it was written, with a run inside the band. R_0 needed the geometric mean of 2 and 4 rps, tried once (D281, written after the three doubling runs were seen and before the 2.83 run was); R_1 was taken after the web server's two notice lines had been read as D282 says (the 8 rps run's file differed from the confirming run's only in the place of those two lines, and the reading was decided after the run and applied to the runs before it, none of which changed).

All the runs of this table were made with the application at 10 cores (section 1.2) and with the web server configuration of section 2.2.

### 3.3 The effective gate, the runs measured, the coefficients and the residuals

**The effective gate** is the one the measuring entry calls on the real plan after R has been decided. It was called without the search flag, before each of cal-2 and cal-3 and again alone with `--gate-only` (`bagisto-calibration/fit/gate-only.out.txt`): **open** (exit 0), smallest degree **0.546895** (the set without cal-8), threshold 0.50; the other sets' degrees are 0.671844 to 0.737940. The tool's entry does not measure if the gate refuses. Its output was printed by the tool before each of the two runs began, and it was committed afterwards, with the results (`fec43eb7`, 2026-11-26 13:50:07); the commit that precedes the runs (`20a3e407`, 13:24:25) holds the rule and the plan and not the gate's result. So the order "gate, then measurement" is shown by the tool's behaviour and by the calibration log (the start of cal-2 at 13:24:33), and not by a commit that holds the gate's result before the measurement.

**The eight runs**, and how each was handed to the formula (D274; `--run-scale` written out for all eight and checked against the first line of each run's `tables-before.tsv`):

| run | mix | rows | rate (rps) | database | where it comes from |
| --- | --- | ---: | ---: | ---: | --- |
| cal-1 | even | 10,000 | 2.83 | 20.4348% | the search |
| cal-2 | first four routes at 80% | 10,000 | 2.83 | 36.9806% | **measured**, app 81.84% |
| cal-3 | routes 1, 3, 5, 7 at 80% | 10,000 | 2.83 | 32.8299% | **measured**, app 78.33% |
| cal-4 | even | 3,000 | 8 | 20.1003% | the search |
| cal-5 | even | 30,000 | 1.0 | 23.8785% | the search |
| cal-6 | route 1 at 80% | 30,000 | 0.3 | 24.6619% | the search |
| cal-7 | route 2 at 80% | 30,000 | 1.0 | 18.2450% | the search |
| cal-8 | even | 1,000 | 16 | 17.6836% | the search |

cal-2 and cal-3 were measured at R_0 = 2.83 rps for 10 minutes with the first 2 minutes discarded, limits off, request timeout 1,800 s. Both were valid (2.8333 rps reached, 0 dropped, 1,360 requests in the kept window, all 200); the application stayed below 90% of its CPUs (condition (b) of D271 is not breached); the settings files were the same as the confirming run's, the count read 10,000, the receive queue after the run was 0, and there was no mark after the window (`bagisto-calibration/`, `20a3e407` and `fec43eb7`). Before cal-2 the application and web containers were recreated by the entry's switch from 1,000 to 10,000 rows; the volumes were not touched.

**The coefficients** (`make-calibration-scenario` with `--tables=n_shared` and `--cores=2`, then `recalibrate` with the shared-table estimate; `bagisto-calibration/fit/`; nothing was predicted by this dry run):

| | |
| --- | --- |
| `c_query` | 3.79962e-4 |
| `c_row` | 8.52974e-7 |
| a coefficient cut to 0 | none |
| warnings | none |
| squared residual (load) | 0.130607 |

The width, from the nine fits that leave one run out: `c_query` 1.56060e-4 to 6.08743e-4, `c_row` 7.99257e-7 to 1.01285e-6, none cut. The fits that use queries only (`c_query` 1.93037e-3, squared residual 1.35920) and rows only (`c_row` 9.07804e-7, 0.149962) are shown by the tool and not used.

**The residuals** (observed minus fitted, in percentage points of the database's utilization):

| run | observed (%) | fitted (%) | residual |
| --- | ---: | ---: | ---: |
| cal-1 | 20.4348 | 21.9081 | −1.4733 |
| cal-2 | 36.9806 | 30.2983 | +6.6823 |
| cal-3 | 32.8299 | 25.8669 | +6.9631 |
| cal-4 | 20.1003 | 20.9247 | −0.8244 |
| cal-5 | 23.8785 | 22.5421 | +1.3365 |
| cal-6 | 24.6619 | 19.1129 | +5.5490 |
| cal-7 | 18.2450 | 32.2889 | −14.0438 |
| cal-8 | 17.6836 | 18.5345 | −0.8509 |

**The residuals are large, and they are on the side of not passing.** They are larger than the first four runs of BookStack's seven-run calibration, whose residuals were at most 0.47 point (`calibration-r4/`). The two-coefficient formula fits Bagisto's eight runs less closely than it fitted BookStack's; a looser fit makes a prediction that agrees with the measurement less likely, so it cannot favour a pass. That is a reading of the direction, and not a measured effect on the prediction. Nothing was changed in response (the rule, the formula, the set and the runs): changing any of them after seeing the residuals would be a choice made on a value that has been seen. The residuals are written here so that section 8 can read the prediction against them. cal-7 and cal-8 are the two runs taken below the band, and cal-2 and cal-3 are above it; the calibration runs are not held to the band.

**How much of the start-up reads of section 1.5 the coefficients took up** is not separated in this record: the calibration fits measured utilization, which contains those reads, and the record does not split them by count.

### 3.4 The estimate, and the table that holds N

The estimate was made from the third capture, which is the same one the extrapolation test A used (largest size 30,000 rows). The lab's database is built the same way as the capture's (123 tables that grow together by the same N, the generator, the profile); the match is shown in the record (D262, decision 9). Both build the tables and run ANALYZE once afterwards. The capture then fixes the statistics (it turns off the automatic refresh of statistics), while the lab leaves the automatic refresh on after ANALYZE, at its default (D181). This is the same shape as for BookStack, and it is on that precedent that D262, decision 9, reused the capture of 30,000 rows.

**The shared N table (D264, decision 1).** In Bagisto's estimate some routes whose growth axis is N have no table named (the static analysis seldom reaches the table; D245). With no table, the tool cannot look up N for that route and treats its cost as unknown, so a calibration made from those routes alone would fail for a missing input, not for the way the set was put together. In a copy of the estimate, the table field of every entry whose axis is N and whose table is empty was set to the stand-in name `n_shared`, and **nothing else was changed**: not the formulas, not the coefficients, not any other field. The original estimate is not changed. The record holds the copy's script, the SHA-256 of the original and of the copy, and a machine check that the copy equals the original when the table field is left out (for this count: 119 of the 120 route-index entries changed, every changed line a table line; `bagisto-b-prediction/shared/share-proof.json`). The same copy is used by the gate, the rehearsals and the prediction. The table is used only as a key to look up N, and the formula is evaluated with N alone; the lab builds all 123 tables at the same N, so a route's N and the scenario's row count are the same number whichever name is used. The two meanings of the tables flag are kept apart: the estimate, the gate and the simulation take `n_shared`; the guard's check of counts and checksums and the build take the real 123 tables.

The estimate for this count is `58107a2c` (2026-11-26 19:29:56): 190,000-row database built 19:12:13 to 19:25:49 (802.89 s, migrations 17.5 s and filling 760.83 s), ANALYZE once on 152 of 152 tables (status OK), the plans of the statements from `bin/explain-at-scale` (432 statement types, 3 `plan_unstable`, 56 `plan_changes_at_scale`), the estimate and the marks each made twice with the same bytes (41 of the 63 routes carry a mark). No request was sent to the database of this count before the prediction was committed (section 6).

## 4. The rehearsals (not tests)

Both rehearsals were made at 30,000 rows, a count already seen in the capture, on a throw-away clone of the repository with three names changed (the compose project, the name prefix and the image tag; the diff was read), in a stack of their own with their own data volume. Their numbers are not a test, are not looked at for their quality, and are carried into nothing (`docs/research/lab-data/dryrun-25/README.md`, `dryrun-2400/README.md`).

### 4.1 The rehearsal of the whole chain (`dryrun-25`; plan `3212d44a`, 2026-11-26 14:12:33; results `cc500d0d`, 19:01:58)

- s-1 to s-5, two steps each (0.025 and 0.035 rps), with the settings, the tools, the order and the way of deciding the warm-up of the real run. All ten steps were valid, with the achieved rate equal to the decided one, 0 dropped and every status 200; each scenario sent 8 routes and none was taken out; the write check, the table check and the write counters were satisfied at every step; memory showed no kill; the database log had no recovery or error line.
- The verdict script (the committed one, unchanged) ran to the end; it said "undecidable" (0 usable steps, 0 scenarios with a limit), which is the right answer for two low steps. No fault of the script was found.
- **Length.** From the rehearsal's own times the real chain was expected to take from about 9.5 hours (K = 6 steps a scenario and a warm-up W of 231 s) to about 31.5 hours (K = 15 and W = 900 s), K and W being fixed only by the real prediction.
- **Two things written before the prediction, which the prediction then met.** The rehearsal's W was 231 s (the upper bound of one `GET /api/products` request, 230.95 s). A straight line from that to the real count would give about 1,463 s, which is over the prediction tool's stopping line (900 s). That line was a premise that had not been checked, and it was used for no choice; the real figure was 1,148.9 s (section 5), not on that line. The application container's memory was already large at low load: `memory.peak` of 1.7 to 3.6 GiB at 0.025 to 0.035 rps and 30,000 rows, against the container's 6 GiB.
- **What it could not try.** The condition that the application should not go above 90% before the database reaches 80% (D271, condition b) could not occur at the two lowest steps and was not tried.
- A statement in the rehearsal's README that a route taken out by the allocation does not stop the tool was wrong and was corrected after review (`223f7c33`, 19:09:14): the check covers every route of the scenario, before the allocation.

### 4.2 The rehearsal of the 2,400 s timeout (`dryrun-2400`; plan `a28e9f9e`, 2026-11-27 01:19:30; results `359d1e30`, 02:19:22)

- s-1 only, two steps (0.025 and 0.035 rps), 30,000 rows rebuilt for it, with the chain's own default and no timeout given. The chain ended with exit 0 (01:19:42 to 02:16:21, 56 min 39 s).
- **What it showed:** 2,400 s was accepted at every place that carries it: the chain log's first line (`request_timeout=2400s`), the load tool's request timeout and the wait at the end of a step (2,400 s; the load tool's own line showed 40 minutes), the web server's `fastcgi_read_timeout 2400s`, `check-limits` (which requires the value, `LIMITS OK` seven times) and the settings in each step's record. The string 1800 does not appear as a time limit anywhere in the chain's or the steps' output.
- The wait at the end of a step was provided and not used: no request was running at either edge of the two windows and none ended after them, so this rehearsal could not show the wait at work. Both steps were valid (0 dropped, 40 requests, all 200).
- It was reviewed independently after it was run, and passed.

## 5. The request timeout of 2,400 s

### 5.1 How it came to be

The prediction was first made at the request timeout of 1,800 s, as spec 7.4.7 then said. The stopping line of the prediction tool is half the timeout (spec 7.4.4): a request predicted to take more than half the timeout in PHP CPU time stops the tool (exit 39). It had been decided before the tool was run (`0e7a540b`, 2026-11-26 19:12:44) that exit 39 meant: stop, run nothing again, change nothing, ask. At this count the upper bound of one `GET /api/products` request was **1,148.9 s** in all five scenarios (the point reading gives 87.9 s), over the line of 900 s. The tool stopped with exit 39 (`309e05a1`, 19:30:43); there is no prediction file from that attempt; nothing was measured (`docs/research/lab-data/bagisto-b-prediction/stop/`).

The maintainer ruled on 2026-11-27 at 00:23 (D284, commit `36d21962`, 00:27:16) that **the request timeout of this B is 2,400 s**: the load tool's request timeout, the web server's `fastcgi_read_timeout`, the wait at the end of a step and the value `check-limits` requires. **What was not changed**: the formula of the stopping line (the bound against half the timeout), the rules of the marks, the rule of the warm-up (decided from the safe-side bound), the coefficient 9.11, the row count and the set of routes. The line becomes 1,200 s, and the warm-up W is then fixed by the rule from the bound 1,148.9 s: **1,149 s**. Not taken, because each changes a rule or the set to be measured: a change in the reading of the line or in the coefficient; a different count; a different set of routes (leaving out `GET /api/products`). The spec has the change as a sentence in 7.4.7 (`36d21962`); the settings are in `135bb585` (2026-11-27 00:56:03), and a review of the ruling and of the settings' diff passed (`ce51e53a`, 01:13:30).

### 5.2 The three disclosures

1. **It is a decision made after a value was seen.** The change was ruled after the upper bound of one request at this count (1,148.9 s) was seen and before any measured value was seen. It opens a way to measure and can work in favour of a pass. That direction is not hidden here.
2. **The calibration was measured at 1,800 s.** The eight calibration runs were measured with the request timeout at 1,800 s. The calibration's requests are short, and none reached the timeout; the longest request in each run (the load tool's maximum duration over the requests sent inside the window, `summary.json`) is, in seconds: cal-1 8.893520, cal-2 10.926418, cal-3 10.404857, cal-4 2.803058, cal-5 26.280403, cal-6 **27.056375**, cal-7 23.498909, cal-8 0.981196. In all eight runs the counts of timeouts, 504 answers, failures and dropped arrivals are 0 (`ce51e53a` and the addendum of D284). **What this does not say**: a request in the part of a run before the window (the part thrown away) has no duration in the record; what can be said is that every request ended within the whole length of the load tool's run (the longest, 623.7 s). The record of the web server's own access log was not used to check that no 504 occurred; the load tool's status counts and failure counts were used.
3. **BookStack's B and this B differ in the timeout.** BookStack's B kept 1,800 s and was not changed; Bagisto's B alone is at 2,400 s. The two B's settings differ in this one place, as well as in the web server's configuration (section 2) and in the application's cores (section 1.2).

## 6. The prediction, and the order that makes the test valid

### 6.1 What the prediction fixed

The prediction is `poc/lab/predictions/ladder-prediction-bagisto-b.json` (sha256 `cb955d5bfb247d48c59d87992f5acb73729a3819f0eed6c4ccb9c8ef69f30028`), committed with its input file in `87e8f6ec` (2026-11-27 02:25:59). It was made twice and the two files are the same bytes (`bagisto-b-prediction/second/prediction/two-runs.sha256`), with `bin/predict-ladder --drop-marked-routes --request-timeout-s=2400 --warmup-from-marks --naive-count=10000 --expect-estimate-commit=58107a2`, on the estimate and the shared-table copy made for the first attempt (nothing rebuilt, re-planned or re-estimated). Its `allocation` block says `request_timeout_s` 2400.0 and `half_request_timeout_s` 1200.0, and the prediction gate said **open** (`second/prediction/prediction-gate.txt`). The prediction's statistics of the 190,000-row database were read just before the prediction run and equal those taken after the build (the 152 tables, `n_rows` and `last_update`, the same; the database was not rebuilt).

- **The route taken out of the mix**: `GET /admin/customers/view/{id}` (route 3), in **all five** scenarios, by the mark of memory risk; the other seven routes are sent (`ALLOCATION s-1: 7 routes sent, 1 not sent`). This was decided from the prediction alone. The shares of the seven routes that are sent are the prediction's `rps_after`, not the mix's (`allocation.json` beside each scenario's record).
- **The warm-up**: 1,149 s at every step of every scenario (basis: `GET /api/products`, upper bound 1,148.9 s; the line is 1,200 s, so the margin is 51.1 s). No other route has a bound over 1,200 s.
- **The steps**: the 15 steps of spec 7.4.2 (0.025 to 5 rps in total).
- **The chain's length before it was started** (`second/chain-plan/chain-length.txt`): from 13.6 hours (K, the first step over the line, at the upper reading, with no waiting at the end of steps) to 87.3 hours (all 15 steps, with the longest waits), not past 2026-12-05 00:00; the point reading of K gives 25.8 hours or more.

### 6.2 The order

Times are the host's clock (JST). The commits are in `git log`.

| time | commit | what |
| --- | --- | --- |
| 2026-11-25 11:33:30 | `c0b66755` | the ruling (spec 7.4.7; D261), before any value of the route list, the calibration or the row count |
| 11:33:52 | `a3979035` | the decisions on the unclear points (D262, decisions 1 to 10) |
| 11:34:28 | `3e4b9763` | the form of the fixes to the tools (D262 section 6) |
| 11:45:45 | `8dad9821` | the column to order by, the record of writes and the reuse of the capture of 30,000 rows (D262 section 9), **decided before the script that makes the route list was run** |
| 11:49:02 | `24ba1c63` | the route list, the candidates, the routes taken out and the allowed tables |
| 12:12:01 | `70423692` | the calibration set (D263), before any value of the calibration |
| 12:18:58 | `124fa099` | the shared N table and the rate of the gate (D264), before the gate was run |
| 12:35:32 | `0953fdbb` | the row count (D265) |
| 12:37:09 | `0807e6d1` | the lab entry and its guards read the allowed tables from a list; a second lab stack beside the BookStack lab |
| 13:06:47 | `3147dac7` | the gate on inputs, for reading (D266) |
| 13:30:15 | `ac982bc2` | the lab's requests get the capture's host (D267) |
| 15:14:30 | `c6d9a339` | the application's CPUs set to 10 (D271) |
| 2026-11-26 07:49:28 | `48c7bb26` | the fix of the stall and how to judge it, before any run (D279) |
| 08:11:20 | `efe5bc2f` | the web server's two lines taken out in the Bagisto lab |
| 08:44:45 | `f7dab84a` | the confirming run |
| 12:01:16 | `7e011b42` | the ruling that adopted R_4 and the rule for the rest (D283), before any R_5 run |
| 13:24:25 | `20a3e407` | the rate table and the calibration set, before cal-2 and cal-3 (cal-2 started 13:24:33) |
| 13:50:07 | `fec43eb7` | cal-2, cal-3, the effective gate's output and the formula's dry run on the eight runs |
| 14:12:33 / 19:01:58 | `3212d44a` / `cc500d0d` | the rehearsal of the chain: its plan, then its results |
| 19:12:44 | `0e7a540b` | the inputs and the stopping rules of the prediction, before its plans were made |
| 19:29:56 | `58107a2c` | the estimate, the plans and the shared-table copy |
| 19:30:43 | `309e05a1` | the first attempt stopped at exit 39 (no prediction file) |
| 2026-11-27 00:27:16 | `36d21962` | the ruling of 2,400 s (D284) |
| 02:19:22 | `359d1e30` | the rehearsal of the 2,400 s timeout |
| 02:24:34 | `14f73668` | the plan of the second attempt, before the tool was run again |
| **02:25:59** | **`87e8f6ec`** | **the prediction** |
| 02:32:46 | `e3a67a28` | the prediction's record and the plan of the chain |
| 02:33:07 | | the first start of the chain (see 6.3) |
| 02:35:55 | | the chain started again with the same flags: this is the measurement |
| 02:37:13 | | the first step of s-1 (the `started_at` of step 1's record) |
| 06:35:09 | `8d01dba5` (06:36:34) | the chain stopped at the 7th step of s-1 |

**The prediction was committed before the first measured step**, by git and by the record: `87e8f6ec` at 02:25:59 against the first step at 02:37:13 and the first start of the chain at 02:33:07. The prediction file's sha256 is the same at `87e8f6ec` and at the head of the repository, and there is no difference in `poc/lab/predictions` between them. The prediction, the input, the rules, the formulas, the calibration, the routes and the settings were not changed after the commit. The step records name the prediction's commit as `87e8f6ec` (02:25:59).

### 6.3 The first start of the chain

The chain was first started at 02:33:07 and stopped at 02:34:19 with exit 2 **before any request was sent**: the file of the concrete paths of the routes for this database (`targets-190000.json`) had not been made, a gap in the preparation (the rehearsals had made it in their preparation step). The file was made with the same static analysis as the rehearsal (the same bytes, 8 of 8 routes filled; `second/chain-plan/targets.txt`) and **the chain was started again at 02:35:55 with the same command and the same flags**. Nothing was measured by the first start, and the prediction, the rules and every setting were unchanged. Its output directory is kept (`chain-b-attempt1`), and its log is `second/chain-plan/first-start-chain.log`.

## 7. The measurement: s-1

The record of the chain is `docs/research/lab-data/bagisto-b-prediction/second/chain-stop/` (the chain's log, the memory and out-of-memory records before and after, the application's log lines, a read-only `docker inspect`). The per-step records are those the tools wrote in the chain's output directory (`ladder-b/s-1-190000/rung-*/record.json`); **they are not yet copied into the repository** (the path of the copy is to be set before this file is final). The application and web containers were made again before s-1 (02:36:10). The limits read before each step were those of B.

### 7.1 Steps 1 to 6

Every step has a warm-up of 1,149 s. All six were valid (the achieved rate within ±5% of the decided one), with 0 dropped arrivals and no status other than 200. The database and application utilization are the tools' figures for the window.

| step | rps decided | rps achieved | window (s) | requests in the window | database utilization | application utilization | dropped | started | ended |
| ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | --- | --- |
| 1 | 0.025 | 0.025 | 1600 | 40 | 4.3678% | 4.9885% | 0 | 02:37:13 | 03:24:06 |
| 2 | 0.035 | 0.03503 | 1142 | 40 | 5.8076% | 6.7843% | 0 | 03:24:08 | 04:03:36 |
| 3 | 0.05 | 0.05 | 800 | 40 | 7.9496% | 9.9825% | 0 | 04:03:39 | 04:37:47 |
| 4 | 0.07 | 0.07005 | 571 | 40 | 11.0065% | 14.1329% | 0 | 04:37:49 | 05:08:24 |
| 5 | 0.1 | 0.1 | 400 | 40 | 15.8135% | 20.6567% | 0 | 05:08:27 | 05:36:22 |
| 6 | 0.15 | 0.15009 | 533 | 80 | 23.4220% | 31.8326% | 0 | 05:36:25 | 06:06:51 |

The application's utilization is below the 90% line at every step (D271, condition b). These steps are not set against the prediction here: the comparison belongs to the verdict (section 8), and the measured values were not compared with the prediction while the measurement ran.

### 7.2 Step 7 (0.2 rps): the application container was killed for memory

Step 7 began at 06:06:53. The load tool finished at 06:35:09 with exit 0 (311 iterations complete, none interrupted). The tool's memory check then found that the application container was no longer as before the step: `oom_killed` false to true; the cgroup's `oom` counter 0 to 208 and `oom_kill` 0 to 3; `restart_count` 0 (the container was not restarted). The container's memory limit is 6 GiB and its recorded peak reached the limit during the step. The database container shows no memory kill. The application's log has three lines in which a php-fpm child exited on signal 9 (SIGKILL). The tool's rule (D208) is that the step is not judged and has no record; the chain ends with exit 19, and it did so at 06:35:09 (`CHAIN STOP at s-1 exit=19`). The load tool's own summary file, which is kept but is not a step record, counts one 502 among the 80 requests sent in the window, on `GET /api/products` (the rest 200).

- **The cause is not settled.** The record has the time and the route of the one failure and the peaks of the container's memory; it has no memory figure per request, so which request used how much cannot be said. That `GET /api/products` is the cause is a reading from the time, the route of the 502 and the number of that route's requests in flight (4 at the window's start and 5 at its end, in the load tool's own count; no other route had a request running at either edge), and not a measurement per request. The PHP memory limit of one request (4G) was not the one reached (no "Allowed memory size … exhausted" line); the container's total was.
- **By the rule, s-1's limit rps is "not measured".** Step 6, the last judged step, is valid and below 80% (23.4220%); step 7 is not judged; no step of s-1 reached 80% and no extension of the line is opened by the rule. This is the same form and the same reading as the third round on BookStack (its s-5, whose 9th step was stopped by the memory guard after a valid 8th step at 75.51%; `lab-backtest-3.md`). It is neither a pass nor a fail.
- **What the verdict can still do.** The rule for "undecidable" (spec 7.2) is that B is undecidable if fewer than 4 scenarios measured a limit rps or fewer than 10 steps are usable (valid and from 10% to 90%). From s-1 the usable steps are 3 (steps 4, 5 and 6). With s-1 "not measured", B can be decided only if s-2, s-3, s-4 and s-5 all measure a limit. Stopping here means 0 scenarios measured and B undecidable.
- **What was done and not done.** Nothing was repaired, nothing was run again, and s-1 was not measured again (a repeat after the value was seen could favour a pass; the third round on BookStack did not repeat it either). The stack, the database, the output directories and all records were left as they are. Scenarios s-2 to s-5 were not started. **Whether to go on with s-2 to s-5 under the same prediction, flags and tools, or to stop here, waits for the maintainer's ruling.** The precedent on BookStack of going on after a stop (the sixth round) was for a stop that was not a real out-of-memory kill; there is no precedent for going on after a real one.

## 8. Verdict

*Not written yet.* To be written when the measurement ends (or is ended by ruling).

### 8.1 How the verdict is to be made

*Not written yet.* (Two readers rebuild the verdict from the raw records without seeing each other's work, and compare; the committed verdict scripts of `docs/research/lab-data/dryrun-286/judge/` are used unchanged.)

### 8.2 The steps, and "undecidable"

*Not written yet.*

### 8.3 The items (spec 7.4 B, items 1 to 4)

| item | measured | lowest standard | goal | result |
| --- | --- | --- | --- | --- |
| 1. mean absolute difference smaller than the naive estimate's | not written yet | required | required | |
| 2. mean absolute difference of utilization | not written yet | 15 points or less | 5 points or less | |
| 3. "over 80% or not" agrees with the measurement | not written yet | 80% or more | 80% or more | |
| 4. predicted limit rps within ±X% of the measured one | not written yet | ±20% in 80% or more of the scenarios | ±10% in 80% or more of the scenarios | |

### 8.4 Scenarios s-2 to s-5

*Not written yet.* (The measurement of s-2 to s-5 has not been started.)

### 8.5 Comparison with the naive estimate, and condition 2

*Not written yet.* (Condition 2's second half is counted as spec 7.4.7 counts it: 5 times the capture's largest size or more (190,000 ÷ 30,000 = 6.33), a count not seen before, passing at the goal; "undecidable" counts as not met, and no route is added afterwards.)

### 8.6 The order check

The order of the prediction and the first measurement is in section 6.2. *The part that depends on the verdict is not written yet.*

## 9. What this verdict does not say, and what the lab cannot say

*Not written yet.* Points already fixed by the decisions, to be written out here:

- The routes measured take GET only, and the allowed list of writes is empty.
- The lab's data is the generator's; the capture and the lab fix session, cache and queue to fakes (array, sync), so the application's health check runs on every admin request in both, and may make admin requests heavier than a real deployment's (D269). The work that disappeared when the lab's host was made equal to the capture's was the lab's own (D267, condition 4).
- At each request the application reads `core_config` twice with a full scan as part of its start-up. The estimate does not contain it and the measurement does (section 1.5; D269).
- The applications differ and the calibration is made per application, so the verdicts on BookStack and on Bagisto are not set side by side as a comparison of pass and fail, with the reasons of sections 1.2, 2 and 5.
- B is measured with the application's time limit lifted and the memory limit at 4G inside a container limited to 6 GiB, with a request timeout of 2,400 s; the container's limit was reached in the first scenario (section 7.2). It says nothing about the application stopping early by time or by memory at the defaults.
- One row count, once, on one application.

This file does not claim any precision on a real deployment.
