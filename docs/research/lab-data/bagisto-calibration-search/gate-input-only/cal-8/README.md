# The calibration gate on inputs alone with an eighth run (cal-8) added, for the Bagisto lab

**This was computed before any rate of the search was seen again** (no run of the search was measured after the app's CPU ruling, D271, and nothing at all was measured for this file: no database, no application and no lab container
was started). It follows the maintainer's ruling that, if the gate that is called after the rates are fixed refuses the set cal-1 to cal-7, a run cal-8 is added and the gate called again, and that the region the gate passes
with cal-8 is worked out on inputs first (decisions D271 and the ruling behind it). Written when the repository was at `c6d9a33` (`docs: record the app cpu ruling for the bagisto lab`, 2026-11-25 15:14:30 +0900); the calculation started 2026-11-25 15:20.

## What cal-8 is

Count 1,000 rows (0.1 x N_0, a count already seen in the capture), the eight routes evenly (the `even` mix), rate R_5 chosen as the rate of cal-4 is (from 1 rps, doubled until the database's utilization is 20% to 30%).
For R_5 the grid is **1, 2, 4, 8, 16, 32** (the grid of R_0 and R_1); a rate the rule would reach above 32 is not covered. The plan of cal-8 at R_5 is the same file as the `even` plan at that rate; its count is given to the gate as `--run-scale=cal-8:1000`.

## What was called

The call is the one of `../gate_input_only.py` (the calibration gate on files only, the shared-table copy of the estimate with `--tables=n_shared`, `--scale=10000`, cal-4 at 3,000 rows, cal-5 to cal-7 at 30,000 rows, cal-1/2/3 at R_0,
cal-4 at R_1, cal-5 at R_2, cal-6 at R_3, cal-7 at R_4), with cal-8 added. Because 169,344 calls of the command line are many, `gate_batch.php` calls **the same parts in the same order in one process**
(`CalibrationScenario::rpsOfPlan`, `InputLoader::loadParsed`, `CalibrationPoint::fromRoutes`, `CalibrationGate::evaluate`). **Check of the batch against the command line:** run on the grid of cal-1 to cal-7 (28,224 points: R_3 in
0.3, 0.37, 0.45, 0.7 x R_4 in 14 values x R_0, R_1 in 6 values x R_2 in 14 values), it gives for **28,224 of 28,224 points the same exit code, the same smallest degree to six decimals and the same run left out** as the command line gave
(`../grid-r3-0.3-0.37-r4-all.tsv` and `../grid-r3-0.45-0.7-r4-all.tsv`).

Grid of cal-1 to cal-8: the grid above x R_5 in 1, 2, 4, 8, 16, 32 = **169,344 points**. A point passes when the gate exits 0 (smallest degree at or above 0.50 over the whole set and over every set that leaves one run out).
Over the 8 runs the gate looks at the whole set and the 8 sets of 7 runs; over the 7 runs it looked at the whole set and the 7 sets of 6, so the sets of 6 are no longer asked about.

## Inputs (sha256)

| File | sha256 |
| --- | --- |
| estimate-shared/estimate.json | 92fd9b7b78a400f6e355c77d7492fa82a204fdb815fa1aaab196c7f4760a194c |
| poc/lab/scenarios/calibration-gate.php | c627e16c0d73d7bc40f7b5e9f6d83bef6d4f74b2222a91b2a86dba00d039a5c5 |
| poc/lab/scenarios/CalibrationScenario.php | e31fdffa6481474af377f4f39c941846f6b7613bdf6ff70ea276315f88f439f6 |
| poc/runtime/src/Simulate/Calibrate/CalibrationGate.php | 126dd4347cfda8194710ebfa9d0b58c42c9b4589d70b079f90d23956ccf53137 |
| poc/runtime/src/Simulate/Calibrate/CalibrationPoint.php | d4e26052342bb80448876315b5adac97550c452fcef5836cf80411258adb2005 |
| gate_batch.php | 6d9f9900b00f4d54f3e79b19ff1d1f45a1921ec42ecebcf88ad2a1967436a306 |
| the 49 plans used (`plans-sha256.txt`; sha256 of that list) | fc88d943f8d22c859091861a50330392cc7efd8bb2006a60f34ac9c4f87cf56e |

## Result

Everything below is on inputs alone: a point that passes is one at which the gate would open, not a rate that will be found by a measurement.

**Added up: cal-1 to cal-7 passes 315 of 28,224 points (1.1%); cal-1 to cal-8 passes 22,584 of 169,344 (13.3%).**
Adding cal-8 loses no point: every one of the 315 points that passed without it passes with at least one value of R_5, and at every one of the 28,224 points the best degree over R_5 is at or above the degree without cal-8
(the smallest degree of the set of 8 is never below that of the set of 7, since it adds rows to the matrix). The largest smallest-degree is 0.722065 (R_3 0.7, R_4 0.7, R_0 1, R_1 4, R_2 0.7, R_5 8; without cal-8).

| R_3 | cal-1 to cal-7: passing / points (largest degree) | cal-1 to cal-8: passing / points (largest degree) | points of (R_0, R_1, R_2, R_4) that pass with some R_5 / 7,056 |
| --- | --- | --- | --- |
| 0.3 | 17 / 7,056 (0.508108) | 4,494 / 42,336 (0.660081) | 1,265 |
| 0.37 | 41 / 7,056 (0.533887) | 4,864 / 42,336 (0.676943) | 1,390 |
| 0.45 | 86 / 7,056 (0.551415) | 5,805 / 42,336 (0.694564) | 1,618 |
| 0.7 | 171 / 7,056 (0.571818) | 7,421 / 42,336 (0.722065) | 2,066 |

### By R_5

| R_5 | cal-1 to cal-7 passing / points | cal-1 to cal-8 passing / points (largest degree) |
| --- | --- | --- |
| 1 | 0 / 0 | 671 / 28,224 (0.601877) |
| 2 | 0 / 0 | 1,812 / 28,224 (0.637294) |
| 4 | 0 / 0 | 3,531 / 28,224 (0.687441) |
| 8 | 0 / 0 | 5,290 / 28,224 (0.722065) |
| 16 | 0 / 0 | 5,989 / 28,224 (0.712555) |
| 32 | 0 / 0 | 5,291 / 28,224 (0.704464) |

The values that open the most are 8 and 16; at R_5 = 1 the gain is the smallest.

### Where the passing region lies, by each rate (all four values of R_3 together)

| R_0 | cal-1 to cal-7 passing / points | cal-1 to cal-8 passing / points (largest degree) |
| --- | --- | --- |
| 1 | 276 / 4,704 | 10,765 / 28,224 (0.722065) |
| 2 | 39 / 4,704 | 8,390 / 28,224 (0.670593) |
| 4 | 0 / 4,704 | 2,919 / 28,224 (0.645348) |
| 8 | 0 / 4,704 | 510 / 28,224 (0.597766) |
| 16 | 0 / 4,704 | 0 / 28,224 (0.479350) |
| 32 | 0 / 4,704 | 0 / 28,224 (0.312178) |

| R_1 | cal-1 to cal-7 passing / points | cal-1 to cal-8 passing / points (largest degree) |
| --- | --- | --- |
| 1 | 0 / 4,704 | 2,064 / 28,224 (0.667319) |
| 2 | 48 / 4,704 | 3,203 / 28,224 (0.704708) |
| 4 | 179 / 4,704 | 5,402 / 28,224 (0.722065) |
| 8 | 88 / 4,704 | 6,751 / 28,224 (0.711350) |
| 16 | 0 / 4,704 | 4,353 / 28,224 (0.675895) |
| 32 | 0 / 4,704 | 811 / 28,224 (0.661817) |

| R_2 | cal-1 to cal-7 passing / points | cal-1 to cal-8 passing / points (largest degree) |
| --- | --- | --- |
| 0.05 | 22 / 2,016 | 1,520 / 12,096 (0.713852) |
| 0.07 | 22 / 2,016 | 1,524 / 12,096 (0.713953) |
| 0.1 | 22 / 2,016 | 1,524 / 12,096 (0.714166) |
| 0.15 | 22 / 2,016 | 1,528 / 12,096 (0.714682) |
| 0.2 | 22 / 2,016 | 1,544 / 12,096 (0.715390) |
| 0.3 | 22 / 2,016 | 1,567 / 12,096 (0.717327) |
| 0.37 | 25 / 2,016 | 1,602 / 12,096 (0.719034) |
| 0.45 | 44 / 2,016 | 1,657 / 12,096 (0.721259) |
| 0.7 | 43 / 2,016 | 1,793 / 12,096 (0.722065) |
| 1 | 54 / 2,016 | 1,965 / 12,096 (0.721836) |
| 1.5 | 17 / 2,016 | 1,957 / 12,096 (0.710201) |
| 2.2 | 0 / 2,016 | 1,694 / 12,096 (0.700771) |
| 3.3 | 0 / 2,016 | 1,555 / 12,096 (0.674065) |
| 5 | 0 / 2,016 | 1,154 / 12,096 (0.664670) |

| R_4 | cal-1 to cal-7 passing / points | cal-1 to cal-8 passing / points (largest degree) |
| --- | --- | --- |
| 0.05 | 17 / 2,016 | 1,478 / 12,096 (0.708546) |
| 0.07 | 17 / 2,016 | 1,482 / 12,096 (0.708789) |
| 0.1 | 17 / 2,016 | 1,491 / 12,096 (0.709300) |
| 0.15 | 17 / 2,016 | 1,528 / 12,096 (0.710525) |
| 0.2 | 21 / 2,016 | 1,540 / 12,096 (0.712175) |
| 0.3 | 44 / 2,016 | 1,645 / 12,096 (0.716514) |
| 0.37 | 43 / 2,016 | 1,692 / 12,096 (0.720134) |
| 0.45 | 38 / 2,016 | 1,769 / 12,096 (0.721836) |
| 0.7 | 64 / 2,016 | 2,076 / 12,096 (0.722065) |
| 1 | 37 / 2,016 | 2,086 / 12,096 (0.711361) |
| 1.5 | 0 / 2,016 | 1,844 / 12,096 (0.711350) |
| 2.2 | 0 / 2,016 | 1,657 / 12,096 (0.684190) |
| 3.3 | 0 / 2,016 | 1,217 / 12,096 (0.675895) |
| 5 | 0 / 2,016 | 1,079 / 12,096 (0.661817) |

Reading the tables as inputs only: **with cal-8 the region is no longer a corner.** Without it, the passing points had R_0 of 1 or 2 only, R_1 of 2 to 8, R_2 up to 1.5 and R_4 up to 1;
with it, R_3 of every value passes at R_4 of every value (5 included), and R_0 up to 8, R_1 of any of the six values and R_2 of any of the 14 pass somewhere. But R_0 of 16 or 32 still passes nowhere (largest degree 0.479350 and 0.312178),
and the passing share falls steeply with R_0 (R_0 = 1: 38%, 2: 30%, 4: 10%, 8: 2%). The run left out in the smallest set of a passing point is cal-8 for 11,876 of the 22,584: the binding set is then the set of the seven
runs cal-1 to cal-7 itself, so the gate on cal-1 to cal-8 is bounded above by the gate on cal-1 to cal-7 taken as a whole.

### By pair of rates (R_3, R_4)

| R_3 | R_4 | cal-1 to cal-7: passing / 504 | largest degree | cal-1 to cal-8: passing / 3,024 | points that pass with some R_5 / 504 | largest degree |
| --- | --- | --- | --- | --- | --- | --- |
| 0.3 | 0.05 | 1 | 0.501325 | 295 | 81 | 0.646686 |
| 0.3 | 0.07 | 1 | 0.502453 | 296 | 81 | 0.646742 |
| 0.3 | 0.1 | 1 | 0.504782 | 298 | 82 | 0.646859 |
| 0.3 | 0.15 | 1 | 0.503168 | 306 | 85 | 0.647130 |
| 0.3 | 0.2 | 2 | 0.505187 | 304 | 84 | 0.647475 |
| 0.3 | 0.3 | 6 | 0.508108 | 327 | 84 | 0.648252 |
| 0.3 | 0.37 | 5 | 0.505070 | 335 | 84 | 0.649320 |
| 0.3 | 0.45 | 0 | 0.496535 | 353 | 88 | 0.650627 |
| 0.3 | 0.7 | 0 | 0.463014 | 402 | 91 | 0.653076 |
| 0.3 | 1 | 0 | 0.416489 | 392 | 103 | 0.653823 |
| 0.3 | 1.5 | 0 | 0.384819 | 341 | 96 | 0.660081 |
| 0.3 | 2.2 | 0 | 0.353877 | 364 | 112 | 0.655979 |
| 0.3 | 3.3 | 0 | 0.355923 | 255 | 99 | 0.657550 |
| 0.3 | 5 | 0 | 0.343068 | 226 | 95 | 0.645892 |
| 0.37 | 0.05 | 2 | 0.520202 | 305 | 85 | 0.673693 |
| 0.37 | 0.07 | 2 | 0.519860 | 306 | 85 | 0.673668 |
| 0.37 | 0.1 | 2 | 0.519135 | 308 | 86 | 0.673615 |
| 0.37 | 0.15 | 2 | 0.517370 | 318 | 90 | 0.673479 |
| 0.37 | 0.2 | 3 | 0.517362 | 322 | 91 | 0.673274 |
| 0.37 | 0.3 | 10 | 0.532221 | 347 | 91 | 0.672602 |
| 0.37 | 0.37 | 11 | 0.533887 | 358 | 92 | 0.676139 |
| 0.37 | 0.45 | 9 | 0.532889 | 371 | 93 | 0.676943 |
| 0.37 | 0.7 | 0 | 0.493467 | 458 | 106 | 0.673252 |
| 0.37 | 1 | 0 | 0.441224 | 444 | 113 | 0.662977 |
| 0.37 | 1.5 | 0 | 0.400903 | 413 | 118 | 0.668450 |
| 0.37 | 2.2 | 0 | 0.363655 | 389 | 125 | 0.658950 |
| 0.37 | 3.3 | 0 | 0.360426 | 278 | 110 | 0.658980 |
| 0.37 | 5 | 0 | 0.344872 | 247 | 105 | 0.647894 |
| 0.45 | 0.05 | 5 | 0.544166 | 377 | 102 | 0.687334 |
| 0.45 | 0.07 | 5 | 0.544750 | 378 | 102 | 0.687253 |
| 0.45 | 0.1 | 5 | 0.545971 | 380 | 103 | 0.687079 |
| 0.45 | 0.15 | 5 | 0.548467 | 389 | 106 | 0.686654 |
| 0.45 | 0.2 | 6 | 0.545869 | 393 | 109 | 0.686058 |
| 0.45 | 0.3 | 13 | 0.538650 | 419 | 112 | 0.691120 |
| 0.45 | 0.37 | 11 | 0.547161 | 436 | 119 | 0.694564 |
| 0.45 | 0.45 | 12 | 0.551415 | 465 | 124 | 0.692277 |
| 0.45 | 0.7 | 24 | 0.521496 | 541 | 126 | 0.693608 |
| 0.45 | 1 | 0 | 0.466557 | 548 | 127 | 0.675741 |
| 0.45 | 1.5 | 0 | 0.420179 | 474 | 128 | 0.679608 |
| 0.45 | 2.2 | 0 | 0.376057 | 431 | 136 | 0.665055 |
| 0.45 | 3.3 | 0 | 0.366468 | 300 | 114 | 0.660932 |
| 0.45 | 5 | 0 | 0.347423 | 274 | 110 | 0.650630 |
| 0.7 | 0.05 | 9 | 0.564350 | 501 | 139 | 0.708546 |
| 0.7 | 0.07 | 9 | 0.564093 | 502 | 139 | 0.708789 |
| 0.7 | 0.1 | 9 | 0.563547 | 505 | 141 | 0.709300 |
| 0.7 | 0.15 | 9 | 0.562216 | 515 | 145 | 0.710525 |
| 0.7 | 0.2 | 10 | 0.560368 | 521 | 146 | 0.712175 |
| 0.7 | 0.3 | 15 | 0.562078 | 552 | 152 | 0.716514 |
| 0.7 | 0.37 | 16 | 0.569393 | 563 | 154 | 0.720134 |
| 0.7 | 0.45 | 17 | 0.571818 | 580 | 154 | 0.721836 |
| 0.7 | 0.7 | 40 | 0.568310 | 675 | 160 | 0.722065 |
| 0.7 | 1 | 37 | 0.519370 | 702 | 164 | 0.711361 |
| 0.7 | 1.5 | 0 | 0.477069 | 616 | 159 | 0.711350 |
| 0.7 | 2.2 | 0 | 0.417702 | 473 | 147 | 0.684190 |
| 0.7 | 3.3 | 0 | 0.389925 | 384 | 135 | 0.675895 |
| 0.7 | 5 | 0 | 0.358450 | 332 | 131 | 0.661817 |

## Files

- `grid-cal-1-to-8.tsv.gz`: all 169,344 points (R_3, R_4, R_0, R_1, R_2, R_5, gate exit, smallest degree, run left out), sorted as text.
- `summary-by-pair.tsv`: the table above by pair (R_3, R_4).
- `gate_batch.php`: the driver that was run (in a throw-away container of the dev image with no network, the repository, the search record's directory and a work directory mounted).
- `plans-sha256.txt`: sha256 of each of the 49 plan files used.
