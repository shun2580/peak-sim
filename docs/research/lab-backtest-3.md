# Lab backtest (B) and the mark, third round: 300,000 rows

Status: **SKELETON. No result is in this file.** What is written here does not depend on a result: the purpose, the rules, the order of work, the way of measuring, the prediction as committed, and the limits. Every place that a result, a commit, a time or a value chosen later has to fill is marked `TBD`. When a `TBD` is filled, the section says where the figure comes from. Nothing in the rules below is changed when the figures are added.

This file is the record of the third round of test B and of the mark `app_limit_risk` (`docs/simulator-spec.md`, version 0.2, sections 6.2, 6.4, 7.2, 7.4, 7.4.1 and 7.4.2). It follows `docs/research/lab-backtest.md` (the first round, which did not pass) and `docs/research/lab-backtest-2.md` (the second round: B passed at the lowest standard and missed the goal on item 4 by one scenario; the mark failed with one miss). It says what was changed, what is done in which order, what is measured, and what the numbers can and cannot support. The pass lines, the tolerances and the rule for "undecidable" are those of the spec and are not moved by this file.

What is checked is a **check against a busy period reproduced in the lab**: a prediction compared with a measurement made in the lab. Nothing here is observed on a real deployment, and this file does not claim any precision on one.

The third run of test A (the extrapolation test at the same row count) is in `docs/research/extrapolation-test-3.md`.

**Result in brief:** `TBD` (written after section 5; one line each for B items 1 to 4, for the mark, and for the multiple of the capture's largest size, which is 10).

## 1. What was changed

The second round left two things open, and the project owner decided on 2026-11-16 (D194, written before any 300,000-row prediction was made) how the third round deals with them.

### 1.1 The mark gets a memory limit

In the second round the book page at 200,000 rows answered HTTP 500 in every window of the three 200,000-row scenarios, and the application's log shows that it ran out of its 512M memory limit (`lab-backtest-2.md`, section 4.4). The mark `app_limit_risk` looked at the application's CPU time against the run-time limit only and had no input for the memory limit, so it gave no warning for a route that fails by memory first. That was the one miss.

The change (spec section 7.4.2):

- **What the mark adds.** A route is also marked when its predicted peak memory may reach PHP's `memory_limit`. The basis is the route's peak-memory estimate (spec section 6.2, the route-level fit that test A has reported since version 0.1).
- **The limit is an input.** The value of the memory limit is given as an input of the prediction (name: `TBD`, fixed with the implementation). The tool has no default; without the input no memory mark is output and the output is byte for byte what it is without this change.
- **The CPU-time rule is not changed.** The factor 9.11 (D178) stays.
- **The output says which limit the mark came from**, the CPU time or the memory.
- **The threshold** (what is multiplied by the peak-memory estimate before it is compared with the limit): `TBD`. It is chosen **only from records of row counts already seen** (10,000, 30,000, 50,000, 70,000, 100,000 and 200,000) and committed before the 300,000-row prediction (section 3.3). Record of the rule and of how it catches the 200,000-row book page: `TBD` (decision number and commit).
- **Routes whose peak-memory estimate is outside ±30% in test A** (spec section 6.2 says such routes are not output): how the mark treats them is decided and recorded before the prediction. `TBD` (decision number and commit).
- **Where the new part is called from:** `TBD` (the call path from the prediction's entry point, written when the part exists).
- **Tests:** `TBD` (a route over the limit is marked and one under it is not; the mark follows the input; without the input the output is unchanged).

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
3. The prediction file is committed. The measuring entries open only when the prediction file is in HEAD, has no difference in the working tree, and the directory of predictions holds no other changed or untracked file. The same gate stands in front of the measurement of test A. The entry that takes the count and `CHECKSUM TABLE` of the N tables refused the 50,000- and 200,000-row databases in the second round unless the prediction file passed that gate and held a scenario with that row count; the same for 300,000 rows: `TBD` (confirmed when the prediction is committed).
4. Only then is anything measured at 300,000 rows. The prediction, the estimate, the calibration, the formula, the ladder, the scenarios, the threshold of the mark and the rules of the spec are **not changed** after the commit. If a step is later found to be wrong, it is recorded and reported, and not repaired.

| | |
| --- | --- |
| Commit of the prediction file | `206d1148fce29a8643b50a2bb21d0732b7b2cc03` (`poc/lab/predictions/ladder-prediction-3.json` and its input) |
| Commit time | 2026-11-16 08:53:18 (+0900) |
| SHA-256 of the prediction file as committed | `3fd5d865bbec454fcdc36f4f1699c0752b12b1025215a88ec529a39c337e021d` (the file in HEAD and the file in the working tree were the same when this was written) |
| First step of a new scenario started | `TBD` (read from the step's own record) |
| Interval between the two | `TBD` |

## 4. Measurement

`TBD`. The records are written to `docs/research/lab-data/` (directory names `TBD`) as the tools wrote them, with no pass or fail in them. This file is where the verdict is first written.

### 4.1 The order of work

`TBD` (a table of times, as in section 4.1 of `lab-backtest-2.md`). The order planned: B first (s-1 to s-5 at 300,000 rows), then the settings are put back, then the mark. Where a measurement is interrupted (a stop of the host or anything else), the databases' statistics tables, the counts and `CHECKSUM TABLE` of the N tables and the real values of the limits are read before and after the restart, the interrupted scenario is measured again from its first step, and the interrupted run is kept apart and enters no count.

### 4.2 The settings, and how they were changed and put back

For every change of the lab's settings: what was changed, how it was put back, and the value read back **from the side of the application server** (one request through php-fpm, the web server's configuration, the php-fpm configuration), not from the command line. `TBD`. If a setting cannot be put back, the work stops and the stop is reported.

### 4.3 The steps of B

`TBD`. One table per scenario: intended rps, achieved rps, ratio, valid or not, database utilization, application CPU, requests in the window, HTTP statuses in the window, start and end. The three settings are in every step's record.

### 4.4 Requests that did not complete

`TBD`. The number of HTTP 500 per scenario, counted from the web server's access log, and the cause of each kind read from the application's log. In the second round the book page ran out of memory in every window at 200,000 rows while B's memory limit stayed at 512M. In this round B's `memory_limit` is 4G (section 2.1), which is meant to avoid that. If a request still fails by memory (at 4G, or by the application container's limit of 6 GiB, or by the stop of section 2.1), it is reported here, with a bound on how far it can move the measured utilization, as in section 7 of `lab-backtest-2.md`.

### 4.5 The mark

`TBD`. For each of the eight routes: the HTTP status, the application's CPU time, the limit that stopped the request, and the mark (and which limit it came from). The log copy and the time it was taken.

## 5. Verdict

`TBD`. Written after section 4, from the records' raw data. A second reader recomputes the three verdicts (B, the mark and A) from the raw records without using the first reader's figures, and the two are compared; the comparison is recorded.

### 5.1 What was checked before the verdict

`TBD`: that the prediction's commit is earlier than the first measurement (from the history and from the records' times); that the prediction, the estimate, the calibration and the threshold of the mark in HEAD are the files committed; that the three settings were in force in every step; that no `last_update` of the statistics changed during a ladder (or which did).

### 5.2 B

`TBD`. Items 1 to 4 against the lowest standard and the goal; the table of steps used; the measured limit rps of each scenario and whether it was extended; the verdict (pass, fail or undecidable).

### 5.3 The mark

`TBD`. Hits, misses and false alarms, the limit that stopped each stopped request, and whether the mark came from that limit.

### 5.4 What is not repaired

If anything fails, nothing is repaired in this round. Candidates for a repair, if any, are listed here as candidates only; each would change the spec and is for the project owner to decide. `TBD`.

### 5.5 Readings of the rules that could have gone another way

`TBD`. Where a rule can be read in two ways, the reading used and the effect of the other one, as in section 5.5 of `lab-backtest-2.md`.

### 5.6 What is left before version 1.0

Conditions 1 to 3 of section 0 of the spec, counted as D153 counts them (with the change of D167). `TBD`, with numbers.

## 6. Comparison with the naive estimate

The naive estimate (spec section 7.3) scales the utilization of the chosen run at 10,000 rows by the total rps and knows nothing of the row count or the mix of routes. It is in the step tables. `TBD`: the mean absolute difference per scenario for the point and for the naive estimate, item 3 for both, and item 4 for both (item 4 against the naive estimate is shown and is not part of the pass rule).

## 7. What the lab cannot say

The limits of the lab are those of section 6 of `lab-backtest.md` and section 7 of `lab-backtest-2.md`:

- **Cache behaviour of a real deployment**, data that does not fit in the database's memory, contention with other work on the same machine, and the load of writes. The lab's entry writes the `views` table and nothing else, and the entry takes GET only (D187); the counter is committed and grows.
- **The lab's data is the generator's.** For example how many books a shelf holds, and that every book has the same slug, are properties of the data built for the lab. Whether the 300,000-row database has the same properties is read when it is built (`TBD`).
- **B is measured with the application's time limit lifted and its memory limit raised to 4G** (section 2.1), inside a container limited to 6 GiB. B says nothing about the application stopping early by time, and nothing about its stopping early by memory at the default of 512M; the mark of section 2.3 is where that is looked at, one request per route and without load. B does not say how the application behaves when several requests that need much memory run together, beyond the stop of section 2.1: one worker at 4G fits in 6 GiB, and the prediction's own per-request peaks are about 1.12 GB (book page) and 0.54 GB (ZIP export).
- **The mark is one cold request per route**, one at a time, without load. It says nothing about the application under load or about a second request to the same route.
- **The estimate assumes a database whose statistics are in order** (section 1.3) and is carried to 300,000 rows from a capture whose largest size is 30,000 rows, 10 times (section 2.4). The calibration was measured at 3,000, 10,000 and 30,000 rows, and its coefficients are used at 300,000. No check of the estimate beyond 30,000 rows exists at 300,000 rows other than this one.
- **One row count, once.** 300,000 rows is a count not seen before this round and is used up by it: it cannot be used again as a test of anything repaired afterwards (spec section 7.4.1 applies the same rule).
- **One application.** B is run on BookStack only, with its eight routes chosen by the rule of section 7.2 of the spec. Nothing here says how the tool does on another application; the third application (Bagisto) is a later step.
- **php-fpm's `pm.max_children` is 16**, as before. A step may stop climbing because all the workers are busy and not because of the database; the application's CPU and the waits in each step's record are where this is looked for.

- **The lab has not measured the peak memory of any route; the formula's values are from another test.** The memory side of the mark takes each route's peak memory from the formula of the second test of A (`docs/research/extrapolation-data-2/bookstack/fits.json`, fitted from measurements at 10 to 10,000 rows and not fitted again), carried to 300,000 rows (D196). No measurement of the lab's own php-fpm exists for the routes' peak memory at any count, so whether the lab's php-fpm peaks as the test's measurements do is not shown. For example, the three `references` routes come out of the formula at 105,466,304 to 109,958,940 bytes (about 105 to 110 MB, section 3); the lab has not measured them and no claim is made for them. The constant (the bytes per row) of the book page was not checked against the lab either; only its exponent was (the failed request at 200,000 rows had tried to take 97,271,808 bytes, which agrees within 0.05% with four times the 24,329,703 bytes of the same page's response at 50,000 rows in the lab; D196). The measured peak is `memory_get_peak_usage()` less the use before the request starts (`Requester.php`), so it does not include the memory of the started application, while the limit counts it; the factor 1.51 does not cover that part, and its size has not been measured.
- **How hard the two heavy routes are pushed is not shown by the limit rps.** The prediction's database time of the ZIP export is about 97% of the database time of one request of each route taken together (section 3), so the predicted limit rps of the five scenarios follows the share of the ZIP export: s-4 against s-2 differ by 2.6% (0.0757 against 0.0738) and s-5 against s-3 by 10% (0.2683 against 0.2952). The shares of the book page, which the redrawing of s-4 and s-5 was meant to test, are expected to show in the utilization at the lower steps and hardly in the limit rps. That is the shape of the prediction and not a measured value. The five scenarios differ in the input (the pair of shares of the two heavy routes and the list of the eight shares are different in all five); whether they behave as five different shapes of load is for the measurement to show.

Items found by the measurement are added to this list after section 5, each with the section it comes from. `TBD`.

This file does not claim any precision on a real deployment.
