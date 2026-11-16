# Lab backtest (B) and the mark, third round: 300,000 rows

Status: **the verdict of B and of the mark is written; test A and what depends on it are `TBD`.** This file holds the purpose, the rules, the order of work, the way of measuring, the prediction as committed and the limits, the records of the measurement (sections 1.1, 3 and 4), and the verdict of B and of the mark with what was checked before it (sections 5 to 7). **B is undecidable; the mark passes.** The extrapolation test A was being measured when this was written: what depends on its result is marked `TBD`. When a figure is filled, the section says where it comes from. Nothing in the rules below was changed when the figures were added.

This file is the record of the third round of test B and of the mark `app_limit_risk` (`docs/simulator-spec.md`, version 0.2, sections 6.2, 6.4, 7.2, 7.4, 7.4.1 and 7.4.2). It follows `docs/research/lab-backtest.md` (the first round, which did not pass) and `docs/research/lab-backtest-2.md` (the second round: B passed at the lowest standard and missed the goal on item 4 by one scenario; the mark failed with one miss). It says what was changed, what is done in which order, what is measured, and what the numbers can and cannot support. The pass lines, the tolerances and the rule for "undecidable" are those of the spec and are not moved by this file.

What is checked is a **check against a busy period reproduced in the lab**: a prediction compared with a measurement made in the lab. Nothing here is observed on a real deployment, and this file does not claim any precision on one.

The third run of test A (the extrapolation test at the same row count) is in `docs/research/extrapolation-test-3.md`.

**Result in brief** (B and the mark; the line for test A is `TBD`):

- **B: undecidable** (spec 7.2; not a pass and not a fail). 25 steps could be used for the difference (10 are needed: met), but the limit rps could be measured for **1 scenario** (s-3; 4 are needed: not met). The cause is the measuring tool and not the database: k6 cut off long requests that were still running at the end of a step, at its default of 30 seconds, and counted only the requests that had ended (sections 4.3 and 5.4). This test is not repaired and not measured again; s-5 was stopped in its step 9 by the memory of the application container and is not measured again (section 4.3).
- **Item 1** (the mean difference is smaller than the naive estimate's): 4.594 points against 31.389 (the safe side 4.839).
- **Item 2** (mean absolute difference of utilization): 4.594 points, over 25 steps (lowest standard 15, goal 5).
- **Item 3** (agreement on "over 80%"): 25 of 27 valid steps (92.6%); only 1 of the 27 was measured over 80%, so it hardly separates predictors.
- **Item 4** (limit rps within ±X%): measured for s-3 only; the point (0.2952) is 0.751 of the measured 0.3932, outside ±20%. With 1 scenario measured, item 4 cannot be decided.
- **The mark: pass** (spec 7.4.1; no miss). Hits 2 (the ZIP export and the book page), misses 0, false alarms 0. Both cut requests were ended by the 512M memory limit and both routes were marked on the memory side and on the CPU-time side (the CPU time of neither reached 30 s).
- **The multiple of the capture's largest size: 10** (300,000 rows against 30,000).
- **Test A: `TBD`** (`docs/research/extrapolation-test-3.md`).

What else the measurement showed, as facts: in B the book page answered HTTP 500 in every request of every recorded window, from SQLSTATE 1390 (the limit on the number of placeholders of a prepared statement) and not from the memory limit (section 4.4); the point is above the measurement in 23 of the 25 steps used, the largest difference being +35.87 points at s-3 step 9 (section 5.2).

## 1. What was changed

The second round left two things open, and the project owner decided on 2026-11-16 (D194, written before any 300,000-row prediction was made) how the third round deals with them.

### 1.1 The mark gets a memory limit

In the second round the book page at 200,000 rows answered HTTP 500 in every window of the three 200,000-row scenarios, and the application's log shows that it ran out of its 512M memory limit (`lab-backtest-2.md`, section 4.4). The mark `app_limit_risk` looked at the application's CPU time against the run-time limit only and had no input for the memory limit, so it gave no warning for a route that fails by memory first. That was the one miss.

The change (spec section 7.4.2):

- **What the mark adds.** A route is also marked when its predicted peak memory may reach PHP's `memory_limit`. The basis is the route's peak-memory estimate (spec section 6.2, the route-level fit that test A has reported since version 0.1).
- **The limit is an input.** The value of the memory limit is given as an input of the prediction (name: `resources.app_memory_limit`, in bytes or as a number with K, M or G; D196, commit `722abc7`). It is a separate key from `php_memory_limit`, which is the input of the line in section 6.2 of the spec. The tool has no default; without the input no memory mark is output and the output is byte for byte what it is without this change. The value given in the prediction of this round is the lab's `memory_limit` as php-fpm shows it, 512M (536,870,912 bytes), read with `bin/check-limits --require=limited` before the prediction was made (D209).
- **The CPU-time rule is not changed.** The factor 9.11 (D178) stays.
- **The output says which limit the mark came from**, the CPU time or the memory.
- **The threshold** (what is multiplied by the peak-memory estimate before it is compared with the limit): **1.51**. A route is marked when 1.51 times its predicted peak memory is at least the limit (a route exactly on the line is marked). The predicted peak memory is the route's value from the fit of section 6.2 of the spec, used only when its status is `predicted`; a route whose status is not `predicted` is not judged, is listed apart with the reason, and is not counted as safe. The factor is the largest ratio of measured peak memory to the committed prediction in the second extrapolation test (200,000 rows, both applications; `docs/research/extrapolation-data-2/compare.json`), rounded up to two decimals, over the 29 routes of the two applications whose measured peak is 100 MB or more: the largest, 1.507, is six delete routes; the other 23 are 0.96 to 1.03. The floor of 100 MB lies in a gap between two groups of measured peaks and was fixed before the factor was read. It is chosen **only from records of row counts already seen** (10,000, 30,000, 50,000, 70,000, 100,000 and 200,000) and committed before the 300,000-row prediction (section 3.3). Record of the rule: D196, commit `722abc7` (2026-11-16 01:15:42; the code and its tests) and commit `236b1ca` (01:17:27; the record `docs/research/lab-data/diagnosis/app-memory-risk/`, the two lines of the spec, and the tool that reads the records). On the records of the counts already seen (8 routes at 5 counts, 40 pairs; the fit of the second test; the limit 512M) the rule gives 1 hit, 0 misses, 2 false alarms and 37 correctly not marked (D196). The one hit is the book page at 200,000 rows (predicted 714.1 MiB; the bound is 2.11 times the limit). It is **not a test**: the rule was made after that fall was known. The test is this 300,000-row check.
- **Routes whose peak-memory estimate is outside ±30% in test A** (spec section 6.2 says such routes are not output): the mark does **not** cut them by the accuracy record. They are judged with the same factor (D196 item 6; commits `722abc7` and `236b1ca`; sections 6.2 and 6.4 of the spec say so). What section 6.2 shows as the row count N ("not output for lack of accuracy") is not changed; the mark is a different kind of thing, a warning that must not be missed. Two reasons are recorded: (i) the factor 1.51 was set from routes outside ±30% (the six delete routes at 1.507), so silencing them would rebuild a miss the records already show; (ii) the book page that fell by memory at 200,000 rows is a route no test could measure (in the second test of A its failure at 200,000 rows had another cause, the limit on the number of placeholders of a prepared statement), and "not measured, so not output" would silence the mark exactly there. Whether a route is within, outside or not checked in test A is not an input of the mark.
- **Where the new part is called from:** `AppMemoryRisk::evaluate` (`poc/runtime/src/Simulate/Predict/AppMemoryRisk.php`) is called from two places: `PredictionReport::build` (`poc/runtime/src/Simulate/Predict/PredictionReport.php`, line 49) and `LadderPrediction::build` (`poc/runtime/src/Simulate/Ladder/LadderPrediction.php`, line 121; the entry that makes the ladder prediction file of this round, which carries the block five times, once per scenario). It is called only when `resources.app_memory_limit_bytes` is in the input. The input is read by `ScenarioReader` (`poc/runtime/src/Simulate/Load/ScenarioReader.php`, line 151, from `resources.app_memory_limit`); the text lines come from `PredictionText::appLimitRiskMemory`. The result is the block `app_limit_risk_memory`, beside `app_limit_risk` (the CPU-time side, unchanged byte for byte); the block says which limit it is for, and the text says `… of memory` or `… of cpu time`. Line numbers are those of the tree when this section was written.
- **Tests:** `AppMemoryRiskTest` (`poc/runtime/tests/Unit/Simulate/Predict/AppMemoryRiskTest.php`, 17 tests): a route over the limit is marked and one under it is not; the mark follows the limit that was given; a route exactly on the line is marked; a route with no peak-memory estimate is not judged and is never marked; the status decides whether a figure is used; the mark does not depend on the rps; a limit of zero or less is refused; the factor is the one in the records; the rule applied to the lab's records marks the book page at 200,000 rows and misses no route; **without the memory limit the output has no trace of the memory mark, and with only the run-time limit given it is the same as before the memory side existed**; a given memory limit adds the block to every target scenario and changes nothing else; the two sides are judged apart and the output says which limit marked the route; the note on the limit rps is printed once when both sides mark a route; the accuracy record of the fit does not switch the mark off. Also `ScenarioReaderTest` (the limit is read in bytes and is absent when not given; one that is not a size stops the run) and `LadderPredictionTest` (a memory limit adds the block and nothing else changes). Three rewrites each make the tests fail: the factor set to 1.0, `>=` changed to `>`, the status check removed (D196). `RuntimeUnit` when D196 was written: 2,668 tests on PHP 8.3 and on PHP 8.2, 0 skipped, 0 failed.

### 1.2 Nothing else is changed

The calibration is the seven runs `cal-1` to `cal-7` of the second round, unchanged (`docs/research/lab-data/calibration-r4/fit-7runs/calibration.yaml`, SHA-256 `56adb832dba42ee9b24fcf22ca716f963e7c25e6e8b287a61f8333b99ea26793`, two coefficients, `c_query` 2.84407e-4 and `c_row` 9.37963e-7 in seconds). The formula, the lab's entry and its four guards (`lab-backtest-2.md`, section 1.6), the threshold of the database (0.8 with 2 CPUs) and the rules of the spec are as they were. The records of the first and second rounds and their verdicts are not changed.

### 1.3 The premise of the estimate

As in the second round (D180): every lab database is analyzed with `ANALYZE TABLE` after it is built and before it is used, and the `last_update` of the statistics tables is recorded before use. The estimate assumes a database whose statistics are in order; it is not claimed for one whose statistics are stale or missing. The prediction's output carries that note.

## 2. What is tested

### 2.1 B: the prediction of the database's utilization

**The row count and the calibration.** N_0 = 10,000. The N tables of the eight routes are grown together to 300,000 rows (30 times N_0; the three tables `entities`, `images` and `references` in the second round). The prediction uses the calibration `cal-1` to `cal-7` of the second round, unchanged (section 1.2).

**The settings of B.** Those of the second round (spec section 7.4.1), with one addition made by the ruling of 2026-11-16 07:45 (spec section 7.4.2, D206): PHP's `max_execution_time` 0; php-fpm's `request_terminate_timeout` 0s; the web server's read limit and the load tool's time limit for one request 600 s; **and PHP's `memory_limit` 4G**. In the second round `memory_limit` stayed at 512M and the book page at 200,000 rows ran out of it in every window, which took work away from the database and spoiled the test of the database's prediction; the same is expected at 300,000 rows and more strongly, so B lifts that limit as it lifts the run-time limit (D208). The lab's tool reads the values through php-fpm and refuses to start B unless the 4G (and the other three) are in force (`bin/check-limits --require=unlimited`, D208). The limit is lifted for B only. The prediction's own input keeps the application's default, 512M, because the mark is made against the limit the mark is later checked against (section 3.1); the mark is measured after B with the defaults put back (section 2.3). The record of every step carries the values.

**The memory the application can use during B.** 4G is a limit of PHP, not an allocation, and a worker takes only what the request needs. The application's container has a memory limit of **6 GiB** (`mem_limit` and `memswap_limit` both 6,442,450,944 bytes; D210), taken from the memory the host showed as available at the time (8.841 GiB at 2026-11-16 08:28:12) less 2 GiB for the agents, the container daemon and the load tool, rounded down. Before D210 the container had no limit, and a worker set at 4G taking that much from the host's memory could stop the whole host and not only the container (D208). One worker at 4G fits in 6 GiB; the prediction's own per-request peaks (section 3.2) are about 1.12 GB for the book page and 0.54 GB for the ZIP export. If the container is stopped for lack of memory the measurement stops (next paragraph).

**What stops the measurement for lack of memory (D208).** The lab's tool (`bin/check-oom`) reads the application container's `OOMKilled`, `RestartCount` and start time and, from the container's cgroup, the counters `oom` and `oom_kill` of `memory.events`. It takes a base when the application has been pointed at the scenario's row count, and compares with it before each step and straight after the load tool and the sampler have ended. If anything differs, or the reading fails, the measurement stops with exit code 19: the step is not judged and no record of it is made, and the step's directory holds a note of which step it was. The cgroup counter is read because a worker killed by the kernel is restarted by the php-fpm master, so that the container does not stop and the first three values do not move. PHP's own "Allowed memory size exhausted" is not a stop of this kind; it shows in the step's count of HTTP statuses (section 4.4). Separately, and **for the record only, with no stop attached** (D210), `bin/read-memory` writes the host's available memory, the application container's `memory.current`, `memory.peak` and `memory.max`, and the `oom` and `oom_kill` counters of the application's and the database's containers, at the base and before and after each step. The database's counter is there because the kernel could kill the database's process while the application's container is unaffected; if it moves, it shows in that step's record and does not stop the ladder.

**The scenarios.** Five scenarios, all at 300,000 rows. The eight routes are in the order of the lab's route list (`poc/lab/routes/route-list.json`), which is the order of the places below: (1) `GET /books/{bookSlug}/chapter/{chapterSlug}/references`, (2) `GET /books/{bookSlug}/export/zip`, (3) `GET /books/{bookSlug}/page/{pageSlug}/references`, (4) `GET /books/{slug}`, (5) `GET /books/{slug}/references`, (6) `GET /images/edit/{id}`, (7) `GET /oidc/callback`, (8) `GET /shelves/{slug}`. The two heavy routes are the ZIP export (place 2) and the book page (place 4). A route "at 80%" has a share of 0.2 of the total rps and a route at 20% has 0.05 (four of each, so the shares add up to 1; the even mix is 0.125 each).

| id | rows | mix of routes | share of the ZIP export | share of the book page |
| --- | --- | --- | --- | --- |
| s-1 | 300,000 | even | 0.125 | 0.125 |
| s-2 | 300,000 | places 1 to 4 at 80%, places 5 to 8 at 20% | 0.2 | 0.2 |
| s-3 | 300,000 | places 5 to 8 at 80%, places 1 to 4 at 20% | 0.05 | 0.05 |
| s-4 | 300,000 | places 1, 2, 3 and 5 at 80%, the others at 20% | 0.2 | 0.05 |
| s-5 | 300,000 | places 1, 3, 4 and 5 at 80%, the others at 20% | 0.05 | 0.2 |

The shares in the last two columns are computed from the scenario file (section 3.1) and the order of the places, not from any prediction or measurement. The pair (share of the ZIP export, share of the book page) is different in all five scenarios, and so is the list of the eight shares.

**How s-4 and s-5 came to be drawn so (the ruling of 2026-11-16 07:45, D206 and D207).** The first drawing, in the spec as first written, was s-4 with the odd places at 80% (the definition of `cal-3`) and s-5 with the even places at 80%. Both heavy routes are on even places, so s-5 had the same shares of the two heavy routes as s-2, and s-4 the same as s-3: five scenarios with three distinct shapes of load. That follows from the order of the places and the places of the two heavy routes alone. The project owner ruled that s-4 and s-5 be redrawn so that the two heavy routes are driven apart. The new definition uses the order of the places and the places of the two heavy routes and no value of any prediction. It was ruled and put in the tool **before any request was sent to the 300,000-row database** (D206, D207), and the prediction was made again for the five scenarios as they now stand (D209). The first prediction, made for the first drawing, is kept as a record and is not the one scored: `docs/research/lab-data/prediction-before-s45/` (the prediction's SHA-256 `233a9e751a21bbead103cb04c29cec968f98eca27ad18d5c6fad7ab69dde84ce`, its input's `06d7ed227aa49de75b55e1dc67379d41ffc04e1e644a895e354d925a3342fb34`). It is not under `poc/lab/predictions/`, the directory the prediction gate reads, so that it cannot open the gate. s-1, s-2 and s-3 are the same in both as JSON, and what differs is s-4, s-5 and the hash of the input (D209). Section 7.4.2 of the spec still carries the first wording of s-4 and s-5 in its second paragraph, and the redrawing is written in the same section a few paragraphs below; this file follows the redrawing. No rule, threshold, estimate or calibration was changed after any value of the first prediction was seen.

**The ladder is 15 steps** of total rps: 0.025, 0.035, then the 13 of section 7.2 of the spec (0.05, 0.07, 0.1, 0.15, 0.2, 0.3, 0.45, 0.7, 1.0, 1.5, 2.2, 3.3, 5.0). The two lowest were added because at 300,000 rows the limit rps is expected near the lowest step, and a scenario whose limit cannot be measured would make the test undecidable. A step discards its first minute and keeps a window of the smallest multiple of 40 ÷ rps seconds that is at least 300 seconds; the lowest step, 0.025 rps, keeps 1,600 seconds, and the window of the 0.035 step is 1,142 seconds (D204: the tool had rounded 40 ÷ 0.035 = 1,142.857 up to 1,143, which gave a plan of 40.005 requests and would have judged this step differently from its neighbours for the same shortfall; it was rounded down before the prediction was made, and the prediction does not depend on the window). A scenario stops after the first step whose database utilization is 80% or more, or whose intended rps did not come out (outside ±5%). These are the rules of section 7.2; only the two steps and the stop for lack of memory above are new.

### 2.2 The verdict of B

The rules are those of section 7.4 of the spec (B) and are not moved. Over the valid steps whose measured utilization is from 10% to 90% (the steps used for the difference), and the scenarios whose limit rps could be measured:

| item | lowest standard | goal |
| --- | --- | --- |
| 1. Mean absolute difference of utilization, smaller than the naive estimate's | required | required |
| 2. Mean absolute difference of utilization | 15 points or less | 5 points or less |
| 3. Share of valid steps where "threshold exceeded or not" agrees with the measurement | 80% or more | 80% or more |
| 4. Share of scenarios whose predicted limit rps (point) is within ±X% of the measured one | ±20%, 80% or more | ±10%, 80% or more |

B is **undecidable**, and not a pass, when fewer than 10 steps can be used for the difference or when the limit rps could be measured for fewer than 4 scenarios. A ratio on the boundary of a tolerance passes. The limit rps of a measurement is found as in section 7.2: a straight line between the two valid steps that straddle 80%; if the step at or above 80% was invalid, a line carried on from the two highest valid steps, and the extension is recorded.

### 2.3 The mark

Measured after B, with the application's limits put back at their defaults (run-time limit 30 s; memory limit as the lab has it, 512M, as in the second round and as in the prediction's input), at 300,000 rows: one cold request, with no load, for each of the eight routes. For each route the record holds the HTTP status, the application's CPU time, and **which limit stopped the request**, read from the application's log (the PHP fatal error and the php-fpm log). The log is copied out **before any container is rebuilt or the stack is taken down**: in the second round the log was gone when it was needed, and the cause of the book page's failure there is an inference (`lab-backtest-2.md`, section 5.3).

Rules of section 7.4.1 of the spec, with the memory limit counted among the limits (section 7.4.2). **Cut** means: the request answered HTTP 500, or was stopped by a limit (the run-time limit or, from this round, the memory limit), whatever the cause. An HTTP 500 counts as cut whatever its cause; the wording of the spec is not narrowed here.

- **hit**: marked and cut;
- **miss**: not marked and cut;
- **false alarm**: marked and not cut.

The line for passing is **no miss**. The number of false alarms is always shown. Which limit cut a request is **not part of the count**: it is written in a separate column, read from the application's log (the CPU-time limit, the memory limit, or neither when the cause is something else), together with whether the mark came from the same limit.

### 2.4 The multiple of the capture's largest size

The estimates are made from captures at 100, 1,000, 10,000 and 30,000 rows. The row count of the test is 300,000, **10 times the capture's largest size**. In the second round the multiples were 1.667 (50,000 rows) and 6.667 (200,000 rows). A reader should take every agreement or disagreement below as the result of carrying the estimate 10 times past what it was captured from. The result is not a statement about 3,000,000 rows.

## 3. Prediction

The prediction was committed on 2026-11-16 at 08:53:18 as `206d114`, before any step was measured at 300,000 rows (section 3.4). Every figure in sections 3.1 to 3.2 is copied from the committed file `poc/lab/predictions/ladder-prediction-3.json` by a script, or from the records named beside it; none is typed by hand. The figures are a prediction and nothing here has been compared with a measurement.

**The limit rps.** The total rps at which the database's utilization reaches 80%, for each scenario: the point, the safe side (the upper side), the naive estimate, and, for the point, the range over the seven fits of the two coefficients each made by leaving one of `cal-1` to `cal-7` out (the range of the calibration; it is a spread of the calibration only and does not include the estimate's error).

| scenario | point | safe side | naive | range of the point over the 7 leave-one-out fits |
| --- | --- | --- | --- | --- |
| s-1 | 0.1181 | 0.1176 | 2.1964 | 0.1179 to 0.1184 |
| s-2 | 0.0738 | 0.0735 | 2.1964 | 0.0737 to 0.0740 |
| s-3 | 0.2952 | 0.2937 | 2.1964 | 0.2947 to 0.2960 |
| s-4 | 0.0757 | 0.0756 | 2.1964 | 0.0756 to 0.0759 |
| s-5 | 0.2683 | 0.2648 | 2.1964 | 0.2679 to 0.2690 |

The naive estimate is the same for all five scenarios, because it knows nothing of the row count or the mix.

**The marks.** The mark `app_limit_risk` (section 1.1; the CPU-time side and the memory side, both given as inputs: the run-time limit 30 s and the memory limit 512M, 536,870,912 bytes) is the same in all five scenarios, because it is a property of each route. **Two routes are marked, the ZIP export and the book page, on both sides, the CPU time and the memory.** The other six routes are not marked on either side. In the table a "bound" is the value that is compared with the limit: on the CPU-time side, 9.11 times the database time the model gives for one request (the larger of the point and the upper side); on the memory side, 1.51 times the predicted peak memory of one request. The two columns of "bound / limit" are that value divided by the limit; a route is marked from 1.

| route | CPU-time side: bound / 30 s | CPU-time mark | memory side: bound / 512M | memory mark | predicted peak memory (bytes) | bound (x1.51, bytes) |
| --- | --- | --- | --- | --- | --- | --- |
| `GET /books/{bookSlug}/chapter/{chapterSlug}/references` | 0.0008 | not_marked | 0.3093 | not_marked | 109,958,940 | 166,037,999 |
| `GET /books/{bookSlug}/export/zip` | 31.8185 | marked | 1.5219 | marked | 541,098,821 | 817,059,220 |
| `GET /books/{bookSlug}/page/{pageSlug}/references` | 0.0008 | not_marked | 0.2966 | not_marked | 105,466,304 | 159,254,119 |
| `GET /books/{slug}` | 1.2139 | marked | 3.1458 | marked | 1,118,474,027 | 1,688,895,781 |
| `GET /books/{slug}/references` | 0.0007 | not_marked | 0.3087 | not_marked | 109,742,598 | 165,711,323 |
| `GET /images/edit/{id}` | 0.0006 | not_marked | 0.0113 | not_marked | 4,019,104 | 6,068,847 |
| `GET /oidc/callback` | 0.0000 | not_marked | 0.0001 | not_marked | 25,632 | 38,704 |
| `GET /shelves/{slug}` | 0.0113 | not_marked | 0.0010 | not_marked | 363,257 | 548,519 |

The three `references` routes have a predicted peak memory of 105,466,304 to 109,958,940 bytes (about 105 to 110 MB), 30 to 31% of the memory limit with the factor applied; they are not marked. These three values come from the formula of the second test of A and not from any measurement in the lab (section 7).

The prediction file carries a second, separate list for each scenario, under `safe_side`: `GET /books/{slug}` and `GET /shelves/{slug}`. It is the list of routes whose plan changes or is unstable at 300,000 rows in the EXPLAIN (spec section 7.1; `docs/research/lab-data/estimate-300k/marked-routes-300000.json`, SHA-256 `35827efece4d5886133b47d05caf44dd3ba91f411747078d7906aee6ff3838ca`). It is another mark, for the extrapolation test, and it is not the mark `app_limit_risk` that is scored in section 2.3; the two are not mixed in any count.

**What decides the limit rps (D209).** The model's database time for one request, at the point, is 104.75 s for the ZIP export, 3.62 s for the book page and under 0.01 s for each of the other six. The ZIP export is about 97% of the database time of one request of each route taken together. The limit rps at the point is therefore decided almost entirely by the share of the ZIP export: s-4 (ZIP 0.2) is 0.0757 against s-2 (ZIP 0.2) at 0.0738, a ratio of 1.026, and s-5 (ZIP 0.05) is 0.2683 against s-3 (ZIP 0.05) at 0.2952, a ratio of 1.100. The shares of the book page, which the redrawing of s-4 and s-5 was meant to put to the test (section 2.1), hardly show in the limit rps; they are expected to show in the utilization at the lower steps (for example at 0.1 rps: s-4 105.7% against s-2 108.4%, s-5 29.8% against s-3 27.1%). This is a reading of the shape of the prediction, not a measured value, and it is repeated among the limits of the lab (section 7). The definitions of s-4 and s-5 are not changed for it.

### 3.1 What the prediction is made from

| input | what | identity |
| --- | --- | --- |
| calibration | `cal-1` to `cal-7` of the second round, two coefficients | `calibration-r4/fit-7runs/calibration.yaml`, SHA-256 as in section 1.2 |
| row estimate, 300,000 rows | the point of every step, the marks, the upper side | `docs/research/lab-data/estimate-300k/estimate-300000.json`, SHA-256 `87b8793416224dd438f17cc2a8e68f4eaa079c0a4a910c1140b911c36d748a99`, commit `4d8b751` (2026-11-16 01:32) |
| scenario file | the five scenarios of section 2.1 with the limits and the threshold | `poc/lab/predictions/ladder-prediction-3-input.yaml`, SHA-256 `81c54d6b18b7ef1d193455e875637c5967114b96527af20ac8fbd2122c6939d1`, commit `206d114`; the calibration part is that of `cal-1` to `cal-7` as committed, with `observed` untouched |
| naive estimate | the utilization of the one of `cal-1`, `cal-2`, `cal-3` chosen by section 7.3 of the spec, scaled by the total rps | `cal-2` (the count 10,000, total rps 1.0000000000000002), its utilization 0.3642310410604942 (the file's `naive.basis_utilization`); `cal-4` to `cal-7` are not candidates because their counts are 3,000 and 30,000 |
| the limits of the application | the run-time limit and the memory limit, as inputs | run-time limit 30 s, memory limit 512M (536,870,912 bytes), the database's threshold 0.8 with 2 CPUs (the file's `resources`). The values of the lab's limits were read, and not changed, with `bin/check-limits --require=limited` (30 s, 512M) before the prediction was made (D209). The 4G of B (section 2.1) is a setting of the measurement and not an input of the prediction |

The estimate at 300,000 rows is made in the same way as at 200,000 rows in the second round: `ANALYZE` and `EXPLAIN` only, no request and no load. It is made with the capture of the lab's own way of building data (the N tables of the eight routes grown together), at 100, 1,000, 10,000 and 30,000 rows, on databases analyzed after they were built.

### 3.2 The predicted utilization, step by step

For each scenario, the predicted utilization of the database (the point, the safe side, and the naive estimate) at each step of the ladder, as a percentage; the limit rps of each is in section 3. The file holds all 15 steps. Here each table ends at the first step at which both the point and the safe side are 80% or more, because a scenario is stopped there (section 2.1); the steps above it are in the file, and the values there run far above 100% because the model is a straight line in the rps. The utilization is of the database's CPU as the model has it for 2 CPUs. These are written out by a script from the committed file.

**s-1**

| total rps | point | safe side (upper) | naive |
| --- | --- | --- | --- |
| 0.025 | 16.9% | 17.0% | 0.9% |
| 0.035 | 23.7% | 23.8% | 1.3% |
| 0.05 | 33.9% | 34.0% | 1.8% |
| 0.07 | 47.4% | 47.6% | 2.5% |
| 0.1 | 67.7% | 68.0% | 3.6% |
| 0.15 | 101.6% | 102.0% | 5.5% |

**s-2**

| total rps | point | safe side (upper) | naive |
| --- | --- | --- | --- |
| 0.025 | 27.1% | 27.2% | 0.9% |
| 0.035 | 37.9% | 38.1% | 1.3% |
| 0.05 | 54.2% | 54.4% | 1.8% |
| 0.07 | 75.9% | 76.2% | 2.5% |
| 0.1 | 108.4% | 108.8% | 3.6% |

**s-3**

| total rps | point | safe side (upper) | naive |
| --- | --- | --- | --- |
| 0.025 | 6.8% | 6.8% | 0.9% |
| 0.035 | 9.5% | 9.5% | 1.3% |
| 0.05 | 13.6% | 13.6% | 1.8% |
| 0.07 | 19.0% | 19.1% | 2.5% |
| 0.1 | 27.1% | 27.2% | 3.6% |
| 0.15 | 40.7% | 40.9% | 5.5% |
| 0.2 | 54.2% | 54.5% | 7.3% |
| 0.3 | 81.3% | 81.7% | 10.9% |

**s-4**

| total rps | point | safe side (upper) | naive |
| --- | --- | --- | --- |
| 0.025 | 26.4% | 26.4% | 0.9% |
| 0.035 | 37.0% | 37.0% | 1.3% |
| 0.05 | 52.8% | 52.9% | 1.8% |
| 0.07 | 74.0% | 74.1% | 2.5% |
| 0.1 | 105.7% | 105.8% | 3.6% |

**s-5**

| total rps | point | safe side (upper) | naive |
| --- | --- | --- | --- |
| 0.025 | 7.5% | 7.6% | 0.9% |
| 0.035 | 10.4% | 10.6% | 1.3% |
| 0.05 | 14.9% | 15.1% | 1.8% |
| 0.07 | 20.9% | 21.1% | 2.5% |
| 0.1 | 29.8% | 30.2% | 3.6% |
| 0.15 | 44.7% | 45.3% | 5.5% |
| 0.2 | 59.6% | 60.4% | 7.3% |
| 0.3 | 89.4% | 90.6% | 10.9% |

### 3.3 What was fixed before the prediction, in order

| time | commit | what |
| --- | --- | --- |
| 2026-11-16 00:57 | D194 | the ruling: the memory limit in the mark; 300,000 rows for B, the mark and A; Bagisto later |
| 2026-11-16 01:03 | `6114fba` | spec section 7.4.2 |
| 2026-11-16 01:15 and 01:17 | `722abc7`, `236b1ca` | the memory part of the mark: rule, threshold, treatment of routes outside ±30%, tests (D196) |
| 2026-11-16 01:20 | `8f4cd65` | the build of the 300,000-row lab database and its row counts (the count only) |
| 2026-11-16 02:40 | `9a5ba2d` | the 300,000-row builds of test A, and the lab's limits at the end of the check (D195) |
| 2026-11-16 01:32 | `4d8b751` | the row estimate at 300,000 rows (D201) |
| 2026-11-16 02:46 | `21b1828` | the window of the 0.035 step set to 1,142 seconds (D204) |
| 2026-11-16 07:45 | `cf25c0f` (07:48) | the ruling: s-4 and s-5 redrawn, and the memory limit lifted in B (D206) |
| 2026-11-16 07:51 | `827187e` | the new s-4 and s-5 in the tool (D207) |
| 2026-11-16 08:23 | `117824b` | `memory_limit` 4G in B and the stop for lack of memory (D208) |
| 2026-11-16 08:47 | `0390da6` | the application container's memory limit of 6 GiB and the record of free memory around each step (D210) |
| 2026-11-16 08:53:18 | `206d114` | the prediction file and its input (D209) |

### 3.4 The order that makes the test valid

1. The memory part of the mark, with its threshold, is committed first. The threshold is chosen from records of the counts already seen and from nothing at 300,000 rows.
2. Until the prediction is committed, a 300,000-row database is only **built and counted**, and read for the prediction's inputs (`ANALYZE`, the statistics tables, `EXPLAIN`). No request, no load, no timing, no `CHECKSUM TABLE`. A single request or a single load step at 300,000 rows would use the count up.
3. The prediction file is committed. The measuring entries open only when the prediction file is in HEAD, has no difference in the working tree, and the directory of predictions holds no other changed or untracked file. The same gate stands in front of the measurement of test A. The entry that takes the count and `CHECKSUM TABLE` of the N tables refused the 50,000- and 200,000-row databases in the second round unless the prediction file passed that gate and held a scenario with that row count; the same rule stands for 300,000 rows. `bin/check-tables` refuses (exit 17, and no query is sent) any row count above 30,000 other than 70,000 and 100,000 unless `--prediction` names a file that passes `bin/prediction-gate` and holds a scenario of that count (`poc/lab/bin/check-tables`, lines 72 to 75; `measure-ladder` passes its `--predictions` to it before any query, from line 246). The refusal was not exercised at 300,000 rows: its tests use 50,000, 200,000, 60,000, 31,000 and 1,000,000 (`poc/lab/tests/write-guards.sh`, B11); that 300,000 is under the rule is read from the code. In the records, both starts of s-1 (08:59:57 and 09:01:52) begin with `PREDICTION OK poc/lab/predictions/ladder-prediction-3.json 206d1148fce29a8643b50a2bb21d0732b7b2cc03 2026-11-16T08:53:18+09:00` and only then print `TABLES BASELINE scale 300000` (`docs/research/lab-data/ladder-300k/s-1.not-started-0900.output.txt` and `s-1.output.txt`).
4. Only then is anything measured at 300,000 rows. The prediction, the estimate, the calibration, the formula, the ladder, the scenarios, the threshold of the mark and the rules of the spec are **not changed** after the commit. If a step is later found to be wrong, it is recorded and reported, and not repaired.

| | |
| --- | --- |
| Commit of the prediction file | `206d1148fce29a8643b50a2bb21d0732b7b2cc03` (`poc/lab/predictions/ladder-prediction-3.json` and its input) |
| Commit time | 2026-11-16 08:53:18 (+0900) |
| SHA-256 of the prediction file as committed | `3fd5d865bbec454fcdc36f4f1699c0752b12b1025215a88ec529a39c337e021d` (the file in HEAD and the file in the working tree were the same when this was written) |
| First step of a new scenario started | 2026-11-16 09:02:09 (`started_at` in `record.json` of step 1 of s-1). The first start of the entry, at 08:59:57, ended with exit 2 before any load and started no step (section 4.1) |
| Interval between the two | 8 min 51 s from the commit to the first step. The first start of the entry came 6 min 39 s after the commit; at 300,000 rows it sent only the count and the checksum of the three N tables |

## 4. Measurement

Two measurements, kept apart. **B** was measured with the application's run-time limit lifted and `memory_limit` at 4G, in five ladders (sections 4.1 to 4.4). **The mark** was measured afterwards with the limits put back at their defaults (section 4.5). Times are the host's clock on 2026-11-16; the application's and the web server's logs are in UTC, 9 hours behind. The records are in `docs/research/lab-data/ladder-300k/` (committed as `9a9371a`) and `docs/research/lab-data/mark-300k/` (`13cbd02`); the recreations of the application and web containers and the stop of s-5 are also written in D211 of `docs/decisions.md`. They are kept as the tools wrote them, and no pass or fail is written in them: this file is where the verdict is first written. **Four of the five scenarios ended by the stopping rules of section 2.1. s-5 did not: it was stopped in its step 9 by the memory guard (D208), and step 9 has no record** (sections 4.1, 4.3 and 4.4).

### 4.1 The order of work, and what was built

The 300,000-row database had been built before the prediction was committed and was only counted and read until then (sections 3.3 and 3.4). The `last_update` of its statistics tables is 2026-11-15 16:21:47 to 16:21:49 in every reading. The measurement ran no `ANALYZE`. The statistics were read before and after each scenario (`stats-scenarios.tsv`: 12 readings of 41 tables; s-1 has two before and two after, for its two starts) and after every recorded step (`stats-steps.tsv`: 30 readings of 41 tables); the two readings taken just before B are in `dbstats/`. In the two files **no table has more than one `last_update`**, so the automatic recalculation (section 1.3 of the second round's file) did not run in B.

| time | event |
| --- | --- |
| 08:53:18 | the prediction was committed (`206d114`, section 3.4) |
| 08:58:58 | the settings of B were written to the two compose files (`chain.log`) |
| 08:59:18 | the limits were read from the application server's side, opened (`limits-after-open/`) |
| 08:59:57 to 09:00:11 | the first start of s-1: `PREDICTION OK`, the write check and the count and checksum of the three N tables were done, and it ended with exit 2 before any load because `targets-300000.json` was missing. No step was started (`s-1.not-started-0900.output.txt`) |
| 09:01:52 | the targets file had been made with `bin/targets` (8 of 8 routes); the chain was started again |
| 09:01:52 to 10:27:44 | s-1 (exit 0), 5 steps; the first step started at 09:02:09 |
| 10:27:47 to 11:44:59 | s-2 (exit 0), 4 steps |
| 11:45:04 to 13:47:33 | s-3 (exit 0), 9 steps |
| 13:47:40 to 13:53:10 | a hold before s-4; at 13:52:34 the application and web containers were made again with `bin/use-db 300000` (D211) |
| 13:53:11 to 15:10:21 | s-4 (exit 0), 4 steps |
| 15:16:22 to 15:16:38 | the application and web containers were made again before s-5 (D211) |
| 15:17:25 to 17:19:51 | s-5 (**exit 19**), 8 steps with records; step 9 (intended 0.45 rps) started at 17:11:54 and was stopped by the memory guard at 17:19:51 |
| 17:19:59 | the chain stopped at s-5 (`chain.log`) |
| 17:24:39 to 17:24:59 | the settings were put back (section 4.2) |
| 17:25:11 to 17:27:24 | the mark (section 4.5) |

- **One container for s-1 to s-3, a new one for s-4 and for s-5.** s-1, s-2 and s-3 ran on the application container made at 08:59:00 (`StartedAt` 2026-11-15T23:59:00Z in `ladder-300k/s-1.output.txt`). The application's memory had grown across scenarios: `app_memory_current` was 25,198,592 bytes before s-1, 4,595,646,464 after s-2 and 4,342,079,488 after s-3, and `app_memory_peak` 5,517,594,624 after s-3, against the container's limit of 6,442,450,944 (`mem-sequence.txt`). The application and web containers were made again before s-4 (13:52:34; `app_memory_current` 2,755,756,032 before and 25,243,648 after) and before s-5 (15:16:22; 2,806,005,760 before and 25,190,400 after) with the same command, so that each began as s-1 had. The database was not touched, and no rule, value, threshold or prediction was changed (D211). The limits were read again after each recreation and were the same (`recreate/`, section 4.2).
- **s-5 was stopped and was not measured again.** What the stop is, and what was left of step 9, is in sections 4.3 and 4.4.
- No scenario was repeated or left out, and the order was s-1 to s-5. The chain's log (`chain.log`) shows no other stop than the two above (the first start of s-1 and s-5).

### 4.2 The settings, and how they were changed and put back

In B the settings of section 2.1 were in force in **every one of the 31 steps that were started** (the 30 recorded steps and step 9 of s-5). In the 30 `record.json` the `settings` object is identical: PHP's `max_execution_time` 0; PHP's `memory_limit` 4G; php-fpm's `request_terminate_timeout` 0s (it was already 0s); the web server's `fastcgi_read_timeout` 600s; and the load tool's time limit for one request 600s (`REQUEST_TIMEOUT`, which the load tool reported back as 600s). Before every step the measuring entry ran `bin/check-limits --require=unlimited` and refused to start the step otherwise; all 31 `check-limits.out` read `LIMITS OK (require unlimited)`, and the load tool ended with exit 0 in all 31 (`k6.exit`). The values were read from the side of the application server (one request through php-fpm, `nginx -T`, the php-fpm configuration), not from the command line. The application container's memory limit of 6 GiB (D210) was in force throughout (`app_memory_max` 6,442,450,944 in every reading of `mem-sequence.txt`).

- **How the limits were lifted:** as a difference in the working tree of two compose files under `poc/lab/compose/`, never committed (`ladder-300k/compose-B.diff`, SHA-256 `08e29d4756e3c1b59ffc7c3cb67bed1ca70759f71212f199d90f78611d956375`). `zz-lab.ini` gained `max_execution_time=0` and `memory_limit=4G`; `lab.conf` has `fastcgi_read_timeout` 600s in place of 120s. The files as they were: `zz-lab.ini` `f495eab57df06ffe1f1e0ab92f4a2b2d91fe4fbfbd3c12cd90a7ffabb25c261b`, `lab.conf` `604aedff9bcb935c92dbdd55e555105c5bfc954ac20654732739630101e55ec5` (`ladder-300k/orig/sha256.txt`). The settings were written at 08:58:58 and the containers were made again with `bin/use-db`.
- **Read before and after opening:** before, `max_execution_time` 30, `memory_limit` 512M, `request_terminate_timeout` 0s, nginx read limit 120s (`limits-before/`); after, 0, 4G, 0s, 600s (`limits-after-open/`, 08:59:18).
- **Read again after the two recreations** (`recreate/check-limits-after-recreate-s-4.txt` and `-s-5.txt`): `LIMITS OK (require unlimited)` with the same values. The SHA-256 of the compose difference before each recreation was the same as that of `compose-B.diff` (`recreate/compose-diff-before-recreate-s-4.sha` and `-s-5.sha`). After s-5 stopped, the values were still those of B (`limits-after-b-s5/`).
- **How they were put back:** at 17:24:39 the two compose files were restored from copies kept outside the repository (`mark-300k/orig-sha256.txt` and `compose-sha256-after-restore.txt` hold the same two hashes as above), and `git diff -- poc/lab/compose` was 0 lines. At 17:24:44 `bin/use-db 300000` made the application and web containers again (exit 0, `PREFLIGHT OK`); the application container's memory limit stayed 6,442,450,944. Read back from the side of the application server with `bin/check-limits --require=limited` (exit 0): `max_execution_time` 30, `memory_limit` 512M, `request_terminate_timeout` 0s, nginx read limit 120s (`mark-300k/limits-after-restore/`; the php-fpm configuration in that record is dated 08:24:59 UTC, 17:24:59 on the host's clock). The reading before the restore, at 17:24, is `mark-300k/limits-before-restore/` (`--require=unlimited`, `LIMITS OK`).

### 4.3 The steps of B

One table per scenario. A step discards its first minute and keeps a window (section 2.1); "requests in window" counts the requests of the window that were completed and recorded, and the statuses are those of the requests of the window. "Valid" means the intended rps came out within ±5% (ratio from 0.95 to 1.05; a ratio on the boundary passes). Database utilization is the database's CPU time over the window against the database's capacity (the threshold of 0.8 with 2 CPUs, section 3.1); application CPU is the application's CPU utilization as the tool records it. Dropped is the load tool's count of iterations it could not start; it was 0 in every step. The settings are those of section 4.2 in every step. Figures are those of `record.json` of each step, rounded once.

**s-1: 300,000 rows, even** (`docs/research/lab-data/ladder-300k/s-1-300000/`; stopped after step 5: the rps did not come out while the database was below 80%)

| step | intended rps | achieved rps | ratio | valid | database utilization (%) | application CPU (%) | dropped | requests in window | HTTP statuses in window | started to ended |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | 0.025 | 0.0244 | 0.975 | yes | 16.57 | 8.73 | 0 | 39 | 200 ×29, 302 ×5, 500 ×5 | 09:02:09 to 09:30:49 |
| 2 | 0.035 | 0.0342 | 0.976 | yes | 21.78 | 11.50 | 0 | 39 | 200 ×29, 302 ×5, 500 ×5 | 09:30:51 to 09:51:53 |
| 3 | 0.05 | 0.0488 | 0.975 | yes | 30.23 | 16.11 | 0 | 39 | 200 ×29, 302 ×5, 500 ×5 | 09:51:56 to 10:07:20 |
| 4 | 0.07 | 0.0665 | 0.951 | yes | 47.41 | 24.74 | 0 | 38 | 200 ×28, 302 ×5, 500 ×5 | 10:07:22 to 10:18:56 |
| 5 | 0.1 | 0.0925 | 0.925 | **no** | 62.09 | 33.06 | 0 | 37 | 200 ×27, 302 ×5, 500 ×5 | 10:18:58 to 10:27:42 |

**s-2: 300,000 rows, places 1 to 4 at 80% (`first80`)** (`docs/research/lab-data/ladder-300k/s-2-300000/`; stopped after step 4: the rps did not come out while the database was below 80%)

| step | intended rps | achieved rps | ratio | valid | database utilization (%) | application CPU (%) | dropped | requests in window | HTTP statuses in window | started to ended |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | 0.025 | 0.0244 | 0.975 | yes | 26.19 | 13.65 | 0 | 39 | 200 ×29, 302 ×2, 500 ×8 | 10:28:04 to 10:56:48 |
| 2 | 0.035 | 0.0342 | 0.976 | yes | 35.42 | 18.59 | 0 | 39 | 200 ×29, 302 ×2, 500 ×8 | 10:56:51 to 11:17:54 |
| 3 | 0.05 | 0.0475 | 0.950 | yes | 54.04 | 28.15 | 0 | 38 | 200 ×28, 302 ×2, 500 ×8 | 11:17:56 to 11:33:21 |
| 4 | 0.07 | 0.0648 | 0.926 | **no** | 69.49 | 36.96 | 0 | 37 | 200 ×27, 302 ×2, 500 ×8 | 11:33:23 to 11:44:57 |

**s-3: 300,000 rows, places 5 to 8 at 80% (`last80`)** (`docs/research/lab-data/ladder-300k/s-3-300000/`; stopped after step 9: database utilization at or above 80%)

| step | intended rps | achieved rps | ratio | valid | database utilization (%) | application CPU (%) | dropped | requests in window | HTTP statuses in window | started to ended |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | 0.025 | 0.0250 | 1.000 | yes | 8.26 | 3.92 | 0 | 40 | 200 ×30, 302 ×8, 500 ×2 | 11:45:20 to 12:14:04 |
| 2 | 0.035 | 0.0350 | 1.001 | yes | 10.08 | 4.95 | 0 | 40 | 200 ×30, 302 ×8, 500 ×2 | 12:14:07 to 12:35:09 |
| 3 | 0.05 | 0.0500 | 1.000 | yes | 13.53 | 6.83 | 0 | 40 | 200 ×30, 302 ×8, 500 ×2 | 12:35:11 to 12:50:35 |
| 4 | 0.07 | 0.0683 | 0.976 | yes | 16.25 | 8.56 | 0 | 39 | 200 ×29, 302 ×8, 500 ×2 | 12:50:38 to 13:02:11 |
| 5 | 0.1 | 0.0975 | 0.975 | yes | 20.36 | 11.16 | 0 | 39 | 200 ×29, 302 ×8, 500 ×2 | 13:02:13 to 13:10:56 |
| 6 | 0.15 | 0.1482 | 0.988 | yes | 33.34 | 18.09 | 0 | 79 | 200 ×59, 302 ×16, 500 ×4 | 13:10:59 to 13:21:56 |
| 7 | 0.2 | 0.1950 | 0.975 | yes | 52.65 | 27.67 | 0 | 78 | 200 ×58, 302 ×16, 500 ×4 | 13:21:58 to 13:30:42 |
| 8 | 0.3 | 0.2925 | 0.975 | yes | 69.98 | 37.80 | 0 | 117 | 200 ×87, 302 ×24, 500 ×6 | 13:30:45 to 13:39:29 |
| 9 | 0.45 | 0.4298 | 0.955 | yes | 86.10 | 49.73 | 0 | 153 | 200 ×114, 302 ×32, 500 ×7 | 13:39:31 to 13:47:31 |

**s-4: 300,000 rows, places 1, 2, 3 and 5 at 80% (`places1235`)** (`docs/research/lab-data/ladder-300k/s-4-300000/`; stopped after step 4: the rps did not come out while the database was below 80%)

| step | intended rps | achieved rps | ratio | valid | database utilization (%) | application CPU (%) | dropped | requests in window | HTTP statuses in window | started to ended |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | 0.025 | 0.0244 | 0.975 | yes | 24.80 | 10.89 | 0 | 39 | 200 ×35, 302 ×2, 500 ×2 | 13:53:27 to 14:22:12 |
| 2 | 0.035 | 0.0342 | 0.976 | yes | 34.45 | 15.00 | 0 | 39 | 200 ×35, 302 ×2, 500 ×2 | 14:22:14 to 14:43:17 |
| 3 | 0.05 | 0.0475 | 0.950 | yes | 52.58 | 22.87 | 0 | 38 | 200 ×34, 302 ×2, 500 ×2 | 14:43:19 to 14:58:43 |
| 4 | 0.07 | 0.0648 | 0.926 | **no** | 67.80 | 29.77 | 0 | 37 | 200 ×33, 302 ×2, 500 ×2 | 14:58:46 to 15:10:19 |

**s-5: 300,000 rows, places 1, 3, 4 and 5 at 80% (`places1345`)** (`docs/research/lab-data/ladder-300k/s-5-300000/`; steps 1 to 8 have records; step 9 was stopped by the memory guard and has no record (below))

| step | intended rps | achieved rps | ratio | valid | database utilization (%) | application CPU (%) | dropped | requests in window | HTTP statuses in window | started to ended |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | 0.025 | 0.0250 | 1.000 | yes | 8.03 | 6.31 | 0 | 40 | 200 ×30, 302 ×2, 500 ×8 | 15:17:42 to 15:46:26 |
| 2 | 0.035 | 0.0350 | 1.001 | yes | 10.86 | 8.51 | 0 | 40 | 200 ×30, 302 ×2, 500 ×8 | 15:46:28 to 16:07:31 |
| 3 | 0.05 | 0.0500 | 1.000 | yes | 14.70 | 11.93 | 0 | 40 | 200 ×30, 302 ×2, 500 ×8 | 16:07:33 to 16:22:57 |
| 4 | 0.07 | 0.0683 | 0.976 | yes | 17.86 | 15.63 | 0 | 39 | 200 ×29, 302 ×2, 500 ×8 | 16:22:59 to 16:34:32 |
| 5 | 0.1 | 0.0975 | 0.975 | yes | 22.66 | 21.32 | 0 | 39 | 200 ×29, 302 ×2, 500 ×8 | 16:34:35 to 16:43:18 |
| 6 | 0.15 | 0.1463 | 0.976 | yes | 36.91 | 33.51 | 0 | 78 | 200 ×58, 302 ×4, 500 ×16 | 16:43:21 to 16:54:18 |
| 7 | 0.2 | 0.1925 | 0.963 | yes | 57.35 | 48.86 | 0 | 77 | 200 ×58, 302 ×4, 500 ×15 | 16:54:20 to 17:03:04 |
| 8 | 0.3 | 0.2875 | 0.958 | yes | 75.51 | 72.01 | 0 | 115 | 200 ×86, 302 ×6, 500 ×23 | 17:03:07 to 17:11:51 |
| 9 | 0.45 | no record | no record | no record | no record | no record | no record | no record | no record | 17:11:54 to 17:19:51 (the memory guard stopped the step; below) |

`GET /oidc/callback` answers 302 in every step (the redirect a guest gets) and is not an error.

**Steps that were not valid (3 of the 30 recorded, and why).** All three are steps where the rps did not come out while the database was below 80%; the record notes `app_side_stall: the rps did not come out while the DB was under 80%` and the scenario stopped there.

- **s-1 step 5** (intended 0.1, achieved 0.0925, ratio 0.925; database 62.09%): the window held 37 of the 40 requests planned; the missing three are all `GET /books/{bookSlug}/export/zip` (2 of 5 completed). The last `running (` line of `k6-output.txt` (line 4924) is `running (8m10.0s), 0000/0160 VUs, 45 complete and 3 interrupted iterations`; dropped 0; `k6.exit` 0.
- **s-2 step 4** (intended 0.07, achieved 0.0648, ratio 0.926; database 69.49%): 37 of 40; the missing three are all ZIP (5 of 8 completed). Line 6634 of `k6-output.txt`: `running (11m01.0s), 0000/0160 VUs, 41 complete and 3 interrupted iterations`; dropped 0; `k6.exit` 0.
- **s-4 step 4** (intended 0.07, achieved 0.0648, ratio 0.926; database 67.80%): 37 of 40; the missing three are all ZIP (5 of 8 completed). Line 6634 of `k6-output.txt`: the same text as in s-2 step 4; dropped 0; `k6.exit` 0.
- In each of the three the elapsed time of that line is the plan's length plus 30 seconds (460 s gives 8m10.0s; 631 s gives 11m01.0s). `poc/lab/load/scenario.js` has no `gracefulStop` anywhere, so k6's default of 30 seconds applies to the open requests at the end of the scenario; a request still open then is counted by k6 as an interrupted iteration and is not among the recorded requests of the window. The longest request completed in the window of each of the three took 247,581 ms (s-1 step 5), 258,479 ms (s-2 step 4) and 249,191 ms (s-4 step 4) (`summary.json`, `duration_ms.max`), under the 600 s limit of the load tool. The same facts, with the second round's r-4 step 3, are written in D213.
- In all 30 recorded steps the number of interrupted iterations in that last line equals the number of requests planned for the window that are not in the record: 24 steps have 1 or more. The missing requests are ZIP's in 21 steps; ZIP's and the book page's in 3 (s-3 step 9, 7 of 8 book-page requests; s-5 step 7, 15 of 16; s-5 step 8, 23 of 24); none in 6.

**Other facts about the steps.**

- **s-3 step 9** (intended 0.45, achieved 0.4298, ratio 0.955; database 86.10%) is valid, and the scenario stopped there by the rule (database utilization at or above 80%).
- **Two steps have a ratio of exactly 0.950** (s-2 step 3 and s-4 step 3); the boundary passes and the records mark them valid.
- **s-5 step 9 was not recorded** (the memory guard of D208, section 2.1). The scenario started at 15:17:25 on a container made at 15:16:22; steps 1 to 8 were recorded (the last ended at 17:11:51) and step 9 (intended 0.45 rps; plan 416 s, window 356 s) started at 17:11:54. At 17:19:51 `bin/check-oom` stopped the measurement (exit 19): the application container `peak-lab-app` was, before the step, `oom_killed=false`, `restart_count=0`, cgroup `oom` 0 and `oom_kill` 0, and at the stop `oom_killed=true`, `restart_count=0`, with the same `StartedAt` (2026-11-16T06:16:24.391492715Z, so the container was not made again or restarted), cgroup `oom` 3,254 and `oom_kill` 116 (`s-5-300000/rung-09-0.45/oom-stop.txt`, `oom-before.txt`, `oom-after.txt`). The memory readings (`bin/read-memory`; limit `app_memory_max` 6,442,450,944): before the step (17:11:54) `app_memory_current` 4,612,042,752, `app_memory_peak` 6,215,221,248, `mem_available_kib` 4,826,108, `oom` and `oom_kill` 0; straight after the load tool (17:19:26) 6,153,510,912, 6,442,856,448, 3,248,968, `oom` 3,253 and `oom_kill` 115; after the scenario (17:19:52) `app_memory_current` 5,694,271,488, `mem_available_kib` 3,686,216, `oom` 3,254, `oom_kill` 116. The database's `oom` and `oom_kill` were 0 in every reading of `mem-sequence.txt`. The step has no `record.json` and was not judged; what is left of it is in section 4.4. s-5 was not measured again.

### 4.4 Requests that did not complete

**HTTP 500 in the recorded steps.** Every `GET /books/{slug}` request of every recorded window answered HTTP 500 (190 requests in the 30 recorded steps, in no step a different status), and no other route answered 500. The count of 500 per scenario is that of the step records, which count the requests of the windows; the web server's access log was not kept (the web logs in `ladder-300k/logs/` are its error log).

| scenario | 500 in the windows (step records) | 500 on routes other than the book page | lines of `SQLSTATE[HY000]: General error: 1390` in the application's log, new in the scenario |
| --- | --- | --- | --- |
| s-1 | 25 (5, 5, 5, 5, 5) | 0 | 27 (27 after s-1) |
| s-2 | 32 (8, 8, 8, 8) | 0 | 34 (61 after s-2, the same container) |
| s-3 | 31 (2, 2, 2, 2, 2, 4, 4, 6, 7) | 0 | 35 (96 after s-3, the same container) |
| s-4 | 8 (2, 2, 2, 2) | 0 | 8 (a new container) |
| s-5 | 94 (8, 8, 8, 8, 8, 16, 15, 23; steps 1 to 8) | 0 | 107 (a new container; the copy was taken at 17:19:59, after step 9) |

- **The cause, read from the application's log** (`ladder-300k/logs/app-after-s-N.dated-lines.txt`; the full logs are very large and only the lines that begin with a date stamp are kept, `logs/MANIFEST.tsv`): every one of those lines is `SQLSTATE[HY000]: General error: 1390 Prepared statement contains too many placeholders`, from the query `select * from activities where ((loggable_type = book and loggable_id in (1)) or (loggable_type = chapter and loggable_id in (2, 7, 12, …` (the identifier list is long). No other kind of error line is in the five copies: **0 lines of `PHP Fatal error`, 0 of `Allowed memory size`, 0 of `Maximum execution time`**. The only other lines are php-fpm's start notices and five or six `PHP Warning: mkdir(): File exists` (`ScratchDir.php` line 47) per container, and, in s-5, the lines of the kills below.
- **The counts of the two columns differ** (25 and 27; 32 and 34; 31 and 35; 8 and 8; 94 and 107). The log counts the failures from the start of the container to the time of the copy; the step records count the requests of the windows. The requests of the discarded first minute of each step, and any request that failed outside a window, are in the log and not in a record; for s-5 step 9 has no record. How the difference divides is not read from these records.
- **Kills in s-5 step 9.** The application's log copy of s-5 (taken 17:19:59) holds 116 lines `WARNING: [pool www] child N exited on signal 9 (SIGKILL)`, the first at 17:12:49 and the last at 17:19:40 on the host's clock (UTC 08:12:49 and 08:19:40), all inside step 9 (17:11:54 to 17:19:51); the copies of s-1 to s-4 hold none. 116 is also the cgroup's `oom_kill` at the stop (section 4.3). The log has no `Allowed memory size` line in it: a worker killed by the kernel writes none. The later copy (`app-after-s-5-tail-before-restore.dated-lines.txt`) holds the same last SIGKILL line (17:19:40) and one line of error 1390 at 17:20:17 that the earlier copy does not have.
- **What is left of s-5 step 9** (not a record of the step; the load tool's own `summary.json`, with `k6.exit` 0 and 11 interrupted iterations, the last `running (` line at 7m26.0s, 416 s plus 30 s): 150 requests in the window, HTTP 200 ×112, 302 ×8 and **502 ×30**; the 30 are all `GET /books/{slug}` (no 200 or 500 of that route in the summary). The ZIP export has 0 completed requests in that window, and the other routes' completed counts are 32, 32, 32, 8, 8 and 8. The web server's error log (`logs/web-after-s-5.txt`, 116 lines) has 115 lines `recv() failed (104: Connection reset by peer) while reading response header from upstream` for `GET /books/1`, the first at 17:12:49 and one line about the ZIP's buffering of its response. The longest completed request of the window took 196,800 ms. These figures are not counted in any table above.
- **Memory.** In B no request ended by PHP's memory limit in any recorded step (the log has no such line, above), and `memory_limit` was 4G. The memory guard compared the application container's `OOMKilled`, `RestartCount` and cgroup `oom` and `oom_kill` before and after every step (section 2.1) and stopped the measurement once, in s-5 step 9; the counters were 0 at every scenario boundary before that (`mem-sequence.txt`).
- **A bound on how far the failed requests can move the measured utilization.** In B the book page answered HTTP 500 in every request of every recorded window (error 1390, above), so the measured utilization lacks whatever database work the page would have done after the error, and the prediction counts the page to its end. This is a bound from the prediction's points and not a measurement. In the prediction the database time of one request is 3.616 s for the book page and 104.75 s for the ZIP export, and the book page's share of the predicted database time is 3.34% in s-1, s-2 and s-3, 0.86% in s-4 and 12.13% in s-5 (computed from `poc/lab/predictions/ladder-prediction-3.json` and the rates of each scenario; not a measured share). If all of the book page's work were missing, the measured utilization of a step would be at most that share of the predicted utilization too low. At that extreme: s-5 step 8 would read 86.35% instead of 75.51%; s-3 step 8 72.70% instead of 69.98% and step 9 90.16% instead of 86.10%; the three invalid steps would read 64.35% (s-1 step 5), 72.02% (s-2 step 4) and 68.43% (s-4 step 4), all still below 80%. The mean absolute difference over the 25 steps would be 3.432 points instead of 4.594 (s-3 6.99 instead of 8.27; s-5 2.54 instead of 4.97); item 3 would be 26 of 27 instead of 25 of 27 (s-5 step 8 would be over 80% as the point is); the measured limit of s-3 would be 0.3627 instead of 0.3932 (the point 0.814 of it, inside ±20%); s-5 would have a measured limit, 0.2708, between its steps 7 and 8 (the point 0.991 of it). Even then at most **2 scenarios** (s-3 and s-5) would have a measured limit and s-1, s-2 and s-4 would still have none, against the 4 that are needed, so **the verdict of B, undecidable, does not depend on it**. How much of its work a failing book page does is not known for B. The one reading is a request of the mark, at 512M, which failed by memory and not by error 1390: it read 1.613 s of database CPU time before it failed, 45% of the 3.616 s predicted; it is a request of the mark and not one of the steps.

### 4.5 The mark

The limits were at their defaults (section 4.2): `max_execution_time` 30, `memory_limit` 512M (536,870,912 bytes), the web server's read limit 120 s. `request_probe.py run --scale=300000 --part=http --reps=1 --require-limits=limited`, per route one warm-up request (repetition 0) and one request (repetition 1), one at a time and without load, on the 300,000-row database from 17:25:11 to 17:27:24 (exit 0; the load tool's time limit 180 s, its default, as at 50,000 and 200,000 rows). The mark is judged on repetition 1; the warm-up's result is kept beside it (D191 and D199 item 11). The record is `docs/research/lab-data/mark-300k/mark-300000/` (`context.json`, `http.jsonl`, `table-counts.json`; SHA-256 as listed in `mark-300k/README.md`, whose `context.json` has its `label` field shortened). The count of the three N tables at 17:25:12 is 300,000 each (`table-counts.json`). No `ANALYZE` was run, and the statistics read before and after (`stats-mark-300000.tsv`) are identical. The prediction marked two routes, `GET /books/{bookSlug}/export/zip` and `GET /books/{slug}`, on both sides (the CPU time and the memory), and none of the other six (section 3).

PHP CPU time is that of the application's process for the request (`php_cpu_usec`); "database CPU" is the database's CPU time net of idle (`cpu_net_usec`; a small negative value is the correction for idle time exceeding the little work the request did); seconds to answer is `answered_after_s`, the time until the response ended (the load tool's own duration of the same request is in the record's `README.md`: 41,737 ms for the ZIP export at repetition 1, against 42.01 s here).

**300,000 rows** (`docs/research/lab-data/mark-300k/mark-300000/`)

| route | marked (the prediction, section 3) | warm-up: HTTP / PHP CPU (s) | request: HTTP / PHP CPU (s) | request: database CPU (s) | request: seconds to answer | request: body (bytes) | limit that ended the request (the application's log) |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `GET /books/{bookSlug}/chapter/{chapterSlug}/references` | no | 200 / 0.332 | 200 / 0.030 | 0.003 | 0.3 | 23,577 | none: it was not cut |
| `GET /books/{bookSlug}/export/zip` | yes (CPU time and memory) | 500 / 25.740 | 500 / 24.926 | 18.796 | 42.0 | 15,790 | the memory limit (512M) |
| `GET /books/{bookSlug}/page/{pageSlug}/references` | no | 200 / 0.040 | 200 / 0.030 | 0.003 | 0.3 | 23,505 | none: it was not cut |
| `GET /books/{slug}` | yes (CPU time and memory) | 500 / 3.370 | 500 / 3.064 | 1.613 | 4.9 | 15,768 | the memory limit (512M) |
| `GET /books/{slug}/references` | no | 200 / 0.044 | 200 / 0.030 | 0.004 | 0.3 | 21,082 | none: it was not cut |
| `GET /images/edit/{id}` | no | 200 / 0.030 | 200 / 0.021 | 0.001 | 0.3 | 4,175 | none: it was not cut |
| `GET /oidc/callback` | no | 302 / 0.018 | 302 / 0.013 | -0.001 | 0.2 | 334 | none: it was not cut |
| `GET /shelves/{slug}` | no | 200 / 0.039 | 200 / 0.029 | 0.045 | 0.3 | 33,490 | none: it was not cut |

- **Which limit ended each 500, read from the application's log** (`mark-300k/app-log-after-mark.txt`, the container's log from its start at 17:24:46 on the host's clock to the end of the mark): four lines `PHP Fatal error:  Allowed memory size of 536870912 bytes exhausted (tried to allocate 2097152 bytes)`, at 17:26:14 and 17:26:58 (in `Illuminate/Database/Connection.php`, line 440) and at 17:27:08 and 17:27:15 (in `Illuminate/Database/Eloquent/Concerns/HasRelationships.php`, line 1127), host's clock (the log is in UTC: 08:26:14, 08:26:58, 08:27:08, 08:27:15). They are four, as the four 500 answers are, and in the order ZIP, ZIP, book page, book page: the two requests of the ZIP export (repetitions 0 and 1) and the two of `GET /books/{slug}`. **No line of the log shows the 30-second `max_execution_time` or the web server's 120 s limit ending a request** (0 lines of `Maximum execution time`), and the log holds no line of error 1390. The ZIP export's PHP CPU time at repetition 1 is 24.926 s, less than 30 s; the book page's is 3.064 s.
- **The log copy and when it was taken.** The application's log copy is the whole log of a container made at 17:24:44, so it holds nothing of B; it ends with the last failure (17:27:15), which is before the end of the mark (17:27:24), and it holds all four failures. The time at which the copy was taken is not written in the record. The web server's log of the mark (`logs/web-after-mark.txt`) has no line.

## 5. Verdict

Written after section 4, from the records' raw data. **B is undecidable. The mark passes.** The verdict on test A is `TBD` (it is written in `docs/research/extrapolation-test-3.md`). Two readers recomputed B and the mark from the raw records, each alone and without the other's figures, and the two were compared (section 5.1). It was ruled on 2026-11-16 at 18:11 (written in D213) that B is closed as undecidable by the rule that was set before the prediction, that this test does not repair the tool and does not measure again, and that repairing and measuring again are decided separately.

### 5.1 What was checked before the verdict

- **The order.** The prediction was committed at 2026-11-16T08:53:18+09:00 (`206d114`). The first step started at 09:02:09, 8 min 51 s later (section 3.4). Each of the 30 step records names the same prediction commit and commit time (`prediction` in the record). The mark was measured from 17:25:11 to 17:27:24, after B.
- **Nothing was changed after the prediction.** `git diff 206d114..HEAD` over `poc/` (the tools, the prediction file, the scoring code, the mark's code and its factor) and over `docs/simulator-spec.md` is empty when this was written; the files that changed are all under `docs/research/` (this document, the records under `lab-data/`, and the write-ups) and `docs/decisions.md`. The prediction file in HEAD has SHA-256 `3fd5d865bbec454fcdc36f4f1699c0752b12b1025215a88ec529a39c337e021d`, as committed; the estimate `87b8793416224dd438f17cc2a8e68f4eaa079c0a4a910c1140b911c36d748a99` and the calibration `56adb832dba42ee9b24fcf22ca716f963e7c25e6e8b287a61f8333b99ea26793` are the files named in sections 1.2 and 3.1; the factor of the mark, 1.51, is in `poc/runtime/src/Simulate/Predict/AppMemoryRisk.php`, unchanged. No rule, threshold, estimate, calibration, formula or mark was touched after the result was seen.
- **From the raw records alone, by two readers.** Each reader made the figures of this section from the step records (`record.json`), the mark's `http.jsonl` and application log, and the prediction as committed, by a script outside the repository, and the two were then set side by side. They agree in the verdicts (B undecidable, the mark pass) and in every count and figure: the usable steps (25, of which s-1 4, s-2 3, s-3 8, s-4 3 and s-5 7), the valid steps (27), the invalid steps (s-1 step 5, s-2 step 4, s-4 step 4), items 1 to 3 and the figures per scenario, the limit of s-3, and the mark's 8 routes. **One difference, in how a figure was printed:** the measured utilization of s-3 step 8 was written 69.99 by one reader and 69.98 by the other. The record's value is 69.98488…, which rounds to 69.98, and this document prints 69.98; no verdict, count or limit depends on it (the computations use the unrounded value). The scoring tool of `poc/lab/score/` was read for its stated reading of the rules (its README was committed before the prediction) and was not run, because it starts a throwaway container.
- **The settings and the statistics.** The settings of B were in force in every step (section 4.2) and no `last_update` of any statistics table changed between any two readings (section 4.1).
- **Why B is undecidable** (spec 7.2). The rule asks for 10 or more steps usable in item 2 (valid, and measured from 10% to 90%): there are 25, met. It asks for the limit rps to be measured for 4 or more scenarios: it was measured for **1** (s-3), not met. B is therefore undecidable, and the rule says an undecidable B is not treated as a pass.
- The prediction used is the point (spec 6.3). The safe side and the naive estimate are shown beside it.

### 5.2 B

Error is predicted minus measured, in points of database utilization. Steps used in item 2 are the valid steps measured from 10% to 90%. "Over 80%" says whether the measured utilization and the predicted point are above 80% (item 3 counts the valid steps where the two agree). Figures are those of the step records and the committed prediction, rounded once.

**s-1: 300,000 rows, even**

| step | intended rps | valid | measured (%) | point | safe side | naive | point − measured | used in item 2 | over 80%: measured / point |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | 0.025 | yes | 16.57 | 16.93 | 17.00 | 0.91 | +0.36 | yes | no / no |
| 2 | 0.035 | yes | 21.78 | 23.71 | 23.81 | 1.27 | +1.93 | yes | no / no |
| 3 | 0.05 | yes | 30.23 | 33.87 | 34.01 | 1.82 | +3.64 | yes | no / no |
| 4 | 0.07 | yes | 47.41 | 47.42 | 47.61 | 2.55 | +0.01 | yes | no / no |
| 5 | 0.1 | **no** | 62.09 | 67.74 | 68.02 | 3.64 | +5.65 | no | no / no |

**s-2: 300,000 rows, places 1 to 4 at 80%**

| step | intended rps | valid | measured (%) | point | safe side | naive | point − measured | used in item 2 | over 80%: measured / point |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | 0.025 | yes | 26.19 | 27.09 | 27.20 | 0.91 | +0.90 | yes | no / no |
| 2 | 0.035 | yes | 35.42 | 37.93 | 38.08 | 1.27 | +2.51 | yes | no / no |
| 3 | 0.05 | yes | 54.04 | 54.19 | 54.40 | 1.82 | +0.15 | yes | no / no |
| 4 | 0.07 | **no** | 69.49 | 75.86 | 76.16 | 2.55 | +6.37 | no | no / no |

**s-3: 300,000 rows, places 5 to 8 at 80%**

| step | intended rps | valid | measured (%) | point | safe side | naive | point − measured | used in item 2 | over 80%: measured / point |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | 0.025 | yes | 8.26 | 6.78 | 6.81 | 0.91 | -1.49 | no | no / no |
| 2 | 0.035 | yes | 10.08 | 9.49 | 9.53 | 1.27 | -0.59 | yes | no / no |
| 3 | 0.05 | yes | 13.53 | 13.55 | 13.62 | 1.82 | +0.02 | yes | no / no |
| 4 | 0.07 | yes | 16.25 | 18.97 | 19.07 | 2.55 | +2.72 | yes | no / no |
| 5 | 0.1 | yes | 20.36 | 27.10 | 27.24 | 3.64 | +6.74 | yes | no / no |
| 6 | 0.15 | yes | 33.34 | 40.65 | 40.86 | 5.46 | +7.32 | yes | no / no |
| 7 | 0.2 | yes | 52.65 | 54.21 | 54.47 | 7.28 | +1.56 | yes | no / no |
| 8 | 0.3 | yes | 69.98 | 81.31 | 81.71 | 10.93 | +11.32 | yes | no / yes **(differ)** |
| 9 | 0.45 | yes | 86.10 | 121.96 | 122.57 | 16.39 | +35.87 | yes | yes / yes |

**s-4: 300,000 rows, places 1, 2, 3 and 5 at 80%**

| step | intended rps | valid | measured (%) | point | safe side | naive | point − measured | used in item 2 | over 80%: measured / point |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | 0.025 | yes | 24.80 | 26.42 | 26.45 | 0.91 | +1.61 | yes | no / no |
| 2 | 0.035 | yes | 34.45 | 36.98 | 37.03 | 1.27 | +2.53 | yes | no / no |
| 3 | 0.05 | yes | 52.58 | 52.83 | 52.90 | 1.82 | +0.25 | yes | no / no |
| 4 | 0.07 | **no** | 67.80 | 73.97 | 74.06 | 2.55 | +6.17 | no | no / no |

**s-5: 300,000 rows, places 1, 3, 4 and 5 at 80%**

| step | intended rps | valid | measured (%) | point | safe side | naive | point − measured | used in item 2 | over 80%: measured / point |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | 0.025 | yes | 8.03 | 7.45 | 7.55 | 0.91 | -0.58 | no | no / no |
| 2 | 0.035 | yes | 10.86 | 10.43 | 10.57 | 1.27 | -0.43 | yes | no / no |
| 3 | 0.05 | yes | 14.70 | 14.91 | 15.10 | 1.82 | +0.20 | yes | no / no |
| 4 | 0.07 | yes | 17.86 | 20.87 | 21.15 | 2.55 | +3.01 | yes | no / no |
| 5 | 0.1 | yes | 22.66 | 29.81 | 30.21 | 3.64 | +7.15 | yes | no / no |
| 6 | 0.15 | yes | 36.91 | 44.72 | 45.31 | 5.46 | +7.81 | yes | no / no |
| 7 | 0.2 | yes | 57.35 | 59.63 | 60.42 | 7.28 | +2.27 | yes | no / no |
| 8 | 0.3 | yes | 75.51 | 89.44 | 90.63 | 10.93 | +13.93 | yes | no / yes **(differ)** |

**Items 1 and 2: the mean absolute difference, over the 25 steps used** (points of utilization). s-3 step 1 (8.26%) and s-5 step 1 (8.03%) are valid and below 10%, so they are not used.

| scenario | steps used | point | safe side | naive | signed mean of point − measured |
| --- | --- | --- | --- | --- | --- |
| s-1 | 4 | 1.49 | 1.61 | 27.36 | +1.49 |
| s-2 | 3 | 1.19 | 1.34 | 37.22 | +1.19 |
| s-3 | 8 | 8.27 | 8.48 | 31.62 | +8.12 |
| s-4 | 3 | 1.47 | 1.51 | 35.94 | +1.47 |
| s-5 | 7 | 4.97 | 5.45 | 28.98 | +4.85 |
| **all** | **25** | **4.594** | **4.839** | **31.389** | **+4.51** |

The point is above the measurement in 23 of the 25 steps used and below it in 2 (s-3 step 2, −0.59; s-5 step 2, −0.43). The largest differences are s-3 step 9 (+35.87), s-5 step 8 (+13.93), s-3 step 8 (+11.32) and s-5 step 6 (+7.81). The safe side is below the measurement in four valid steps, those measured below 11%: s-3 step 1 (6.81 against 8.26), s-3 step 2 (9.53 against 10.08), s-5 step 1 (7.55 against 8.03) and s-5 step 2 (10.57 against 10.86).

**Item 3: the threshold.** The predicted point and the measurement agree on whether utilization is above 80% in **25 of 27 valid steps (92.6%)**. The two steps that differ are s-3 step 8 (measured 69.98%, predicted 81.31%) and s-5 step 8 (measured 75.51%, predicted 89.44%); in both the prediction was above and the measurement was not. The safe side agrees in 25 of 27 and the naive estimate in 26 of 27; neither is used for the verdict. **Only 1 of the 27 valid steps was measured over 80%** (s-3 step 9, 86.10%), where the point agrees (121.96%), so item 3 hardly separates a prediction that finds the threshold from one that does not (section 6).

**Item 4: the limit rps.** The rule of spec 7.2: a straight line between the two valid steps that straddle 80%; where the step at or above 80% was not valid, a line through the two valid steps with the largest rps, extended to 80% and recorded as extended. The abscissa is the intended rps of the step (the reading of the scoring tool's README).

| scenario | measured limit rps | how, from which steps | point | point ÷ measured | safe side | naive | naive ÷ measured |
| --- | --- | --- | --- | --- | --- | --- | --- |
| s-1 | **not measured** | not measured: step 5 (62.09%) is not valid and no step at or above 80% was measured (`app_bound_below_threshold`) | 0.1181 | , | 0.1176 | 2.1964 | , |
| s-2 | **not measured** | not measured: step 4 (69.49%) is not valid and no step at or above 80% was measured (`app_bound_below_threshold`) | 0.0738 | , | 0.0735 | 2.1964 | , |
| s-3 | **0.3932** | interpolated, steps 8 (0.3 rps, 69.98%) and 9 (0.45 rps, 86.10%), both valid | 0.2952 | **0.751** (outside ±20%) | 0.2937 (0.747) | 2.1964 | 5.59 |
| s-4 | **not measured** | not measured: step 4 (67.80%) is not valid and no step at or above 80% was measured (`app_bound_below_threshold`) | 0.0757 | , | 0.0756 | 2.1964 | , |
| s-5 | **not measured** | not measured: step 8 (75.51%) is valid and below 80%; step 9 was stopped by the memory guard and has no record (`app_bound_below_threshold`) | 0.2683 | , | 0.2648 | 2.1964 | , |

- Limit rps measured for **1 scenario** (s-3). The point is **0.751 of the measured** (outside ±20%); the point is below the measured limit. The safe side is 0.747 of it and the naive estimate 5.59 times it.
- **s-1, s-2 and s-4 were not measured.** Each stopped at a step that was not valid and below 80% (section 4.3); the rule extends the line only when the step at or above 80% was not valid, and none was measured. **s-5 was not measured** because its step 9 has no record: its last judged step, step 8, is valid at 75.51%. The rule has no other way to extend (the same reading as r-4 in the second round, `lab-backtest-2.md`).
- Only as a reference, and **not a value the rule provides**: carrying a line through the two valid steps with the largest rps anyway gives the limits s-1 0.1079 (the point 1.094 of it, steps 3 and 4), s-2 0.0709 (the point 1.041 of it, steps 2 and 3), s-4 0.0727 (the point 1.042 of it, steps 2 and 3), s-5 0.3248 (the point 0.826 of it, steps 7 and 8). These are lines carried from points well below 80% (the last valid step of s-1, s-2 and s-4 is at 47% to 54%), so they say little. Taken together with s-3 they would be 5 scenarios, 4 of 5 inside ±20% and 3 of 5 inside ±10%; they are not used in any count.

**The verdict of B**

| item | lowest standard | goal | the figure (for reference: B is undecidable and none of these counts) |
| --- | --- | --- | --- |
| 1. the mean difference is smaller than the naive estimate's | required | required | 4.594 points against 31.389 (the safe side 4.839): smaller |
| 2. the mean absolute difference of utilization | 15 points or less | 5 points or less | 4.594 points |
| 3. agreement on "over the threshold" | 80% or more | 80% or more | 25 of 27 = 92.6% |
| 4. the limit rps within ±X% | ±20%, 80% or more | ±10%, 80% or more | cannot be decided: 1 scenario measured (s-3), outside ±20% |

**B is undecidable** (usable steps 25 of the 10 needed; scenarios with a measured limit rps 1 of the 4 needed). This is not a pass and not a fail. Items 1 to 3 are shown as figures; they are not a pass of B.

### 5.3 The mark

By spec 7.4.1: marked and cut is a hit; not marked and cut is a miss; marked and not cut is a false alarm. A route counts as cut if it answered HTTP 500 or was stopped by a limit, whatever the cause. The line for passing is no misses; the false alarms are counted and shown. The mark is the same in all five scenarios, because it is a property of each route (section 3). The request judged is repetition 1; repetition 0 (the warm-up) gives the same class for every route.

| route | CPU-time side: bound ÷ 30 s | memory side: bound ÷ 512M | marked | rep 1: HTTP / PHP CPU (s) | cut | the limit that ended it (application log) | class |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `GET /books/{bookSlug}/chapter/{chapterSlug}/references` | 0.0008 (not marked) | 0.3093 (not marked) | no | 200 / 0.030 | no | — | correctly not marked |
| `GET /books/{bookSlug}/export/zip` | 31.8185 (marked) | 1.5219 (marked) | yes, on both sides | 500 / 24.926 | yes (HTTP 500) | the memory limit (512M) | **hit** |
| `GET /books/{bookSlug}/page/{pageSlug}/references` | 0.0008 (not marked) | 0.2966 (not marked) | no | 200 / 0.030 | no | — | correctly not marked |
| `GET /books/{slug}` | 1.2139 (marked) | 3.1458 (marked) | yes, on both sides | 500 / 3.064 | yes (HTTP 500) | the memory limit (512M) | **hit** |
| `GET /books/{slug}/references` | 0.0007 (not marked) | 0.3087 (not marked) | no | 200 / 0.030 | no | — | correctly not marked |
| `GET /images/edit/{id}` | 0.0006 (not marked) | 0.0113 (not marked) | no | 200 / 0.021 | no | — | correctly not marked |
| `GET /oidc/callback` | 0.0000 (not marked) | 0.0001 (not marked) | no | 302 / 0.013 | no | — | correctly not marked |
| `GET /shelves/{slug}` | 0.0113 (not marked) | 0.0010 (not marked) | no | 200 / 0.029 | no | — | correctly not marked |

**Hits 2, misses 0, false alarms 0. The line (no miss) is met: the mark passes.** The count follows the wording of spec 7.4.1 (HTTP 500 is a cut whatever its cause); no 500 here has a cause other than the memory limit, so no other reading of the wording would change it.

**Which limit the mark came from, kept apart from the count.** The application's log shows 4 lines `Allowed memory size of 536870912 bytes exhausted` and no line for the 30-second run-time limit or the web server's limit; the four lines are the four 500 answers (section 4.5). So both cut requests were ended by the **memory limit**, and neither by the CPU-time limit.

- **The ZIP export** was marked on the memory side (the bound is 1.522 times the 512M limit, from the predicted peak of 541,098,821 bytes) and on the CPU-time side (31.82 times the 30 s limit). It was cut by the memory limit after 24.926 s of PHP CPU time, which is below 30 s: the CPU-time side marked a route that the CPU-time limit did not cut.
- **The book page** was marked on the memory side (3.146 times the limit, from the predicted peak of 1,118,474,027 bytes) and on the CPU-time side (1.214 times). It was cut by the memory limit after 3.064 s of PHP CPU time, far from 30 s.
- In the second round the book page at 200,000 rows was a miss: it was cut at about half the run-time limit, and the mark then had no input for the memory limit (`lab-backtest-2.md`, sections 5.3 and 5.4). In this round both requests that the memory limit cut were marked on the memory side.
- The mark's memory side rests on a predicted peak, not on a measured one: the records do not measure the real peak memory of either request, so how near the predicted peak is to the real one is not shown. That a rule made after the 200,000-row fall was known has now met a count it was not made from is what this check is; it is one request per route on one application (section 7).

### 5.4 What is not repaired

**Nothing was repaired and nothing is measured again in this test.** The measuring tool was not changed, B was not repeated, and s-5 was not measured again. The prediction, the estimates, the calibration, the formula, the thresholds, the mark and the pass rules are as they were committed; none was changed after the result was seen. Repairing the tool and measuring again are decided separately (D213).

**What the records show of the cause of the undecidability** (facts, section 4.3 and D213). The three steps that stopped s-1, s-2 and s-4 each hold 37 of the 40 requests planned for the window; the three missing are all ZIP export requests, and the load tool's last line says `3 interrupted iterations` in each (dropped iterations 0). `poc/lab/load/scenario.js` gives no `gracefulStop`, so k6's default of 30 seconds applies to the requests still open at the end of the scenario, and the elapsed time of that last line is the plan's length plus 30 seconds. A ZIP export at 300,000 rows is a long request (41.7 s for one request with no load, in the mark; up to 247 to 258 s in the windows of the three steps), so a ZIP request that has not ended 30 seconds after the plan's end is cut off and is not counted. The number cut off grows with the load (s-1: 1, 1, 1, 2 and 3 in steps 1 to 5), and at 3 the window holds 37 of 40, a ratio of 0.925 or 0.926, outside ±5%, and the tool judged the step not valid. The rps that was asked for was sent; the shortfall is in the count of requests that ended. The database was at 62% to 69% in those steps and the tool's note says the rps did not come out while the database was below 80%.

**The second round had the same form at one step, and its record is not changed.** In the second round, r-4 step 3 (`docs/research/lab-data/recheck-ladder-b/r-4-200000/rung-03-0.1/k6-output.txt`, line 4924: `41 complete and 3 interrupted iterations`) has the same shape: 37 of 40 in the window, the three missing all ZIP export requests (5 of 8 completed), dropped 0 (D213). That file's record and its verdict are not rewritten by this one; the cause it left open (`app_side_stall`, "not determined") is read here against the facts above, and the second round's file stands as it was written.

Candidates for what to repair, **not implemented, not decided**, each a change of the tool or of the specification and therefore not made here:

- make the load tool wait for the requests still open at the end of a step (a `gracefulStop` longer than the longest request), or count the requests that started inside the window when they end after it, so that a window is not short of its long requests;
- for a scenario that reaches 0.45 rps at 300,000 rows with workers allowed 4G, set the application's memory or the number of workers so that the application container is not stopped for lack of memory (s-5 step 9, section 4.3).

Neither is a test until it is made and checked on a count that was not seen: **300,000 rows is now a seen count** and cannot be used again as a test of anything repaired after this round (spec 7.4.1).

### 5.5 Readings of the rules that could have gone another way

Each reading below is fixed by the wording of the spec or the scoring tool's README, both committed before the prediction. The effect of the other reading is shown so that the reader can see how far the verdict depends on it.

| point | reading used | the other reading and its effect |
| --- | --- | --- |
| ratio exactly 0.950 (s-2 step 3, s-4 step 3) | valid ("within ±5%" includes the boundary) | reading both as not valid: 23 steps used, mean absolute difference 4.976 points, item 3 23 of 25; s-2 and s-4 would each stop at step 3; the number of measured limits stays 1, so B stays undecidable |
| the abscissa of the line (s-3) | the intended rps | the achieved rps: measured limit 0.3778, the point 0.781 of it; still outside ±20% |
| the limit of s-1, s-2, s-4, s-5 | not measured: the rule extends only when a step at or above 80% was measured and not valid | extending anyway through the two valid steps with the largest rps would give 5 scenarios measured, 4 of 5 inside ±20% (80%) and 3 of 5 inside ±10% (60%); at the lowest standard item 4 would then be met and at the goal not; but that is a change of the rule, so it is not used |
| s-5 step 9 | not judged: it has no record | counting it would need a utilization that does not exist |
| the 500 of the book page in B | not part of any rule of B; shown (section 4.4) | none |
| "cut" in the mark | HTTP 500 or stopped by a limit, whatever the cause (spec 7.4.1) | counting only the cuts by the run-time limit would make the two marked routes false alarms and leave 0 misses; the mark would still pass; the wording does not support it, so it is not used |
| the warm-up or the measured request (mark) | the measured request (repetition 1), by D191 | the warm-up gives the same class for every route |

### 5.6 What is left before version 1.0

Conditions 1 to 3 of section 0 of the spec, counted as D153 counts them (with the change of D167).

- **Condition 1, the lab backtest (B at the goal): undecidable.** D153 gives no count of what is short for an undecidable condition, so none is given. For the four terms, for reference: (1) the mean difference is smaller than the naive estimate's: yes (4.594 against 31.389 points); (2) the mean against the goal of 5 points: 4.594, 0 points over; (3) agreement on the threshold: 25 of 27, and ⌈0.8 × 27⌉ = 22 are needed, 0 short; (4) the limit rps: not countable, since 1 scenario was measured and the rule needs 4. B has to be made decidable (a new count of rows, spec 7.4.1) before this condition can be counted.
- **The mark (spec 7.4.1):** pass, 0 misses. It is not a numbered term of D153.
- **Condition 1, the extrapolation test (A):** `TBD`.
- **Condition 2, a third application (Bagisto):** not measured ("未測"); it is not 0.
- **Condition 3, the remaining misses:** `TBD` (it is counted after conditions 1 and 2 are met and is a provisional number until then).

## 6. Comparison with the naive estimate

The naive estimate (spec 7.3) scales the utilization of the chosen run at 10,000 rows (`cal-2`, 36.4231% at 1.0 rps in total) by the total rps; it knows nothing of the row count or the mix of routes. It is in the step tables of section 5.2.

**Item 1 and item 2: the simulator beats it at every scenario.** Mean absolute difference over the steps used, in points of utilization:

| scenario | steps used | point | safe side | naive | signed mean of point − measured |
| --- | --- | --- | --- | --- | --- |
| s-1 | 4 | 1.49 | 1.61 | 27.36 | +1.49 |
| s-2 | 3 | 1.19 | 1.34 | 37.22 | +1.19 |
| s-3 | 8 | 8.27 | 8.48 | 31.62 | +8.12 |
| s-4 | 3 | 1.47 | 1.51 | 35.94 | +1.47 |
| s-5 | 7 | 4.97 | 5.45 | 28.98 | +4.85 |
| **all** | **25** | **4.594** | **4.839** | **31.389** | **+4.51** |

The naive estimate is below the measured utilization at **every one** of the 25 steps used (its signed mean is -31.389), because it keeps the 10,000-row load for a 300,000-row database; the simulator's signed mean is +4.51.

**Item 3.** The naive estimate agrees with the measurement in 26 of 27 valid steps and the simulator in 25 of 27; the naive estimate is not used for the verdict. Only one valid step was measured over 80% (s-3 step 9, 86.10%): the naive estimate misses that one (predicted 16.39%) and agrees on the other 26 by not reaching 80% before 2.2 rps; the simulator finds the one step over 80% and is over 80% in two steps where the measurement was not (s-3 step 8, s-5 step 8). **This ladder cannot say much about item 3.**

**Item 4: the limit rps.** The naive estimate gives one number for all five scenarios, 2.1964 rps, which is 5.59 times the measured limit of s-3 (0.3932 rps; the other four were not measured) and is outside ±20% in 1 of 1; the simulator's point is outside ±20% as well (0.751 of the measured). Beating the naive estimate on item 4 is not part of the pass rule (spec 7.4).

**What this shows and what it does not.** The simulator beats the naive estimate on the mean difference (item 1) in every scenario, by a wide margin, as in the second round, because the load grows with the rows and the naive estimate does not know it. It does not show that the simulator is right on a deployment, and B is undecidable. The naive estimate's one advantage is item 3, for the reason above.

## 7. What the lab cannot say

The limits of the lab are those of section 6 of `lab-backtest.md` and section 7 of `lab-backtest-2.md`:

- **Cache behaviour of a real deployment**, data that does not fit in the database's memory, contention with other work on the same machine, and the load of writes. The lab's entry writes the `views` table and nothing else, and the entry takes GET only (D187); the counter is committed and grows.
- **The lab's data is the generator's.** For example how many books a shelf holds, and that every book has the same slug, are properties of the data built for the lab. Whether the 300,000-row database has the same properties was not read; the lines of the web server's error log for the book page all name `/books/1` (`docs/research/lab-data/ladder-300k/logs/web-after-s-5.txt`), which is what one book would look like and is not a reading of the data.
- **B is measured with the application's time limit lifted and its memory limit raised to 4G** (section 2.1), inside a container limited to 6 GiB. B says nothing about the application stopping early by time, and nothing about its stopping early by memory at the default of 512M; the mark of section 2.3 is where that is looked at, one request per route and without load. B does not say how the application behaves when several requests that need much memory run together, beyond the stop of section 2.1: one worker at 4G fits in 6 GiB, and the prediction's own per-request peaks are about 1.12 GB (book page) and 0.54 GB (ZIP export).
- **The mark is one cold request per route**, one at a time, without load. It says nothing about the application under load or about a second request to the same route.
- **The estimate assumes a database whose statistics are in order** (section 1.3) and is carried to 300,000 rows from a capture whose largest size is 30,000 rows, 10 times (section 2.4). The calibration was measured at 3,000, 10,000 and 30,000 rows, and its coefficients are used at 300,000. No check of the estimate beyond 30,000 rows exists at 300,000 rows other than this one.
- **One row count, once.** 300,000 rows is a count not seen before this round and is used up by it: it cannot be used again as a test of anything repaired afterwards (spec section 7.4.1 applies the same rule).
- **One application.** B is run on BookStack only, with its eight routes chosen by the rule of section 7.2 of the spec. Nothing here says how the tool does on another application; the third application (Bagisto) is a later step.
- **php-fpm's `pm.max_children` is 16**, as before. A step may stop climbing because all the workers are busy and not because of the database; the application's CPU and the waits in each step's record are where this is looked for.

- **The lab has not measured the peak memory of any route; the formula's values are from another test.** The memory side of the mark takes each route's peak memory from the formula of the second test of A (`docs/research/extrapolation-data-2/bookstack/fits.json`, fitted from measurements at 10 to 10,000 rows and not fitted again), carried to 300,000 rows (D196). No measurement of the lab's own php-fpm exists for the routes' peak memory at any count, so whether the lab's php-fpm peaks as the test's measurements do is not shown. For example, the three `references` routes come out of the formula at 105,466,304 to 109,958,940 bytes (about 105 to 110 MB, section 3); the lab has not measured them and no claim is made for them. The constant (the bytes per row) of the book page was not checked against the lab either; only its exponent was (the failed request at 200,000 rows had tried to take 97,271,808 bytes, which agrees within 0.05% with four times the 24,329,703 bytes of the same page's response at 50,000 rows in the lab; D196). The measured peak is `memory_get_peak_usage()` less the use before the request starts (`Requester.php`), so it does not include the memory of the started application, while the limit counts it; the factor 1.51 does not cover that part, and its size has not been measured.
- **How hard the two heavy routes are pushed is not shown by the limit rps.** The prediction's database time of the ZIP export is about 97% of the database time of one request of each route taken together (section 3), so the predicted limit rps of the five scenarios follows the share of the ZIP export: s-4 against s-2 differ by 2.6% (0.0757 against 0.0738) and s-5 against s-3 by 10% (0.2683 against 0.2952). The shares of the book page, which the redrawing of s-4 and s-5 was meant to test, are expected to show in the utilization at the lower steps and hardly in the limit rps. That is the shape of the prediction and not a measured value. The five scenarios differ in the input (the pair of shares of the two heavy routes and the list of the eight shares are different in all five); whether they behave as five different shapes of load is for the measurement to show.

Items found by the measurement, each with the section it comes from:

- **B did not measure the limit rps for four of five scenarios, because of how the tool counts** (sections 4.3 and 5.4). What B shows of the database's utilization stops at 62% to 69% for s-1, s-2 and s-4 and at 75.5% for s-5; above that nothing was measured. The test of the prediction of the limit rps rests on s-3 alone.
- **In B the book page failed in every request, from SQLSTATE 1390 and not from memory** (section 4.4). That is a limit of the application (the number of placeholders of a prepared statement; the failing query lists chapter identifiers in an `IN` list), separate from the memory limit; it did not show at 50,000 rows (no HTTP 500 in r-1 and r-2 of the second round), and at 200,000 rows B showed the memory limit instead (`lab-backtest-2.md`, section 4.4). The measured utilization lacks the database work the page would have done after the error and the prediction counts the page to its end; section 4.4 gives a bound, not a measurement.
- **s-5 was stopped by the memory of the application container** (section 4.3): 6 GiB, workers allowed 4G each. B says nothing about s-5 above 75.5% of utilization, and nothing about how the application behaves when several memory-heavy requests run together beyond that stop.
- **The point is above the measurement in 23 of 25 steps** (section 5.2), by 4.51 points on average (signed mean), the largest at s-3 step 9 (+35.87) and s-5 step 8 (+13.93). This coincides with the failing book page above (its predicted share of the database time is 3.34% in s-1, s-2 and s-3 and 12.13% in s-5), with the estimate being carried 10 times past the capture's largest size, and with a calibration measured at 3,000 to 30,000 rows; the causes were not separated. The second round showed the same direction at 200,000 rows.
- **Item 3 hardly separates predictors**: 1 of 27 valid steps was measured over 80% (section 5.2).
- **The mark's memory side was not checked against a measured peak** (section 5.3): neither of the two cut requests had its real peak memory measured, so the factor 1.51 is neither confirmed nor shown wrong by this check beyond the two requests having been cut and marked.
- **The ZIP export is a long request at this size** (41.7 s with no load; up to 258 s in a window): it is what the tool's window count loses (section 5.4), and it is about 97% of the predicted database time (section 3).

Items that come from test A are `TBD`.

This file does not claim any precision on a real deployment.
