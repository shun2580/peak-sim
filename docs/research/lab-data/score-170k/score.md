# Lab backtest: score of the ladders

Threshold: 80.0%. Usable stage: valid and observed 10.0% to 90.0%.
Scenarios 5 · stages measured 39 · valid 39 · usable 32 · scenarios whose limit rps was measured 5.

## Verdict: PASS at the minimum, target not reached

| Item | Value | Minimum | Target |
| --- | --- | --- | --- |
| 1. mean abs. difference smaller than the naive estimate | 2.54 pt vs naive 32.98 pt | met | met |
| 2. mean abs. difference (lines 15 / 5 points) | 2.54 pt | met | met |
| 3. "exceeded the threshold" agrees, all valid stages (80% or more) | 38/39 = 97.4% (naive 87.2%) | met | met |
| 4. predicted limit rps within ±20.0% / ±10.0% of the measured one (80% or more) | 5/5 and 1/5 | met | not met |

Item 4 for the other two predictions, for the record (not used for the verdict): safe side 5/5 and 1/5; naive estimate 0/5 and 0/5 (within the minimum and the target tolerance).

## Scenario s-1

| rps | point | safe | naive | observed | valid | usable | point − obs (pt) | naive − obs (pt) | predicted over | observed over | agree |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 0.025 | 10.6% | 10.6% | 0.9% | 11.3% | yes | yes | -0.73 | -10.42 | no | no | yes |
| 0.035 | 14.8% | 14.8% | 1.3% | 14.9% | yes | yes | -0.08 | -13.65 | no | no | yes |
| 0.05 | 21.2% | 21.2% | 1.8% | 20.3% | yes | yes | +0.92 | -18.47 | no | no | yes |
| 0.07 | 29.7% | 29.7% | 2.5% | 27.9% | yes | yes | +1.77 | -25.37 | no | no | yes |
| 0.1 | 42.4% | 42.4% | 3.6% | 42.9% | yes | yes | -0.52 | -39.28 | no | no | yes |
| 0.15 | 63.6% | 63.6% | 5.5% | 61.8% | yes | yes | +1.78 | -56.37 | no | no | yes |
| 0.2 | 84.8% | 84.8% | 7.3% | 76.0% | yes | yes | +8.79 | -68.74 | yes | no | no |
| 0.3 | 127.2% | 127.3% | 10.9% | 96.4% | yes | no | +30.87 | -85.44 | yes | yes | yes |
| 0.45 | 190.8% | 190.9% | 16.4% | not measured | | | | | | | |
| 0.7 | 296.9% | 296.9% | 25.5% | not measured | | | | | | | |
| 1 | 424.1% | 424.2% | 36.4% | not measured | | | | | | | |
| 1.5 | 636.2% | 636.3% | 54.6% | not measured | | | | | | | |
| 2.2 | 933.0% | 933.2% | 80.1% | not measured | | | | | | | |
| 3.3 | 1399.5% | 1399.8% | 120.2% | not measured | | | | | | | |
| 5 | 2120.5% | 2121.0% | 182.1% | not measured | | | | | | | |

Measured limit rps: 0.2195 (interpolated between two valid stages).
Predicted limit rps: point 0.1886, safe 0.1886, naive 2.1964. Relative error: point -14.1%, safe -14.1%, naive +900.5%.

## Scenario s-2

| rps | point | safe | naive | observed | valid | usable | point − obs (pt) | naive − obs (pt) | predicted over | observed over | agree |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 0.025 | 19.8% | 19.8% | 0.9% | 19.5% | yes | yes | +0.29 | -18.58 | no | no | yes |
| 0.035 | 27.7% | 27.7% | 1.3% | 27.0% | yes | yes | +0.73 | -25.70 | no | no | yes |
| 0.05 | 39.6% | 39.6% | 1.8% | 40.5% | yes | yes | -0.95 | -38.71 | no | no | yes |
| 0.07 | 55.4% | 55.4% | 2.5% | 54.9% | yes | yes | +0.55 | -52.31 | no | no | yes |
| 0.1 | 79.2% | 79.2% | 3.6% | 71.6% | yes | yes | +7.58 | -67.94 | no | no | yes |
| 0.15 | 118.7% | 118.8% | 5.5% | 96.4% | yes | no | +22.35 | -90.92 | yes | yes | yes |
| 0.2 | 158.3% | 158.3% | 7.3% | not measured | | | | | | | |
| 0.3 | 237.5% | 237.5% | 10.9% | not measured | | | | | | | |
| 0.45 | 356.2% | 356.3% | 16.4% | not measured | | | | | | | |
| 0.7 | 554.1% | 554.2% | 25.5% | not measured | | | | | | | |
| 1 | 791.6% | 791.7% | 36.4% | not measured | | | | | | | |
| 1.5 | 1187.3% | 1187.5% | 54.6% | not measured | | | | | | | |
| 2.2 | 1741.4% | 1741.7% | 80.1% | not measured | | | | | | | |
| 3.3 | 2612.1% | 2612.6% | 120.2% | not measured | | | | | | | |
| 5 | 3957.8% | 3958.5% | 182.1% | not measured | | | | | | | |

Measured limit rps: 0.117 (interpolated between two valid stages).
Predicted limit rps: point 0.1011, safe 0.101, naive 2.1964. Relative error: point -13.6%, safe -13.6%, naive +1777.7%.

## Scenario s-3

| rps | point | safe | naive | observed | valid | usable | point − obs (pt) | naive − obs (pt) | predicted over | observed over | agree |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 0.025 | 4.9% | 5.0% | 0.9% | 6.2% | yes | no | -1.25 | -5.29 | no | no | yes |
| 0.035 | 6.9% | 6.9% | 1.3% | 8.3% | yes | no | -1.39 | -7.04 | no | no | yes |
| 0.05 | 9.9% | 9.9% | 1.8% | 10.9% | yes | yes | -1.04 | -9.12 | no | no | yes |
| 0.07 | 13.9% | 13.9% | 2.5% | 13.8% | yes | yes | +0.10 | -11.21 | no | no | yes |
| 0.1 | 19.8% | 19.8% | 3.6% | 18.0% | yes | yes | +1.77 | -14.38 | no | no | yes |
| 0.15 | 29.7% | 29.7% | 5.5% | 29.3% | yes | yes | +0.37 | -23.87 | no | no | yes |
| 0.2 | 39.6% | 39.6% | 7.3% | 39.1% | yes | yes | +0.52 | -31.79 | no | no | yes |
| 0.3 | 59.4% | 59.4% | 10.9% | 56.0% | yes | yes | +3.41 | -45.05 | no | no | yes |
| 0.45 | 89.1% | 89.1% | 16.4% | 80.1% | yes | yes | +9.00 | -63.70 | yes | yes | yes |
| 0.7 | 138.6% | 138.6% | 25.5% | not measured | | | | | | | |
| 1 | 198.0% | 198.0% | 36.4% | not measured | | | | | | | |
| 1.5 | 297.0% | 297.0% | 54.6% | not measured | | | | | | | |
| 2.2 | 435.6% | 435.7% | 80.1% | not measured | | | | | | | |
| 3.3 | 653.3% | 653.5% | 120.2% | not measured | | | | | | | |
| 5 | 989.9% | 990.2% | 182.1% | not measured | | | | | | | |

Measured limit rps: 0.4494 (interpolated between two valid stages).
Predicted limit rps: point 0.4041, safe 0.404, naive 2.1964. Relative error: point -10.1%, safe -10.1%, naive +388.7%.

## Scenario s-4

| rps | point | safe | naive | observed | valid | usable | point − obs (pt) | naive − obs (pt) | predicted over | observed over | agree |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 0.025 | 14.8% | 14.8% | 0.9% | 14.8% | yes | yes | +0.08 | -13.85 | no | no | yes |
| 0.035 | 20.8% | 20.8% | 1.3% | 19.7% | yes | yes | +1.09 | -18.41 | no | no | yes |
| 0.05 | 29.7% | 29.7% | 1.8% | 29.7% | yes | yes | 0.00 | -27.87 | no | no | yes |
| 0.07 | 41.6% | 41.6% | 2.5% | 41.5% | yes | yes | +0.09 | -38.92 | no | no | yes |
| 0.1 | 59.4% | 59.4% | 3.6% | 55.9% | yes | yes | +3.52 | -52.21 | no | no | yes |
| 0.15 | 89.1% | 89.1% | 5.5% | 81.2% | yes | yes | +7.82 | -75.77 | yes | yes | yes |
| 0.2 | 118.7% | 118.8% | 7.3% | not measured | | | | | | | |
| 0.3 | 178.1% | 178.1% | 10.9% | not measured | | | | | | | |
| 0.45 | 267.2% | 267.2% | 16.4% | not measured | | | | | | | |
| 0.7 | 415.6% | 415.7% | 25.5% | not measured | | | | | | | |
| 1 | 593.7% | 593.8% | 36.4% | not measured | | | | | | | |
| 1.5 | 890.5% | 890.7% | 54.6% | not measured | | | | | | | |
| 2.2 | 1306.1% | 1306.4% | 80.1% | not measured | | | | | | | |
| 3.3 | 1959.2% | 1959.6% | 120.2% | not measured | | | | | | | |
| 5 | 2968.5% | 2969.0% | 182.1% | not measured | | | | | | | |

Measured limit rps: 0.1476 (interpolated between two valid stages).
Predicted limit rps: point 0.1347, safe 0.1347, naive 2.1964. Relative error: point -8.7%, safe -8.7%, naive +1388.5%.

## Scenario s-5

| rps | point | safe | naive | observed | valid | usable | point − obs (pt) | naive − obs (pt) | predicted over | observed over | agree |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 0.025 | 3.7% | 3.7% | 0.9% | 4.4% | yes | no | -0.70 | -3.50 | no | no | yes |
| 0.035 | 5.2% | 5.2% | 1.3% | 5.8% | yes | no | -0.63 | -4.56 | no | no | yes |
| 0.05 | 7.4% | 7.4% | 1.8% | 8.0% | yes | no | -0.57 | -6.17 | no | no | yes |
| 0.07 | 10.4% | 10.4% | 2.5% | 10.8% | yes | yes | -0.40 | -8.24 | no | no | yes |
| 0.1 | 14.9% | 14.9% | 3.6% | 13.9% | yes | yes | +0.96 | -10.25 | no | no | yes |
| 0.15 | 22.3% | 22.3% | 5.5% | 19.8% | yes | yes | +2.45 | -14.36 | no | no | yes |
| 0.2 | 29.7% | 29.7% | 7.3% | 29.7% | yes | yes | +0.02 | -22.40 | no | no | yes |
| 0.3 | 44.6% | 44.6% | 10.9% | 43.8% | yes | yes | +0.73 | -32.90 | no | no | yes |
| 0.45 | 66.8% | 66.8% | 16.4% | 60.8% | yes | yes | +6.05 | -44.39 | no | no | yes |
| 0.7 | 104.0% | 104.0% | 25.5% | 86.7% | yes | yes | +17.23 | -61.23 | yes | yes | yes |
| 1 | 148.5% | 148.5% | 36.4% | not measured | | | | | | | |
| 1.5 | 222.8% | 222.8% | 54.6% | not measured | | | | | | | |
| 2.2 | 326.7% | 326.8% | 80.1% | not measured | | | | | | | |
| 3.3 | 490.1% | 490.2% | 120.2% | not measured | | | | | | | |
| 5 | 742.6% | 742.7% | 182.1% | not measured | | | | | | | |

Measured limit rps: 0.6352 (interpolated between two valid stages).
Predicted limit rps: point 0.5387, safe 0.5386, naive 2.1964. Relative error: point -15.2%, safe -15.2%, naive +245.8%.

## Stages where the safe-side prediction fell below the observation

| scenario | rps | safe | observed | valid | usable |
| --- | --- | --- | --- | --- | --- |
| s-1 | 0.025 | 10.6% | 11.3% | yes | yes |
| s-1 | 0.035 | 14.8% | 14.9% | yes | yes |
| s-1 | 0.1 | 42.4% | 42.9% | yes | yes |
| s-2 | 0.05 | 39.6% | 40.5% | yes | yes |
| s-3 | 0.025 | 5.0% | 6.2% | yes | no |
| s-3 | 0.035 | 6.9% | 8.3% | yes | no |
| s-3 | 0.05 | 9.9% | 10.9% | yes | yes |
| s-5 | 0.025 | 3.7% | 4.4% | yes | no |
| s-5 | 0.035 | 5.2% | 5.8% | yes | no |
| s-5 | 0.05 | 7.4% | 8.0% | yes | no |
| s-5 | 0.07 | 10.4% | 10.8% | yes | yes |
