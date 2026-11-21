# Estimate of the length of the sixth B at 180,000 rows (from the rehearsal; conditional)

Rehearsal facts used (30,000 rows, 10 steps): c (the part of a step outside the window and the preparation) 60 to 65 s, median 61; gap between two steps of a scenario median 2 s; gap between two scenarios (end of the last step to start of the next first step) median 36 s; waits at the end of a step 0 in all 10.

Formula: sum over scenarios s and steps k <= K_s of (window(rps_k) + W + c + wait) + gaps. W = warmup_sec of the prediction; for the longest request (the zip export) its upper bound is about 3.1815 s per 1,000 rows (170,000 rows: 540.86; 300,000 rows: 954.56; recorded), so **W is about 573 s at 180,000 rows (a premise: the bound stays linear; the real W is written by the prediction of T4)**. Windows are those of the spec (1,600 s at 0.025 rps, 1,143 s at 0.035 rps, ...).

| Case | Steps | Total |
| --- | --- | --- |
| A: the 170,000-row stops (8, 6, 9, 6, 10), the rehearsal's median c, no wait | 39 | 14 h 54 min (53643 s) |
| B: as A, with the largest c of the rehearsal | 39 | 14 h 56 min (53799 s) |
| C: as A, with the end-of-step waits of the 170,000-row record in order (2835 s in all) | 39 | 15 h 41 min (56477 s) |
| D: as A, with the old preparation of 60 s (what the same steps would take without the rule) | 39 | 9 h 20 min (33636 s) |

Conditions: the number of steps per scenario is decided by the limit rps of the prediction (T4); the cases above use the steps of the 170,000-row record (39 steps) only as a stand-in and **the real number can differ**. Each added step costs window + W + c (+ wait). With W = 573 the preparation alone adds 39 x (W - 60) = 20007 s (5 h 33 min) over the old 60 s for 39 steps. The waits at the end of a step are not seen at 30,000 rows (0 in all 10); at 170,000 rows 33 of 39 steps had one (largest 172 s), so case C is the nearer to a count at which a request outlives the window; a wait up to 1,800 s per step is possible. The rehearsal says nothing about whether the preparation removes the waits.

Not included: a stop and resume (the stop mark), a restart after a power or update reboot, the builds (about 90 s at 150,000 rows) and the preparation before the chain. These are figures of the rehearsal and of earlier records; none is a figure of the test.
