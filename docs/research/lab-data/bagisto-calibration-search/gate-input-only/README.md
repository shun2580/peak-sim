# The calibration gate on inputs alone, for the rates the app can carry (Bagisto lab)

Nothing here was measured: no database, no application and no lab container was started. The gate (`poc/lab/scenarios/calibration-gate.php`) was called 14,112 times on files
(one throw-away container of the dev image, no network, read-only root, inputs mounted read-only). This extends the earlier table in `../gate-standin/grid-pairs-a.tsv`,
which covered R_3 in 0.3, 0.37, 0.45, 0.7 against five values of R_4, to **R_3 in 0.3 and 0.37 against all 14 values of R_4**.

## What was called

- Same call as `../gate-standin/gategrid.py` (a copy of its logic is `gate_input_only.py`; only the two lists of values differ): the shared-table copy of the estimate (`--tables=n_shared`), `--scale=10000`,
  cal-4 at 3,000 rows, cal-5 to cal-7 at 30,000 rows; cal-1/2/3 at R_0 (even, first80, odd80), cal-4 at R_1 (even), cal-5 at R_2 (even), cal-6 at R_3 (the route ranked 1 at 80%), cal-7 at R_4 (the route ranked 2 at 80%).
- Grid: R_3 in 0.3, 0.37; R_4 in the 13 stage values of section 7.2 and 0.37 (0.05, 0.07, 0.1, 0.15, 0.2, 0.3, 0.37, 0.45, 0.7, 1, 1.5, 2.2, 3.3, 5); R_0 and R_1 in 1, 2, 4, 8, 16, 32; R_2 in the same 14 values as R_4.
  2 x 14 x 6 x 6 x 14 = **14,112 points**. A point passes when the gate exits 0 (smallest degree at or above 0.50 over the whole set and over every set that leaves one run out).
- 0.84 is not in the grid (it is not a stage value); the earlier table has it for R_4 only as a desk-estimate value.

## Inputs (sha256)

| File | sha256 |
| --- | --- |
| estimate-shared/estimate.json | 92fd9b7b78a400f6e355c77d7492fa82a204fdb815fa1aaab196c7f4760a194c |
| route-list.json | 466bf7601a3e99c7b4453401f24e696142e2424bc08c23ffd5950394966f9616 |
| alloc_top1.json | af9300a5e7d057c0f4015f4acf823af053a95dddd2b77073e7f193bc01694670 |
| alloc_top2.json | d45d89ac25d0ea1ca56570c5df7917d84f158ecd40db19f576ce87dd97887e5b |
| poc/lab/scenarios/calibration-gate.php | c627e16c0d73d7bc40f7b5e9f6d83bef6d4f74b2222a91b2a86dba00d039a5c5 |
| the 47 plans used (`plans-sha256.txt`; sha256 of that list) | 82ab9ae5c11c66a8d978774a8cfce75d5bd7fb2923d9d0eaceac37cc81ff6e50 |

The estimate is byte-identical to the one the earlier gate grid (`../../bagisto-calibration-gate/`) used. The repository was at `4540aa8` (2026-11-25 14:51:53 +0900) when the call started (14:57).

## Result per pair of rates

| R_3 | R_4 | points | passing | largest degree (at R_0, R_1, R_2) |
| --- | --- | --- | --- | --- |
| 0.3 | 0.05 | 504 | 1 | 0.501325 (1, 4, 0.45) |
| 0.3 | 0.07 | 504 | 1 | 0.502453 (1, 4, 0.45) |
| 0.3 | 0.1 | 504 | 1 | 0.504782 (1, 4, 0.45) |
| 0.3 | 0.15 | 504 | 1 | 0.503168 (1, 4, 0.45) |
| 0.3 | 0.2 | 504 | 2 | 0.505187 (1, 4, 0.37) |
| 0.3 | 0.3 | 504 | 6 | 0.508108 (1, 4, 0.2) |
| 0.3 | 0.37 | 504 | 5 | 0.505070 (1, 4, 0.05) |
| 0.3 | 0.45 | 504 | 0 | 0.496535 (1, 4, 0.05) |
| 0.3 | 0.7 | 504 | 0 | 0.463014 (1, 2, 0.05) |
| 0.3 | 1 | 504 | 0 | 0.416489 (1, 2, 0.05) |
| 0.3 | 1.5 | 504 | 0 | 0.384819 (2, 2, 0.05) |
| 0.3 | 2.2 | 504 | 0 | 0.353877 (2, 2, 0.05) |
| 0.3 | 3.3 | 504 | 0 | 0.355923 (4, 8, 0.05) |
| 0.3 | 5 | 504 | 0 | 0.343068 (8, 16, 2.2) |
| 0.37 | 0.05 | 504 | 2 | 0.520202 (1, 4, 0.7) |
| 0.37 | 0.07 | 504 | 2 | 0.519860 (1, 4, 0.7) |
| 0.37 | 0.1 | 504 | 2 | 0.519135 (1, 4, 0.7) |
| 0.37 | 0.15 | 504 | 2 | 0.517370 (1, 4, 0.7) |
| 0.37 | 0.2 | 504 | 3 | 0.517362 (1, 4, 0.45) |
| 0.37 | 0.3 | 504 | 10 | 0.532221 (1, 4, 0.45) |
| 0.37 | 0.37 | 504 | 11 | 0.533887 (1, 4, 0.3) |
| 0.37 | 0.45 | 504 | 9 | 0.532889 (1, 4, 0.05) |
| 0.37 | 0.7 | 504 | 0 | 0.493467 (1, 2, 0.05) |
| 0.37 | 1 | 504 | 0 | 0.441224 (1, 2, 0.05) |
| 0.37 | 1.5 | 504 | 0 | 0.400903 (2, 2, 0.05) |
| 0.37 | 2.2 | 504 | 0 | 0.363655 (2, 2, 0.05) |
| 0.37 | 3.3 | 504 | 0 | 0.360426 (4, 8, 0.05) |
| 0.37 | 5 | 504 | 0 | 0.344872 (8, 16, 2.2) |

Totals: **58 of 14,112 points pass** (17 with R_3 = 0.3, 41 with R_3 = 0.37). Every other point exits 14 (refused); no other exit code and no unreadable result occurred.
No point passes with R_4 above 0.45 (R_3 = 0.3 or 0.37). With R_3 = 0.3 and R_4 = 0.45 none passes either (largest degree 0.496535).

## The passing points (all 58)

- R_0 = 1 at every one of them; R_1 = 4 at 54 of them, 8 at 2 and 2 at 2.
- Smallest degree 0.500529 to 0.533887, so most of them sit within 0.034 of the threshold 0.50; the smallest set is the one without cal-4 (30 points) or without cal-6 (28).

| R_3 | R_4 | R_0 | R_1 | R_2 | smallest degree | run left out in the smallest set |
| --- | --- | --- | --- | --- | --- | --- |
| 0.3 | 0.05 | 1 | 4 | 0.45 | 0.501325 | cal-6 |
| 0.3 | 0.07 | 1 | 4 | 0.45 | 0.502453 | cal-6 |
| 0.3 | 0.1 | 1 | 4 | 0.45 | 0.504782 | cal-4 |
| 0.3 | 0.15 | 1 | 4 | 0.45 | 0.503168 | cal-4 |
| 0.3 | 0.2 | 1 | 4 | 0.37 | 0.505187 | cal-6 |
| 0.3 | 0.2 | 1 | 4 | 0.45 | 0.500925 | cal-4 |
| 0.3 | 0.3 | 1 | 4 | 0.05 | 0.500529 | cal-6 |
| 0.3 | 0.3 | 1 | 4 | 0.07 | 0.501053 | cal-6 |
| 0.3 | 0.3 | 1 | 4 | 0.1 | 0.502154 | cal-6 |
| 0.3 | 0.3 | 1 | 4 | 0.15 | 0.504787 | cal-6 |
| 0.3 | 0.3 | 1 | 4 | 0.2 | 0.508108 | cal-4 |
| 0.3 | 0.3 | 1 | 4 | 0.3 | 0.503847 | cal-4 |
| 0.3 | 0.37 | 1 | 4 | 0.05 | 0.505070 | cal-4 |
| 0.3 | 0.37 | 1 | 4 | 0.07 | 0.504866 | cal-4 |
| 0.3 | 0.37 | 1 | 4 | 0.1 | 0.504434 | cal-4 |
| 0.3 | 0.37 | 1 | 4 | 0.15 | 0.503379 | cal-4 |
| 0.3 | 0.37 | 1 | 4 | 0.2 | 0.501913 | cal-4 |
| 0.37 | 0.05 | 1 | 4 | 0.45 | 0.501325 | cal-6 |
| 0.37 | 0.05 | 1 | 4 | 0.7 | 0.520202 | cal-4 |
| 0.37 | 0.07 | 1 | 4 | 0.45 | 0.502453 | cal-6 |
| 0.37 | 0.07 | 1 | 4 | 0.7 | 0.519860 | cal-4 |
| 0.37 | 0.1 | 1 | 4 | 0.45 | 0.504801 | cal-6 |
| 0.37 | 0.1 | 1 | 4 | 0.7 | 0.519135 | cal-4 |
| 0.37 | 0.15 | 1 | 4 | 0.45 | 0.510282 | cal-6 |
| 0.37 | 0.15 | 1 | 4 | 0.7 | 0.517370 | cal-4 |
| 0.37 | 0.2 | 1 | 4 | 0.37 | 0.505187 | cal-6 |
| 0.37 | 0.2 | 1 | 4 | 0.45 | 0.517362 | cal-6 |
| 0.37 | 0.2 | 1 | 4 | 0.7 | 0.514932 | cal-4 |
| 0.37 | 0.3 | 1 | 4 | 0.05 | 0.500529 | cal-6 |
| 0.37 | 0.3 | 1 | 4 | 0.07 | 0.501053 | cal-6 |
| 0.37 | 0.3 | 1 | 4 | 0.1 | 0.502154 | cal-6 |
| 0.37 | 0.3 | 1 | 4 | 0.15 | 0.504787 | cal-6 |
| 0.37 | 0.3 | 1 | 4 | 0.2 | 0.508322 | cal-6 |
| 0.37 | 0.3 | 1 | 4 | 0.3 | 0.517545 | cal-6 |
| 0.37 | 0.3 | 1 | 4 | 0.37 | 0.525166 | cal-6 |
| 0.37 | 0.3 | 1 | 4 | 0.45 | 0.532221 | cal-4 |
| 0.37 | 0.3 | 1 | 4 | 0.7 | 0.508154 | cal-4 |
| 0.37 | 0.3 | 1 | 8 | 0.7 | 0.500936 | cal-6 |
| 0.37 | 0.37 | 1 | 2 | 0.7 | 0.502040 | cal-4 |
| 0.37 | 0.37 | 1 | 4 | 0.05 | 0.520784 | cal-6 |
| 0.37 | 0.37 | 1 | 4 | 0.07 | 0.521183 | cal-6 |
| 0.37 | 0.37 | 1 | 4 | 0.1 | 0.522024 | cal-6 |
| 0.37 | 0.37 | 1 | 4 | 0.15 | 0.524037 | cal-6 |
| 0.37 | 0.37 | 1 | 4 | 0.2 | 0.526751 | cal-6 |
| 0.37 | 0.37 | 1 | 4 | 0.3 | 0.533887 | cal-6 |
| 0.37 | 0.37 | 1 | 4 | 0.37 | 0.530913 | cal-4 |
| 0.37 | 0.37 | 1 | 4 | 0.45 | 0.525158 | cal-4 |
| 0.37 | 0.37 | 1 | 4 | 0.7 | 0.502040 | cal-4 |
| 0.37 | 0.37 | 1 | 8 | 0.7 | 0.502040 | cal-4 |
| 0.37 | 0.45 | 1 | 2 | 0.45 | 0.501558 | cal-6 |
| 0.37 | 0.45 | 1 | 4 | 0.05 | 0.532889 | cal-4 |
| 0.37 | 0.45 | 1 | 4 | 0.07 | 0.532673 | cal-4 |
| 0.37 | 0.45 | 1 | 4 | 0.1 | 0.532214 | cal-4 |
| 0.37 | 0.45 | 1 | 4 | 0.15 | 0.531095 | cal-4 |
| 0.37 | 0.45 | 1 | 4 | 0.2 | 0.529541 | cal-4 |
| 0.37 | 0.45 | 1 | 4 | 0.3 | 0.525176 | cal-4 |
| 0.37 | 0.45 | 1 | 4 | 0.37 | 0.521183 | cal-4 |
| 0.37 | 0.45 | 1 | 4 | 0.45 | 0.515751 | cal-4 |

## Files

- `grid-r3-0.3-0.37-r4-all.tsv`: all 14,112 points (R_3, R_4, R_0, R_1, R_2, gate exit, smallest degree, run left out).
- `passing-points.tsv`: the 58 that pass.
- `plans-sha256.txt`: sha256 of each of the 47 plan files used.
- `gate_input_only.py`: the driver that was run (container name and paths as run; it needs a container of the dev image with the repository, the search record's directory and a work directory mounted).

## Addendum: R_3 = 0.45 and 0.7 against all 14 values of R_4

The same call, driver and inputs (sha256 above; the repository was at the commit that added this directory) with R_3 in 0.45 and 0.7: another 14,112 points, nothing measured.
Files: `grid-r3-0.45-0.7-r4-all.tsv` (all points) and `passing-points-r3-0.45-0.7.tsv` (the passing ones). Where a pair was also in the earlier table
(`../gate-standin/grid-pairs-a.tsv`), the counts agree (R_3 = 0.45 with R_4 = 0.7: 24; R_3 = 0.7 with R_4 = 1: 37).

| R_3 | R_4 | points | passing | largest degree (at R_0, R_1, R_2) |
| --- | --- | --- | --- | --- |
| 0.45 | 0.05 | 504 | 5 | 0.544166 (1, 4, 0.7) |
| 0.45 | 0.07 | 504 | 5 | 0.544750 (1, 4, 0.7) |
| 0.45 | 0.1 | 504 | 5 | 0.545971 (1, 4, 0.7) |
| 0.45 | 0.15 | 504 | 5 | 0.548467 (1, 4, 0.7) |
| 0.45 | 0.2 | 504 | 6 | 0.545869 (1, 4, 0.7) |
| 0.45 | 0.3 | 504 | 13 | 0.538650 (1, 4, 0.7) |
| 0.45 | 0.37 | 504 | 11 | 0.547161 (1, 4, 0.45) |
| 0.45 | 0.45 | 504 | 12 | 0.551415 (1, 4, 0.3) |
| 0.45 | 0.7 | 504 | 24 | 0.521496 (1, 4, 0.05) |
| 0.45 | 1 | 504 | 0 | 0.466557 (1, 2, 0.05) |
| 0.45 | 1.5 | 504 | 0 | 0.420179 (2, 2, 0.05) |
| 0.45 | 2.2 | 504 | 0 | 0.376057 (2, 2, 0.05) |
| 0.45 | 3.3 | 504 | 0 | 0.366468 (4, 4, 0.05) |
| 0.45 | 5 | 504 | 0 | 0.347423 (8, 16, 1.5) |
| 0.7 | 0.05 | 504 | 9 | 0.564350 (1, 4, 1) |
| 0.7 | 0.07 | 504 | 9 | 0.564093 (1, 4, 1) |
| 0.7 | 0.1 | 504 | 9 | 0.563547 (1, 4, 1) |
| 0.7 | 0.15 | 504 | 9 | 0.562216 (1, 4, 1) |
| 0.7 | 0.2 | 504 | 10 | 0.560368 (1, 4, 1) |
| 0.7 | 0.3 | 504 | 15 | 0.562078 (1, 4, 0.7) |
| 0.7 | 0.37 | 504 | 16 | 0.569393 (1, 4, 0.7) |
| 0.7 | 0.45 | 504 | 17 | 0.571818 (1, 4, 0.7) |
| 0.7 | 0.7 | 504 | 40 | 0.568310 (1, 4, 0.05) |
| 0.7 | 1 | 504 | 37 | 0.519370 (1, 4, 0.05) |
| 0.7 | 1.5 | 504 | 0 | 0.477069 (2, 4, 0.05) |
| 0.7 | 2.2 | 504 | 0 | 0.417702 (2, 4, 0.05) |
| 0.7 | 3.3 | 504 | 0 | 0.389925 (4, 4, 0.05) |
| 0.7 | 5 | 504 | 0 | 0.358450 (8, 16, 0.05) |

Totals: **257 of 14,112 points pass** (86 with R_3 = 0.45, 171 with R_3 = 0.7). Every other point exits 14; no other exit code and no unreadable result occurred.
With R_3 = 0.45 no point passes at R_4 of 1 or more; with R_3 = 0.7 none at 1.5 or more. Over the passing points: R_0 is 1 (218 points) or 2 (39); R_1 is 4 (125), 8 (86) or 2 (46);
the smallest degree is 0.500303 to 0.571818; the smallest set leaves out cal-6 (149) or cal-4 (108).

Over the four values of R_3 (0.3, 0.37, 0.45, 0.7): 315 of 28,224 points pass.
