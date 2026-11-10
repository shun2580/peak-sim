# Requests with no response (HTTP status 0) near 80% of the ladder

At the steps close to 80% database utilization, the records of the ladder measurements (`docs/research/lab-data/ladder/`) show requests with HTTP status `0`: the load tool received no response. This note says what the load tool's time limit is, which routes the requests belong to, and how they touch the way the database utilization is measured.

It is a reading of what was already recorded. No load was sent and no lab container was touched for it. The ladder records, the prediction and the score are unchanged, and nothing here is used to judge a scenario again.

## What was read

- The records of the 47 steps: `plan.json`, `summary.json` (k6's summary), `run.json`, `record.json` and the raw database CPU samples `cpu.txt` in `docs/research/lab-data/ladder/`.
- The load tool and the measuring script: `poc/lab/load/scenario.js`, `poc/lab/bin/measure-ladder`, `poc/lab/compose/`.
- The measuring rules: `docs/decisions.md` D144 (utilization from the CPU time of the database container), D158, D159, D161 (the window `B`), D165 and D166.
- Two raw logs that stay in the lab's out directory (outside the repository, not committed): the web server's access log (one line per request: completion time, status, seconds, URI) and k6's own progress log of each step. The ladder README says the k6 logs are not kept in the repository; what is taken from them is written into the tables of this directory, with `analyze.py` showing how.

`analyze.py` makes every table below from these files (`python3 -I analyze.py`). The tables are in `step-summary.csv` (one line per step, 47 lines), `status0-by-route.csv`, `status0-requests.csv` (one line per request that got no response), `db-util-around-window-end.csv`, `status500-seconds.csv` and `limit-sensitivity.csv`.

## 1. The time limit of the load tool

**60 seconds per request.** It is written nowhere in the repository: `scenario.js` passes no `timeout` to `http.get` and has none in its options, `measure-ladder` starts k6 with only the three file paths in the environment (`PLAN`, `TARGETS`, `SUMMARY`), and the compose file sets no `K6_` variable. So the value is k6's own default for one request, which is 60 s (k6 2.3.0, as pinned in `poc/lab/compose/lab.compose.yaml`). The records agree with it:

- In every one of the 10 steps with status 0, k6's longest request lasted 59.998 or 59.999 s (`duration_ms.max` in `summary.json`). In the 37 steps without status 0, the longest was 57.719 s.
- In the web server's log, the requests that ended without a response lasted 59.3 to 60.0 s (the log's clock starts when nginx has the request; k6's starts a little earlier).

The other limits that act on the same requests:

| Limit | Value | Where | Seen in the records |
| --- | --- | --- | --- |
| k6, one request | 60 s | k6's default | status 0 after 59.998 s or more |
| k6, wait at the end of a step | 30 s | k6's default `gracefulStop` of the arrival-rate executor (not set in `scenario.js`) | the k6 log of `t-2` step 8 ends 433 s after the start, the step being 403 s long; the requests still running then are counted by k6 as "interrupted" and give no status |
| nginx, wait for php-fpm | 120 s | `fastcgi_read_timeout` in `poc/lab/compose/nginx/lab.conf` | never reached (the longest request in the log of a step is about 60 s) |
| PHP execution limit | 30 s | PHP's default `max_execution_time`; the lab's ini files do not set it | the HTTP 500 of `GET /books/{bookSlug}/export/zip` (D165), see section 2 |

## 2. Which routes the requests belong to

**The records do not say.** `summary.json` and `record.json` keep the status counts of a step as one total (`"http_status": {"0": 62, ...}`) and the requests per route only as a count of the requests that were counted (`per_route`), whatever their status. There is no status per route.

The web server's log does keep the route (as a URI) and the status of each request: when k6 gives up and closes the connection, nginx writes status `499` and the seconds the request lasted. Counting the `499` lines of 59 s or more, among the requests that began in the kept window of a step, gives the same number as k6's status 0 in 8 of the 10 steps; in the other two it is one more (`t-1` step 10: 29 against 30; `t-4` step 13: 148 against 149). The extra one is probably a request that k6 cut at the end of its 30 s wait just before 60 s; that was not checked. The `499` lines of less than 59 s are all at the very end of a step and are as many as k6's "interrupted" count (the two differ by one in the same two steps).

Requests with no response, by route (`status0-by-route.csv`; each step's kept window; the count is from the web server's log):

| Scenario | Step | Decided rps | DB utilization (%) | k6 status 0 | Counted from the web log | zip | book | book refs | chapter refs | page refs | image edit | oidc | shelf |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| t-1 (30,000 rows) | 10 | 1.5 | 87.8 | 29 | 30 | 4 | 8 | 3 | 2 | 2 | 2 | 4 | 5 |
| t-2 (100,000) | 7 | 0.45 | 75.6 | 18 | 18 | 18 | 0 | 0 | 0 | 0 | 0 | 0 | 0 |
| t-2 (100,000) | 8 | 0.7 | 99.0 | 62 | 62 | 27 | 5 | 5 | 7 | 3 | 10 | 2 | 3 |
| t-3 (100,000) | 6 | 0.3 | 81.2 | 22 | 22 | 22 | 0 | 0 | 0 | 0 | 0 | 0 | 0 |
| t-4 (10,000) | 10 | 1.5 | 34.1 | 2 | 2 | 0 | 0 | 0 | 1 | 0 | 1 | 0 | 0 |
| t-4 (10,000) | 11 | 2.2 | 50.0 | 12 | 12 | 1 | 4 | 0 | 2 | 0 | 3 | 1 | 1 |
| t-4 (10,000) | 12 | 3.3 | 73.8 | 1 | 1 | 0 | 0 | 1 | 0 | 0 | 0 | 0 | 0 |
| t-4 (10,000) | 13 | 5.0 | 96.6 | 148 | 149 | 14 | 20 | 33 | 10 | 3 | 24 | 36 | 9 |
| t-5 (100,000) | 9 | 1.0 | 67.6 | 8 | 8 | 8 | 0 | 0 | 0 | 0 | 0 | 0 | 0 |
| t-5 (100,000) | 10 | 1.5 | 97.1 | 22 | 22 | 22 | 0 | 0 | 0 | 0 | 0 | 0 | 0 |

`zip` is `GET /books/{bookSlug}/export/zip`; the other columns are the routes of the list by their usual names (`book` is `GET /books/{slug}`, `oidc` is `GET /oidc/callback`).

What the table says:

- At 100,000 rows, at the first step where status 0 appears, **every one of the requests with no response is the zip export** (`t-2` step 7: 18 of 18; `t-3` step 6: 22 of 22; `t-5` step 9: 8 of 8, and step 10: 22 of 22). In those steps the zip route has all its requests without a response: `t-2` step 7 sent 20 zip requests in the window, 18 timed out and 2 were cut at the end.
- The exception is `t-2` step 8, where the database is saturated (99.0%): 27 of the 62 are zip and the other 35 are spread over all seven other routes.
- At 10,000 and 30,000 rows, the requests with no response are spread over all routes and the zip route is a minority of them (4 of 29; 14 of 148). They appear at 34% to 97% utilization. In the three steps below 80% (`t-4` steps 10 to 12) there are only 2, 12 and 1 of them.

### Why the zip export goes from status 500 to no response

The zip route's HTTP 500 (D165: PHP stops the request at its execution limit inside the database layer) does not come back after 30 s. Its seconds in the web server's log (`status500-seconds.csv`, mean per step):

| Scenario | Steps 1 to 3 | Step 4 | Step 5 | Step 6 | Step 7 | Step 8 | Step 9 |
| --- | --- | --- | --- | --- | --- | --- | --- |
| t-2 | 53.2, 53.5, 53.5 | 53.5 | 54.3 | 55.3 | no 500 | no 500 | |
| t-3 | 53.4, 53.9, 53.9 | 54.5 | 56.8 | no 500 | | | |
| t-5 | 53.7, 53.2, 53.5 | 53.2 | 53.6 | 53.3 | 54.3 | 54.7 | 59.4 |

So the 500 comes 52 to 60 s after the request began, and later as the load rises. Even at the lowest step it is within about 8 s of the load tool's 60 s limit. At the step where the load pushes it past 60 s, the load tool gives up first and the request is recorded as status 0: in `t-5` step 9, 7 zip requests came back with 500 (57.9 to 59.7 s) and 8 got no response; in the next step none of them got a response. In `t-2` step 7 and `t-3` step 6 the change is complete. Which of the two ends first is a matter of the two timers; the request is the same one.

A reading that fits (not tested here): PHP's execution limit counts the script's own CPU time on Linux, and the time spent waiting for the database is not part of it, so a request that is limited at 30 s of PHP time lasts 52 s or more by the clock, and longer when the database is slower. The lab's PHP and nginx logs do not show a request's PHP time.

## 3. How the requests with no response touch the measured utilization

### 3.1 What is measured

The utilization of a step is the change of `usage_usec` of the database container between the two ends of the kept window, divided by the window's seconds and 2 CPUs (D144; the window of the layout `B`, D161: at least 300 s, a multiple of 40 / rps, after 60 s that are discarded). It adds up every statement the database ran in that time. It does not look at whose request a statement belongs to, at its status, or at whether the client was still there (D166, item 6). The achieved rps, which is checked within 5%, is the count of the requests that k6 recorded in the window divided by the window's seconds, and it counts requests of status 500 and 0 too.

### 3.2 The database goes on after k6 has given up

Yes. The CPU samples keep going for about 58 s after the end of the window (the sampler runs 60 s longer than the step). For each step with status 0 (`step-summary.csv`):

| Scenario | Step | Window | Last minute of the window | After the window end | CPU-seconds in the window | CPU-seconds after it | Seconds sampled after it |
| --- | --- | --- | --- | --- | --- | --- | --- |
| t-1 | 10 | 0.8779 | 0.9424 | 0.3345 | 561.8 | 38.1 | 56.9 |
| t-2 | 7 | 0.7565 | 0.7737 | 0.4222 | 538.6 | 47.7 | 56.5 |
| t-2 | 8 | 0.9899 | 0.9974 | 0.9799 | 679.1 | 114.1 | 58.2 |
| t-3 | 6 | 0.8118 | 0.8201 | 0.4513 | 649.4 | 53.0 | 58.7 |
| t-4 | 10 | 0.3409 | 0.3426 | 0.0278 | 218.2 | 3.1 | 56.3 |
| t-4 | 11 | 0.5003 | 0.4770 | 0.0733 | 309.2 | 8.4 | 57.0 |
| t-4 | 12 | 0.7376 | 0.7265 | 0.0588 | 447.0 | 6.8 | 58.0 |
| t-4 | 13 | 0.9658 | 0.9593 | 0.1625 | 587.2 | 19.2 | 59.1 |
| t-5 | 9 | 0.6764 | 0.6776 | 0.3490 | 432.9 | 39.4 | 56.4 |
| t-5 | 10 | 0.9706 | 0.9951 | 0.8640 | 621.2 | 101.5 | 58.7 |

The clearest case is `t-2` step 8. Ten-second averages of the database utilization (`db-util-around-window-end.csv`, seconds after the start of the step; the window ends at 403, k6 exits at 433):

`t-2` step 8: 370: 0.999, 380: 0.996, 390: 0.995, 400: 0.999 | 410: 0.988, 420: 0.985, 430: 0.976 | after k6 exited: 440: 0.970, 450: 0.971, 460: 0.983.

For the 30 s after k6 had exited, so with no client left at all, the database was still at 0.97 to 0.98. `t-5` step 10 drains in the same way (0.909, 0.778, 0.575 in the last three bins). In `t-4` step 13 and `t-1` step 10 the database is idle again within 10 to 30 s of the window's end; the later rise in those two (0.26 to 0.43 in the last bins) comes after k6 had exited and the database had been idle, and I read it as the next scenario's preparation, not as requests; that is not shown.

This shows that the database keeps working on requests after the window ends, and after k6 has left. It does not show that these are the requests that timed out: requests that were slow but would have been answered, and the requests k6 cut at the end, are in the same work, and the records cannot tell them apart.

### 3.3 Work that falls outside the window, and work that falls inside from before it

The window has an edge on each side. The work of requests sent in the window that runs after its end is not in the figure (the column "after it" above). The work of requests sent in the discarded first 60 s that runs after the window starts is in it. At a steady load the two are about equal. The records cannot give the part that belongs to abandoned requests alone. What they can show is whether the utilization per achieved rps changes at the step where status 0 first appears (`db_util_per_achieved_rps` in `step-summary.csv`; the idle level of the database, about 0.009, is in it):

| Scenario | Step | Status 0 | Utilization per achieved rps | Step before | Change |
| --- | --- | --- | --- | --- | --- |
| t-2 | 7 | 18 | 1.7045 | 1.7206 | -0.9% |
| t-3 | 6 | 22 | 2.7519 | 2.7761 | -0.9% |
| t-5 | 9 | 8 | 0.6785 | 0.6862 | -1.1% |
| t-4 | 10 | 2 | 0.2273 | 0.2241 | +1.4% |
| t-4 | 11 | 12 | 0.2280 | 0.2273 | +0.3% |
| t-4 | 12 | 1 | 0.2235 | 0.2280 | -2.0% |

Steps without status 0 move by about as much from one step to the next: `t-2` steps 4 to 6: +0.8% and -0.8%; `t-5` steps 5 to 8: -1.9%, -0.1% and -0.9%. So at the steps below saturation, status 0 leaves no change in the utilization larger than about 1 to 2%, if the utilization per rps would otherwise stay flat. That is a bound that rests on the flatness; it is not a measurement of the abandoned requests' share. The zip route is the one that goes from 500 to no response in these steps, and the table also fits what section 2 says: the database time of a zip request does not depend on which of the two timers ended it first.

### 3.4 Saturated steps: the figure is capped

A step at saturation cannot show more than 100% (2 CPUs), however much work is offered. The steps at 97% to 99% are of that kind, and so is `t-1` step 10 (87.8% over the window, 94% in its last minute). Their utilization per achieved rps falls below the line of the steps before:

| Scenario | Step | Utilization (%) | Utilization per achieved rps | Step before | Change |
| --- | --- | --- | --- | --- | --- |
| t-1 | 10 | 87.8 | 0.5914 | 0.6698 | -11.7% |
| t-2 | 8 | 99.0 | 1.4827 | 1.7045 | -13.0% |
| t-4 | 13 | 96.6 | 0.1944 | 0.2235 | -13.0% |
| t-5 | 10 | 97.1 | 0.6498 | 0.6785 | -4.2% |

This is the same in the scenarios that matched (`t-1`, `t-4`) and in those that did not. The measured limit rps is a straight line between the first valid step at 80% or more and the step before it (D159). To see how much a capped upper step moves the line, the same line was drawn with the upper step's utilization kept on the straight line of the step before it (`limit-sensitivity.csv`; an illustration of the size, not a score; no record or verdict is changed):

| Scenario | Lower step, upper step | Upper step utilization | Limit rps as measured | Limit rps if the upper step had kept straight | Change |
| --- | --- | --- | --- | --- | --- |
| t-1 | 9, 10 | 0.8779 | 1.3128 | 1.2006 | -8.5% |
| t-2 | 7, 8 | 0.9899 | 0.4966 | 0.4785 | -3.6% |
| t-3 | 5, 6 | 0.8118 | 0.2955 | 0.2930 | -0.8% |
| t-4 | 12, 13 | 0.9658 | 3.7646 | 3.5846 | -4.8% |
| t-5 | 9, 10 | 0.9706 | 1.2100 | 1.1833 | -2.2% |

The "as measured" column is the limit of D166 (the check: the same five values). The cap lowers the measured limit by about 1% to 9%, in the scenarios that matched as much as in those that did not. The three scenarios at 100,000 rows have a predicted limit 35% to 38% below the measured one; this effect does not explain that.

### 3.5 The window of the next scenario

A step starts only after the previous step's sampler has run its 60 s past the window, and within a scenario the database is idle at that point (the ten seconds before k6 starts are at 0.01 to 0.02 for `t-2` step 8, `t-5` step 10 and `t-3` step 6). Between two scenarios it was not: `t-3` step 1 began 14 s after `t-2` step 8 was recorded as ended. Its database was at 0.98 in the ten seconds before k6 started and at 0.93 to 0.98 in the bins from 0 to 30 s; it was 0.89 at 40 s, 0.57 at 50 s, 0.25 at 60 s and 0.21 at 70 s (the window of that step starts at 60 s), then 0.01 from 80 s to 100 s. So the backlog of `t-2` step 8 reached into the first 20 s of `t-3` step 1's window. If all of those two bins were carry-over, they would be 9.2 CPU-seconds out of the 1600 of that window, 0.6 points of its 14.6% (about 4% of the figure). It is an upper bound: part of those bins is that step's own load. Step 1 of `t-3` is a usable step (10% to 90%) in the score.

### 3.6 The rps check

The rps of a step is checked within 5% of the decided one, and a step that fails stops the scenario and changes how the limit is read (D158, D159). The requests that k6 cut at the end of its 30 s wait are not counted; the others, whatever their status, are. At `t-2` step 8, 11 requests were cut, 229 were counted of 240 planned, and the achieved rps is 0.6676 against 0.7 (-4.6%). The least number of requests that keeps a step within 5% is 229 (`min_requests_for_valid_rps`), so this step had **no request to spare**: one more request cut at the end would have made it invalid. Every other step of the 47 has at least 2 requests above its minimum. If that step had been invalid, D159 reads the limit as "extended": a line through the top two valid steps (steps 6 and 7), which gives 0.4767 instead of 0.4966 (-4.0%). That is not a score; it shows how close the edge was and that the effect on the limit would have been small.

## 4. What can be said and what cannot

Can be said, from the records:

- The load tool's time limit is 60 s, k6's default, and no step has a longer request recorded.
- At 100,000 rows the requests with no response are the zip export, with one exception (`t-2` step 8, saturated). Before that step the same route returned HTTP 500 after 52 to 60 s; the status changes where the load makes it last longer than 60 s.
- Status 0 itself does not enter the utilization or the rps check by any rule. The database keeps working after k6 has given up, so those requests' work stays in the database time of the window, in so far as it falls inside the window.
- In the steps below saturation, the utilization per rps is within about 1 to 2% of the step before whether or not status 0 is present, so status 0 does not explain a 1.4 to 1.5 times over-estimate.
- The saturated steps are capped, which lowers the measured limit by about 1% to 9% in all five scenarios; the three scenarios at 100,000 rows are off by 35% to 38%.

Cannot be said:

- The database time of the abandoned requests alone. That needs the database CPU time of one request per route (the per-route measurement of the same diagnosis); the product of that time and the counts above is an upper bound for the abandoned work.
- Whether php-fpm went on running a request after the client had gone. The database being busy after k6's exit shows that some statements went on; the logs do not tie them to those requests.
- Why the 500 comes at 52 to 60 s and not at 30 s (the reading in section 2 is not tested).
- The one-request differences between the web log and k6's count (two steps).
- Whether the load at the next scenario's start was carry-over only (section 3.5 gives a bound).
