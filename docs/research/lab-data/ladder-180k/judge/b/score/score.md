# Lab backtest: score of the ladders

Threshold: 80.0%. Usable stage: valid and observed 10.0% to 90.0%.
Scenarios 5 · stages measured 37 · valid 37 · usable 29 · scenarios whose limit rps was measured 5.

## Verdict: PASS at the target

| Item | Value | Minimum | Target |
| --- | --- | --- | --- |
| 1. mean abs. difference smaller than the naive estimate | 0.87 pt vs naive 33.35 pt | met | met |
| 2. mean abs. difference (lines 15 / 5 points) | 0.87 pt | met | met |
| 3. "exceeded the threshold" agrees, all valid stages (80% or more) | 37/37 = 100.0% (naive 86.5%) | met | met |
| 4. predicted limit rps within ±20.0% / ±10.0% of the measured one (80% or more) | 5/5 and 5/5 | met | met |

Item 4 for the other two predictions, for the record (not used for the verdict): safe side 5/5 and 5/5; naive estimate 0/5 and 0/5 (within the minimum and the target tolerance).

## Scenario s-1

| rps | point | safe | naive | observed | valid | usable | point − obs (pt) | naive − obs (pt) | predicted over | observed over | agree |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 0.025 | 11.2% | 11.2% | 0.9% | 12.9% | yes | yes | -1.66 | -11.98 | no | no | yes |
| 0.035 | 15.7% | 15.7% | 1.3% | 15.8% | yes | yes | -0.05 | -14.49 | no | no | yes |
| 0.05 | 22.5% | 22.5% | 1.8% | 22.5% | yes | yes | -0.06 | -20.69 | no | no | yes |
| 0.07 | 31.4% | 31.4% | 2.5% | 32.4% | yes | yes | -0.97 | -29.85 | no | no | yes |
| 0.1 | 44.9% | 44.9% | 3.6% | 46.3% | yes | yes | -1.41 | -42.67 | no | no | yes |
| 0.15 | 67.4% | 67.4% | 5.5% | 67.4% | yes | yes | -0.05 | -61.94 | no | no | yes |
| 0.2 | 89.8% | 89.8% | 7.3% | 86.1% | yes | yes | +3.69 | -78.83 | yes | yes | yes |
| 0.3 | 134.7% | 134.7% | 10.9% | not measured | | | | | | | |
| 0.45 | 202.1% | 202.1% | 16.4% | not measured | | | | | | | |
| 0.7 | 314.3% | 314.4% | 25.5% | not measured | | | | | | | |
| 1 | 449.0% | 449.1% | 36.4% | not measured | | | | | | | |
| 1.5 | 673.6% | 673.7% | 54.6% | not measured | | | | | | | |
| 2.2 | 987.9% | 988.1% | 80.1% | not measured | | | | | | | |
| 3.3 | 1481.9% | 1482.2% | 120.2% | not measured | | | | | | | |
| 5 | 2245.2% | 2245.7% | 182.1% | not measured | | | | | | | |

Measured limit rps: 0.1837 (interpolated between two valid stages).
Predicted limit rps: point 0.1782, safe 0.1781, naive 2.1964. Relative error: point -3.0%, safe -3.0%, naive +1095.9%.

## Scenario s-2

| rps | point | safe | naive | observed | valid | usable | point − obs (pt) | naive − obs (pt) | predicted over | observed over | agree |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 0.025 | 21.0% | 21.0% | 0.9% | 21.0% | yes | yes | -0.07 | -20.11 | no | no | yes |
| 0.035 | 29.3% | 29.3% | 1.3% | 29.8% | yes | yes | -0.47 | -28.53 | no | no | yes |
| 0.05 | 41.9% | 41.9% | 1.8% | 43.1% | yes | yes | -1.15 | -41.24 | no | no | yes |
| 0.07 | 58.7% | 58.7% | 2.5% | 59.3% | yes | yes | -0.59 | -56.71 | no | no | yes |
| 0.1 | 83.8% | 83.8% | 3.6% | 81.9% | yes | yes | +1.87 | -78.30 | yes | yes | yes |
| 0.15 | 125.7% | 125.7% | 5.5% | not measured | | | | | | | |
| 0.2 | 167.6% | 167.7% | 7.3% | not measured | | | | | | | |
| 0.3 | 251.4% | 251.5% | 10.9% | not measured | | | | | | | |
| 0.45 | 377.2% | 377.2% | 16.4% | not measured | | | | | | | |
| 0.7 | 586.7% | 586.8% | 25.5% | not measured | | | | | | | |
| 1 | 838.1% | 838.3% | 36.4% | not measured | | | | | | | |
| 1.5 | 1257.2% | 1257.4% | 54.6% | not measured | | | | | | | |
| 2.2 | 1843.9% | 1844.2% | 80.1% | not measured | | | | | | | |
| 3.3 | 2765.8% | 2766.3% | 120.2% | not measured | | | | | | | |
| 5 | 4190.6% | 4191.4% | 182.1% | not measured | | | | | | | |

Measured limit rps: 0.0974 (interpolated between two valid stages).
Predicted limit rps: point 0.0955, safe 0.0954, naive 2.1964. Relative error: point -2.0%, safe -2.1%, naive +2154.2%.

## Scenario s-3

| rps | point | safe | naive | observed | valid | usable | point − obs (pt) | naive − obs (pt) | predicted over | observed over | agree |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 0.025 | 5.2% | 5.2% | 0.9% | 6.6% | yes | no | -1.34 | -5.67 | no | no | yes |
| 0.035 | 7.3% | 7.3% | 1.3% | 8.8% | yes | no | -1.50 | -7.56 | no | no | yes |
| 0.05 | 10.5% | 10.5% | 1.8% | 11.4% | yes | yes | -0.94 | -9.60 | no | no | yes |
| 0.07 | 14.7% | 14.7% | 2.5% | 14.5% | yes | yes | +0.18 | -11.94 | no | no | yes |
| 0.1 | 21.0% | 21.0% | 3.6% | 20.7% | yes | yes | +0.23 | -17.09 | no | no | yes |
| 0.15 | 31.4% | 31.5% | 5.5% | 31.6% | yes | yes | -0.15 | -26.13 | no | no | yes |
| 0.2 | 41.9% | 41.9% | 7.3% | 43.5% | yes | yes | -1.61 | -36.25 | no | no | yes |
| 0.3 | 62.9% | 62.9% | 10.9% | 63.1% | yes | yes | -0.19 | -52.15 | no | no | yes |
| 0.45 | 94.3% | 94.4% | 16.4% | 90.0% | yes | no | +4.32 | -73.62 | yes | yes | yes |
| 0.7 | 146.7% | 146.8% | 25.5% | not measured | | | | | | | |
| 1 | 209.6% | 209.7% | 36.4% | not measured | | | | | | | |
| 1.5 | 314.4% | 314.5% | 54.6% | not measured | | | | | | | |
| 2.2 | 461.2% | 461.3% | 80.1% | not measured | | | | | | | |
| 3.3 | 691.7% | 691.9% | 120.2% | not measured | | | | | | | |
| 5 | 1048.1% | 1048.4% | 182.1% | not measured | | | | | | | |

Measured limit rps: 0.3943 (interpolated between two valid stages).
Predicted limit rps: point 0.3816, safe 0.3815, naive 2.1964. Relative error: point -3.2%, safe -3.2%, naive +457.1%.

## Scenario s-4

| rps | point | safe | naive | observed | valid | usable | point − obs (pt) | naive − obs (pt) | predicted over | observed over | agree |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 0.025 | 15.7% | 15.7% | 0.9% | 16.1% | yes | yes | -0.38 | -15.19 | no | no | yes |
| 0.035 | 22.0% | 22.0% | 1.3% | 22.2% | yes | yes | -0.16 | -20.88 | no | no | yes |
| 0.05 | 31.4% | 31.4% | 1.8% | 32.0% | yes | yes | -0.59 | -30.20 | no | no | yes |
| 0.07 | 44.0% | 44.0% | 2.5% | 45.5% | yes | yes | -1.51 | -42.96 | no | no | yes |
| 0.1 | 62.9% | 62.9% | 3.6% | 65.2% | yes | yes | -2.38 | -61.60 | no | no | yes |
| 0.15 | 94.3% | 94.3% | 5.5% | 90.6% | yes | no | +3.70 | -85.13 | yes | yes | yes |
| 0.2 | 125.7% | 125.7% | 7.3% | not measured | | | | | | | |
| 0.3 | 188.6% | 188.6% | 10.9% | not measured | | | | | | | |
| 0.45 | 282.9% | 282.9% | 16.4% | not measured | | | | | | | |
| 0.7 | 440.0% | 440.1% | 25.5% | not measured | | | | | | | |
| 1 | 628.6% | 628.7% | 36.4% | not measured | | | | | | | |
| 1.5 | 942.9% | 943.1% | 54.6% | not measured | | | | | | | |
| 2.2 | 1383.0% | 1383.2% | 80.1% | not measured | | | | | | | |
| 3.3 | 2074.4% | 2074.8% | 120.2% | not measured | | | | | | | |
| 5 | 3143.1% | 3143.7% | 182.1% | not measured | | | | | | | |

Measured limit rps: 0.1291 (interpolated between two valid stages).
Predicted limit rps: point 0.1273, safe 0.1272, naive 2.1964. Relative error: point -1.4%, safe -1.5%, naive +1601.2%.

## Scenario s-5

| rps | point | safe | naive | observed | valid | usable | point − obs (pt) | naive − obs (pt) | predicted over | observed over | agree |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 0.025 | 3.9% | 3.9% | 0.9% | 4.7% | yes | no | -0.81 | -3.83 | no | no | yes |
| 0.035 | 5.5% | 5.5% | 1.3% | 6.3% | yes | no | -0.79 | -5.02 | no | no | yes |
| 0.05 | 7.9% | 7.9% | 1.8% | 8.6% | yes | no | -0.75 | -6.79 | no | no | yes |
| 0.07 | 11.0% | 11.0% | 2.5% | 11.7% | yes | yes | -0.72 | -9.18 | no | no | yes |
| 0.1 | 15.7% | 15.7% | 3.6% | 16.4% | yes | yes | -0.63 | -12.71 | no | no | yes |
| 0.15 | 23.6% | 23.6% | 5.5% | 24.1% | yes | yes | -0.49 | -18.62 | no | no | yes |
| 0.2 | 31.4% | 31.5% | 7.3% | 32.4% | yes | yes | -0.95 | -25.12 | no | no | yes |
| 0.3 | 47.2% | 47.2% | 10.9% | 48.9% | yes | yes | -1.77 | -38.02 | no | no | yes |
| 0.45 | 70.8% | 70.8% | 16.4% | 70.6% | yes | yes | +0.17 | -54.20 | no | no | yes |
| 0.7 | 110.1% | 110.1% | 25.5% | 99.3% | yes | no | +10.77 | -73.80 | yes | yes | yes |
| 1 | 157.2% | 157.3% | 36.4% | not measured | | | | | | | |
| 1.5 | 235.9% | 235.9% | 54.6% | not measured | | | | | | | |
| 2.2 | 345.9% | 346.0% | 80.1% | not measured | | | | | | | |
| 3.3 | 518.9% | 519.0% | 120.2% | not measured | | | | | | | |
| 5 | 786.2% | 786.4% | 182.1% | not measured | | | | | | | |

Measured limit rps: 0.5319 (interpolated between two valid stages).
Predicted limit rps: point 0.5088, safe 0.5087, naive 2.1964. Relative error: point -4.4%, safe -4.4%, naive +312.9%.

## Stages where the safe-side prediction fell below the observation

| scenario | rps | safe | observed | valid | usable |
| --- | --- | --- | --- | --- | --- |
| s-1 | 0.025 | 11.2% | 12.9% | yes | yes |
| s-1 | 0.035 | 15.7% | 15.8% | yes | yes |
| s-1 | 0.05 | 22.5% | 22.5% | yes | yes |
| s-1 | 0.07 | 31.4% | 32.4% | yes | yes |
| s-1 | 0.1 | 44.9% | 46.3% | yes | yes |
| s-1 | 0.15 | 67.4% | 67.4% | yes | yes |
| s-2 | 0.025 | 21.0% | 21.0% | yes | yes |
| s-2 | 0.035 | 29.3% | 29.8% | yes | yes |
| s-2 | 0.05 | 41.9% | 43.1% | yes | yes |
| s-2 | 0.07 | 58.7% | 59.3% | yes | yes |
| s-3 | 0.025 | 5.2% | 6.6% | yes | no |
| s-3 | 0.035 | 7.3% | 8.8% | yes | no |
| s-3 | 0.05 | 10.5% | 11.4% | yes | yes |
| s-3 | 0.15 | 31.5% | 31.6% | yes | yes |
| s-3 | 0.2 | 41.9% | 43.5% | yes | yes |
| s-3 | 0.3 | 62.9% | 63.1% | yes | yes |
| s-4 | 0.025 | 15.7% | 16.1% | yes | yes |
| s-4 | 0.035 | 22.0% | 22.2% | yes | yes |
| s-4 | 0.05 | 31.4% | 32.0% | yes | yes |
| s-4 | 0.07 | 44.0% | 45.5% | yes | yes |
| s-4 | 0.1 | 62.9% | 65.2% | yes | yes |
| s-5 | 0.025 | 3.9% | 4.7% | yes | no |
| s-5 | 0.035 | 5.5% | 6.3% | yes | no |
| s-5 | 0.05 | 7.9% | 8.6% | yes | no |
| s-5 | 0.07 | 11.0% | 11.7% | yes | yes |
| s-5 | 0.1 | 15.7% | 16.4% | yes | yes |
| s-5 | 0.15 | 23.6% | 24.1% | yes | yes |
| s-5 | 0.2 | 31.5% | 32.4% | yes | yes |
| s-5 | 0.3 | 47.2% | 48.9% | yes | yes |
