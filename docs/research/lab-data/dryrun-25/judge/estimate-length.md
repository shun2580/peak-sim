# Length of the lab backtest on Bagisto, from the rehearsal (conditional)

Rehearsal facts used (30,000 rows, 10 steps, all valid): c 60 to 64 s (median 64, largest 64); gap between two steps of a scenario median 3 s; gap between two scenarios (end of the last step to the start of the next first step) median 70 s; the first step starts 50 s after the chain; wait at the end of a step 0 in all 10 (no request outlived its window).

Formula: sum over the five scenarios and the steps k <= K of (window(rps_k) + W + c) + gaps, with c = 64 s (the largest seen). Windows of the ladder: 0.025 rps: 1600 s, 0.035 rps: 1142 s, 0.05 rps: 800 s, 0.07 rps: 571 s, 0.1 rps: 400 s, 0.15 rps: 533 s, 0.2 rps: 400 s, 0.3 rps: 400 s, 0.45 rps: 355 s, 0.7 rps: 342 s, 1 rps: 320 s, 1.5 rps: 320 s, 2.2 rps: 309 s, 3.3 rps: 303 s, 5 rps: 304 s.

**W is the preparation of a step**, `warmup_sec` of the backtest's own prediction (the largest, over the routes that stay in the mix, of the upper bound of one request's time). In the rehearsal it was **231 s** for every stage (route `GET /api/products`, upper bound 230.95 s). **If that bound grows in proportion to the count** (a premise; the rehearsal has one count), it grows by about 77 s per 10,000 rows and passes 900 s (half of the 1,800 s request timeout, the line at which the prediction tool stops with exit 39) at about 117,000 rows. The backtest's count is above that, so **its prediction can stop on this line unless that route is taken out of the mix by the allocation at that count, or the bound is smaller than a straight line gives**. This is read from the backtest's own estimate when its prediction is made; the rehearsal cannot say which.

| Steps per scenario K | W = 231 s | W = 450 s | W = 900 s |
| --- | --- | --- | --- |
| 6 | 9 h 34 min | 11 h 24 min | 15 h 09 min |
| 8 | 11 h 31 min | 13 h 57 min | 18 h 57 min |
| 10 | 13 h 18 min | 16 h 21 min | 22 h 36 min |
| 12 | 15 h 01 min | 18 h 40 min | 26 h 10 min |
| 15 | 17 h 32 min | 22 h 06 min | 31 h 28 min |

The number of steps per scenario is decided by the limit rps of the backtest's prediction and by the stopping rule (a scenario goes on up the ladder until it stops: 80% utilization or a rate that does not come out); K is the same for the five scenarios in this table only for the sake of a table, and the real number differs by scenario. Each added step costs its window + W + c (+ wait): for example with W = 450 s the step at 1 rps costs 320 + 450 + 64 = 834 s.
The rehearsal prediction at 30,000 rows gives limit rps (point) of 2.5 to 5.9 for the five scenarios; at a larger count the limit rps is lower, so K is smaller than the 15 steps of the ladder; the rehearsal cannot say by how much.
Not included: a stop and resume (the stop mark), a restart after a reboot (the pause of the operating system's updates ends 2026-11-29 00:02 JST), the build and the analyze of the backtest's database, the preparation before the chain (build, estimate, prediction), and waits at the end of a step (0 here; up to 1,800 s per step is possible at a count where a request outlives its window). These are figures of the rehearsal; none is a figure of the backtest.
