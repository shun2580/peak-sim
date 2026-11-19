# Lab backtest (B), fifth round: B at 170,000 rows

Status: **the prediction of B at 170,000 rows was committed at 2026-11-19 17:40:41 (`f52f692`) and has not been changed. B has been measured (section 3) and its verdict is written (section 4): it passes at the lowest standard and does not reach the goal (item 4, at ±10%). It is not undecidable. It does not count for condition 1b (section 5).**

This file is the record of the fifth round of test B (`docs/simulator-spec.md`, sections 7.2, 7.4 and 7.4.4). It follows `docs/research/lab-backtest.md` (first round, not passed), `lab-backtest-2.md` (second round: lowest standard passed, goal missed on item 4 by one scenario), `lab-backtest-3.md` (third round: undecidable) and `lab-backtest-4.md` (fourth round: B at 90,000 rows passed at the goal; an easy test, between counts already seen). The pass lines, the tolerances and the rule for "undecidable" are those of the spec and are not moved by this file. The extrapolation test A of the same round is written elsewhere.

What is checked is a **check against a busy period reproduced in the lab**: a prediction compared with a measurement made in the lab. Nothing here is observed on a real deployment, and this file does not claim any precision on one.

**In brief:**

1. B was run at **170,000 rows**, which is 5.667 times the largest size the statements were captured at (30,000 rows), and a count that appears in no earlier record as a scale.
2. **Items 1, 2 and 3 are met at the lowest standard and at the goal.** The mean absolute difference of utilization is 2.541 points (the naive estimate's is 32.984), and "over 80% or not" agrees at 38 of 39 steps.
3. **Item 4 is met at ±20% (5 of 5 scenarios) and not at ±10% (1 of 5).** In all five scenarios the predicted limit rps is lower than the measured one, by 8.7% to 15.2%.
4. One route, `GET /books/{slug}`, was taken out of the mix of every scenario, from the prediction alone, because its largest statement carries more values than the statement limit (section 2.2).
5. Two readers rebuilt the verdict from the raw records without seeing each other's work and agree (section 4.1).
6. s-1 and s-2 were measured on 2026-11-19; the lab was then stopped and started again, and s-3, s-4 and s-5 were measured on 2026-11-20, after the write counters' baseline had been read again. The first run of s-3 is invalid and is not used (section 3.3).

## 1. What was tested

B is the test of the database prediction in the lab (spec 7.2): a prediction of the database utilization of each step of five scenarios, made before any step is measured, compared with the utilization measured in the lab. The rules are those of spec 7.2 and 7.4 B, carried out by the committed tool `poc/lab/score/score-ladder`; nothing in them was changed for this round.

- **Row count.** B = 170,000 rows. The count was first set at 160,000; 160,000 then turned out to appear in the output of a trial run of the simulator on a made-up input (as a made-up table size), which the wording of spec 7.4.4 does not allow, so it was replaced by 170,000 before any estimate or prediction for it was made (spec 7.4.4, "Revised" paragraph; `docs/decisions.md` D229). 170,000 was searched for in the working tree, the history of every branch, the trial folders and the lab's output folders, and has no appearance as a scale (D223).
- **Settings of B** (spec 7.4.2, 7.4.3, 7.4.4): the three limits that can end a request are open: PHP `max_execution_time` 0, PHP `memory_limit` 4G, php-fpm `request_terminate_timeout` 0s; nginx `fastcgi_read_timeout` 1800s; the load tool's request timeout 1800s; the wait at the end of a step 1800s. The app container's memory limit is 6 GiB. The application and web containers are made again before each scenario.
- **Scenarios and steps.** Five scenarios (s-1 even mix; s-2 first routes at 80%; s-3 last routes at 80%; s-4 routes 1, 2, 3 and 5 at 80%; s-5 routes 1, 3, 4 and 5 at 80%) and the 15 steps of spec 7.4.2 (0.025 to 5 rps in total). A scenario stops at the first step whose database utilization is 80% or more, or whose intended rps was not reached; later steps are not measured.
- **Calibration** is cal-1 to cal-7 as before. The window and the stopping rules are those of spec 7.2.
- **A dry run** at 30,000 rows (a count seen before) was made first to find faults of tools and procedures; its numbers are not a test and were not used for anything here (`docs/research/lab-data/dryrun-286/README.md`).

## 2. The prediction

### 2.1 What it was made from

The prediction file is `poc/lab/predictions/ladder-prediction-5-b170k.json` (sha256 `828309f446e8f98d151e04dce65b13faa55f5e8d4e337874e53d72ed386a2b28`), committed in `f52f692`.

- The estimate is `73a6d81` (`docs/research/lab-data/estimate-170k/`, committed 2026-11-19 17:24:38): the statements were captured again at 100, 1,000, 10,000 and 30,000 rows (the largest capture is 30,000 rows), without the application's own rebuild of `references`, after ANALYZE; the small side of the EXPLAIN was 17,000 rows (one tenth of the target, fixed before the EXPLAIN was run). The EXPLAIN ran on a throw-away database, not on the lab's.
- The database of 170,000 rows was built without the application's own rebuild (12 tables at 170,000 rows, including `references`, which shows that the rebuild was not run; 746.0 MiB) and ANALYZE was run on its 41 tables (`docs/research/lab-data/build-170k/`). **No statement other than the build check, ANALYZE and the EXPLAIN was run on it before the prediction was committed**: the statement counters read just before the commit equal the baseline at all 42 rows.
- The estimation rules, the calibration (cal-1 to cal-7: two coefficients, `c_q` 0.000284407 and `c_row` 9.380e-07), the pass lines, the tolerances and the judging rules were not changed after the prediction was committed.

### 2.2 The route taken out of the mix

Under the settings of B, a route whose statement carries more values than the placeholder limit of a statement (65,535), or that the memory mark flags against the 4G limit, is reported as one that will not run, and is taken out of the mix of every scenario (spec 7.4.4). The route's rps is shared among the routes that stay in its group (the routes with the same rps in the scenario file), in proportion to their rps in the file, so the sum of the rps and the naive estimate do not change. This was decided from the prediction alone.

| | |
| --- | --- |
| Route taken out | `GET /books/{slug}`, in **all five** scenarios |
| Reason | placeholders: its largest statement carries 102,009 values at 170,000 rows, 1.56 times the limit of 65,535 |
| Memory mark | none of the 8 routes (the largest predicted peak of one request is that of the same route, `GET /books/{slug}`: 637,592,246 bytes, about 638 MB, 14.8% of 4G; the mark rule multiplies the peak by the measured-over-predicted ratio 1.51, which gives about 963 MB, 22.4% of 4G, still under the limit) |
| Rps shared out | s-1: 7 routes that stay, each ×1.1429; s-2 to s-5: 3 routes that stay in the group, each ×1.3333 |
| Groups left empty | none |
| Longest single request predicted | `GET /books/{bookSlug}/export/zip`, upper bound 540.9 s of PHP CPU time (the stop rule is 900 s, half of the request timeout) |

The `allocation.json` written beside each scenario's record equals the prediction's allocation for that scenario.

### 2.3 What the prediction said

| scenario | predicted limit rps (point) | safe side | naive |
| --- | --- | --- | --- |
| s-1 | 0.18863 | 0.18859 | 2.19641 |
| s-2 | 0.10107 | 0.10101 | 2.19641 |
| s-3 | 0.40408 | 0.40400 | 2.19641 |
| s-4 | 0.13475 | 0.13470 | 2.19641 |
| s-5 | 0.53867 | 0.53860 | 2.19641 |

The utilization of all 15 steps (point and safe side) is in the prediction file.

### 2.4 The order that makes the test valid

| time | what |
| --- | --- |
| 2026-11-19 17:24:38 | `73a6d81`: the row estimate was committed |
| 2026-11-19 17:40:41 | `f52f692`: the prediction was committed |
| 2026-11-19 17:46:28 | the settings of B were written to the two compose files; the prediction file showed no difference from the commit |
| 2026-11-19 17:47:07 | the chain of measurements started |
| 2026-11-19 17:47:41 | the first step of B (s-1, step 1) started |

The prediction was committed 7 minutes before the first step. The prediction file is unchanged since its commit (`git diff f52f692 HEAD` over it is empty), and every step's record names `f52f692` as the commit of its prediction. `docs/research/lab-data/score-170k/order.md` is the output of the order check.

## 3. Measurement

The record is `docs/research/lab-data/ladder-170k/` (committed as `7e20148`, 2026-11-20 06:42:27), kept as the tools wrote it; its `README.md` lists the files and the few changes made to the copies, and no pass or fail is written in it. Times are the host's clock (JST); the application's logs are in UTC, 9 hours behind. The measuring tool is `bin/measure-ladder-chain` / `bin/measure-ladder` as of `7a4cf32`, with `--require-allocation`.

### 3.1 The scenarios

| scenario | first step started | scenario ended | steps measured | last step (rps) | why it stopped | utilization of the last step |
| --- | --- | --- | --- | --- | --- | --- |
| s-1 | 2026-11-19 17:47:41 | 2026-11-19 19:47:25 | 8 | 0.3 | database utilization at or above 80% | 96.4% |
| s-2 | 2026-11-19 19:48:00 | 2026-11-19 21:29:58 | 6 | 0.15 | same | 96.4% |
| s-3 (second run) | 2026-11-20 00:40:44 | 2026-11-20 02:45:15 | 9 | 0.45 | same | 80.1% |
| s-4 | 2026-11-20 02:45:48 | 2026-11-20 04:24:58 | 6 | 0.15 | same | 81.2% |
| s-5 | 2026-11-20 04:25:32 | 2026-11-20 06:38:13 | 10 | 0.7 | same | 86.7% |

The later steps of each scenario have no record because the scenario stopped by its own rule. 39 steps were run, and all 39 are valid (the intended rps was reached; `dropped` is 0 at every step). The limits read from the php-fpm and nginx side before every step were the ones of B at all 39 steps. The statuses of the 2,560 requests of the 39 steps are 200 (2,290) and 302 (270, the OIDC callback, by design); there is no 500 and no request without a response. The application container was not stopped for memory in any scenario, and the write counters were the same before and after every step, and the table counts and the checksum of the three large tables were the same before and after every scenario.

Requests still running when a step's window ended: 33 of the 39 steps have 1 to 18 of them, and at the 32 steps used for items 1 and 2 at most 6; the longest ended 172.1 s after the window. Their database work falls outside the window, so the measured utilization can be a little lower than the true one for that reason. Nothing was corrected for it.

### 3.2 The settings of B, as read

| setting | value read at every step |
| --- | --- |
| PHP `max_execution_time` | 0 |
| PHP `memory_limit` | 4G |
| php-fpm `request_terminate_timeout` | 0s |
| nginx `fastcgi_read_timeout` | 1800s |
| load tool request timeout | 1800s |
| wait at the end of a step | 1800s |

After s-5 the two compose files were restored to their state before B (their sha256 equal those read before the first start), and the values read from the php-fpm and nginx side were the defaults again (30, 512M, 0s, 120s).

### 3.3 The stop between s-2 and s-3, and the second run of s-3

This is what the maintainer's ruling of 2026-11-20 00:36 and its record require to be written here. The details are in `docs/research/lab-data/ladder-170k/README.md` and `PAUSE-20261119.md`.

- **s-3 was begun on 2026-11-19 21:30:16 and was stopped at 23:07:42** with `docker compose stop` (no `-v`; the data volume was kept) because the machine was to be shut down. It is not a failure of the measurement. Steps 1 to 6 had ended valid and step 7 was cut 4 s in. **That first run of s-3 is invalid as a whole and is not used for pass or fail** (kept in `s-3-170000-INVALID-20261119/`). The scenario ended with an out-of-memory-check exit that was the effect of the stop, not a real memory kill.
- **The lab was started again on 2026-11-20 about 00:30.** The first attempt to start failed (a bind mount of the nginx configuration), the database container was removed and made again, and **the database server's log of the container that had run since the first launch is lost**. What happened to the database between the stop and the second start is therefore not known from a log. What is known: the second start's log (13 lines) has no crash-recovery line and no `[ERROR]`; the count and checksum of the three large tables are those of before s-1; the statistics of the 41 tables differ in 0 places from the readings before s-1, before s-2 and before s-3. The database did not shut down cleanly at the stop (the container's stop timeout is 1 s).
- **The baseline of the write counters was set again before s-3.** The server's write counters start at the server's start, so the restart set them to 0 and they could no longer be compared with the ones read before s-1. They were read again at 2026-11-20 00:39:23, and that reading is the baseline of the second s-3. The tool, the judging rule and the prediction were not changed.
- **The checksum of all tables was not taken**, because there is no baseline of it from before the stop; a reading now would not show that the tables are as they were.
- The db log was read before and after every scenario of the second run: no new line each time.
- **Because of this, s-1 and s-2 (one run) and s-3 to s-5 (one scenario per call of the chain) were measured with a stop and a restart of the lab between them.** This is the same shape as the fourth round's s-5.

## 4. Verdict

**B at 170,000 rows passes at the lowest standard. The goal is not reached: item 4 is met at ±20% and not at ±10%. B is not undecidable.** The prediction used is the committed `f52f692`, unchanged.

### 4.1 How the verdict was made

Two readers rebuilt the verdict from the record `docs/research/lab-data/ladder-170k/` and the prediction file, each without seeing the other's work, and then compared. One reader ran the committed verdict scripts (`docs/research/lab-data/dryrun-286/judge/`, committed in `53825c8` and `62ba6a2`, **not changed** for this round; they passed their own 32 checks against known answers and made-up records before they were used, with none skipped); the other wrote their own formulas from the raw records and did not use the scripts. They agree on the figures below (usable steps, scenarios with a limit, items 1 to 4, the step where item 3 disagrees, and the five relative errors of item 4 to one decimal). The scripts' outputs are in `docs/research/lab-data/score-170k/`. The scripts were given only the five valid ladders; the first run of s-3 was not given to them.

The rules are those of spec 7.2 and 7.4 B: the **point** of the prediction is used and not its width; a step is usable for items 1 and 2 when it is valid and its measured utilization is from 10% to 90%; the limit rps is the line between the two adjacent valid steps that cross 80%; B is undecidable if fewer than 10 steps are usable or fewer than 4 scenarios measured a limit.

### 4.2 The steps, and "undecidable"

39 steps were run (s-1 8, s-2 6, s-3 9, s-4 6, s-5 10) and all 39 are valid. **32 steps are usable** (s-1 7, s-2 5, s-3 7, s-4 6, s-5 7). The steps not used for items 1 and 2: s-1 at 0.3 rps (measured 96.36%) and s-2 at 0.15 rps (96.39%), which are above 90%; s-3 at 0.025 and 0.035 rps, and s-5 at 0.025, 0.035 and 0.05 rps, which are below 10%. All five scenarios measured a limit rps, by a line between two valid steps (none was extended). **Usable steps 32 (10 are needed) and scenarios with a limit 5 (4 are needed): B is not undecidable.**

### 4.3 The items

| item | measured | lowest standard | goal | result |
| --- | --- | --- | --- | --- |
| 1. mean absolute difference smaller than the naive estimate's | point 2.541 points; naive 32.984 points | required | required | met, both |
| 2. mean absolute difference of utilization (32 usable steps) | 2.541 points | 15 points or less | 5 points or less | met, both |
| 3. "over 80% or not" agrees with the measurement (all 39 valid steps) | 38 of 39 = 97.44% | 80% or more | 80% or more | met, both |
| 4. predicted limit rps (point) within ±X% of the measured one | 5 of 5 within ±20%; **1 of 5 within ±10%** | ±20% in 80% or more of the scenarios | ±10% in 80% or more of the scenarios | **met at the lowest standard; not met at the goal** (4 of 5 are needed; short by 3) |

**Item 2, by scenario.** The mean absolute difference is s-1 2.082, s-2 2.019, s-3 2.316, s-4 2.098 and s-5 3.978 points. The signed mean (predicted minus measured) is +2.309 points. The largest difference at a usable step is 17.23 points (s-5 at 0.7 rps: predicted 104.0%, measured 86.7%). Six usable steps are over 5 points: s-1 at 0.2 rps (+8.79; measured 76.03%), s-2 at 0.1 (+7.58; 71.58%), s-3 at 0.45 (+9.00; 80.09%), s-4 at 0.15 (+7.82; 81.24%), s-5 at 0.45 (+6.05; 60.78%) and s-5 at 0.7 (+17.23; 86.73%); all of them have a measured utilization of 60% or more. Over the 32 usable steps the measured utilization is 0.834 to 1.105 times the predicted one.

**Item 3.** The one step where the two disagree is s-1 at 0.2 rps: predicted 84.8% (over 80%), measured 76.0% (not over). The other 38 agree, including the five steps whose measured utilization is 80% or more (one at the end of each scenario).

**Item 4, by scenario.** The limit rps is the total rps at which the database utilization reaches 80%. The difference is the predicted point minus the measured limit, over the measured limit.

| scenario | measured limit rps | predicted limit rps (point) | difference of the prediction | measured / predicted | within ±20% | within ±10% |
| --- | --- | --- | --- | --- | --- | --- |
| s-1 | 0.21953 | 0.18863 | -14.1% | 1.164 | yes | no |
| s-2 | 0.11697 | 0.10107 | -13.6% | 1.157 | yes | no |
| s-3 | 0.44943 | 0.40408 | -10.1% (-10.09%) | 1.112 | yes | no, just outside |
| s-4 | 0.14756 | 0.13475 | -8.7% | 1.095 | yes | yes |
| s-5 | 0.63518 | 0.53867 | -15.2% | 1.179 | yes | no |

**The direction of the difference.** In all five scenarios the predicted limit rps is lower than the measured one, by 8.7% to 15.2%: the prediction says the limit is reached earlier than it was. At the five steps whose measured utilization is 80% or more, the predicted point is higher than the measured one, by 7.82 to 30.87 points (s-1 at 0.3 rps +30.87, s-2 at 0.15 +22.35, s-3 at 0.45 +9.00, s-4 at 0.15 +7.82, s-5 at 0.7 +17.23). **The cause was not separated here**; no measurement in this round looks at it. For reference only, and not used in the verdict, the safe side of the prediction has the same item 4 result (5 of 5 and 1 of 5).

### 4.4 Comparison with the naive estimate

The naive estimate is the one of spec 7.3: the utilization of the calibration scenario with the largest total rps (cal-2, 36.423% for each 1 rps) multiplied by the rps of the step, with no growth of the data and no mix of routes; its limit rps is 0.8 divided by 0.36423 = 2.19641 in every scenario. As the spec says, item 4 is shown for the naive estimate but beating it there is not used for the verdict.

| | point prediction | naive estimate |
| --- | --- | --- |
| items 1 and 2: mean absolute difference of utilization, 32 usable steps | 2.541 points | 32.984 points (13.0 times) |
| by scenario (s-1, s-2, s-3, s-4, s-5) | 2.082, 2.019, 2.316, 2.098, 3.978 | 33.187, 40.650, 28.445, 37.840, 27.681 |
| item 3: "over 80% or not" agrees, 39 valid steps | 38 of 39 = 97.44% | 34 of 39 = 87.18% |
| item 4: limit rps within ±20% / ±10% of the measured one | 5 of 5 / 1 of 5 | 0 of 5 / 0 of 5 |
| item 4: the limit rps over the measured one | 0.85 to 0.91 times (predicted / measured) | 3.46 to 18.78 times |

This is one comparison at one row count (170,000), made once, on one application (BookStack). It shows that at this count the prediction was closer to the lab's measurement than the naive estimate was, by the figures above; it does not show how close either would be at a count never seen or on another application.

## 5. Condition 1b

Spec 7.4.4 counts condition 1b (B) as met only when a B at **5 times the capture's largest size or more**, at a **count not seen before**, **passes at the goal**.

| requirement | this B |
| --- | --- |
| 5 times the capture's largest size or more | met: 170,000 ÷ 30,000 = 5.667 |
| a count not seen before | met: no appearance as a scale in any earlier record (D223); the counts listed in `docs/research/lab-data/score-170k/seen.txt` do not include it |
| passes at the goal | **not met**: item 4 at ±10% is 1 of 5 (4 are needed) |

**Condition 1b is not counted as met by this B.** The numbers 1b is counted with: item 1 yes; item 2 over the 5 points 0.000; item 3 short 0 (38 of 39, 32 needed); item 4 short 3 at the goal and 0 at the lowest standard. The 90,000-row B of the fourth round (passed at the goal, 3 times the capture, between counts seen before) stays as a reference and was not used here.

## 6. What this verdict does not say

- It says nothing about a count never seen, and 170,000 is now a count that has been measured: it cannot be used again as a test of anything repaired afterwards (spec 7.5 asks that an improvement be checked on data not seen when the improvement is made).
- It does not say why the predicted limit was lower than the measured one in all five scenarios, or why six usable steps are more than 5 points away (section 4.3). That was not examined.
- It is not a statement about the other test of this round (A).
- The goal of item 4 is not reached at this count. The lowest standard is passed.

## 7. What the lab cannot say

The limits of the lab are those of `lab-backtest.md` (section 6), `lab-backtest-2.md` (section 7), `lab-backtest-3.md` (section 7) and `lab-backtest-4.md` (section 7), and are not repeated in full. Those that bear on this round:

- **The lab's data is the generator's.** Its text bodies hold no links to each other, so the application's own rebuild of `references` would leave that table empty at every row count; for that reason the rebuild was not run on any database of this round (maintainer's ruling of 2026-11-19 12:10; the 170,000-row database has 170,000 rows in `references` as built). The tables that other routes read are therefore as the generator wrote them, and not in the state a real deployment's own maintenance would leave. The estimate assumes the database it was captured on and the one it is used on are in the same maintenance state; here both are, because the rebuild was run on neither.
- **Cache behaviour of a real deployment**, data that does not fit in the database's memory, contention with other work on the same machine and the load of writes are not in the lab. The routes measured take GET only.
- **B is measured with the application's time limit lifted and the memory limit at 4G**, inside a container limited to 6 GiB, and with a request timeout of 1,800 s. It says nothing about the application stopping early by time or by memory at the defaults. `GET /books/{slug}` was taken out of the mix, so B says nothing about that route at 170,000 rows.
- **The measurement was interrupted once** (section 3.3): a stop and a restart of the lab, a lost server log, a new baseline of the write counters, and no checksum of all tables. These are written here so that they are not forgotten; nothing in the records shows an effect on the measured utilization, but the lost log means that what happened to the database between the stop and the restart is not known from a log.
- **Requests running after a step's window** add database work outside the window (section 3.1), which can make the measured utilization a little lower than the true one.
- **The estimate assumes a database whose statistics are in order** and is carried to 170,000 rows from a capture whose largest size is 30,000 rows (5.667 times).
- **One row count, once, on one application.** 170,000 rows is used up by this round. B is run on BookStack only; the third application (Bagisto) is a later step.

This file does not claim any precision on a real deployment.
