# Lab backtest: score of the ladders

Threshold: 80.0%. Usable stage: valid and observed 10.0% to 90.0%.
Scenarios 5 · stages measured 10 · valid 10 · usable 0 · scenarios whose limit rps was measured 0.

## Verdict: INDETERMINATE

- Indeterminate: usable stages 0 < 10
- Indeterminate: scenarios whose limit rps was measured 0 < 4
- An indeterminate backtest is not a pass. The items below are shown for the record only.

| Item | Value | Minimum | Target |
| --- | --- | --- | --- |
| 1. mean abs. difference smaller than the naive estimate | n/a vs naive n/a | not met | not met |
| 2. mean abs. difference (lines 15 / 5 points) | n/a | not met | not met |
| 3. "exceeded the threshold" agrees, all valid stages (80% or more) | 10/10 = 100.0% (naive 100.0%) | met | met |
| 4. predicted limit rps within ±20.0% / ±10.0% of the measured one (80% or more) | 0/0 and 0/0 | not met | not met |

Item 4 for the other two predictions, for the record (not used for the verdict): safe side 0/0 and 0/0; naive estimate 0/0 and 0/0 (within the minimum and the target tolerance).

## Scenario s-1

| rps | point | safe | naive | observed | valid | usable | point − obs (pt) | naive − obs (pt) | predicted over | observed over | agree |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 0.025 | 1.9% | 2.0% | 0.9% | 2.9% | yes | no | -1.07 | -2.03 | no | no | yes |
| 0.035 | 2.6% | 2.8% | 1.3% | 3.5% | yes | no | -0.93 | -2.27 | no | no | yes |
| 0.05 | 3.7% | 4.0% | 1.8% | not measured | | | | | | | |
| 0.07 | 5.2% | 5.5% | 2.5% | not measured | | | | | | | |
| 0.1 | 7.5% | 7.9% | 3.6% | not measured | | | | | | | |
| 0.15 | 11.2% | 11.9% | 5.5% | not measured | | | | | | | |
| 0.2 | 15.0% | 15.9% | 7.3% | not measured | | | | | | | |
| 0.3 | 22.5% | 23.8% | 10.9% | not measured | | | | | | | |
| 0.45 | 33.7% | 35.7% | 16.4% | not measured | | | | | | | |
| 0.7 | 52.4% | 55.5% | 25.5% | not measured | | | | | | | |
| 1 | 74.9% | 79.3% | 36.4% | not measured | | | | | | | |
| 1.5 | 112.4% | 118.9% | 54.6% | not measured | | | | | | | |
| 2.2 | 164.8% | 174.4% | 80.1% | not measured | | | | | | | |
| 3.3 | 247.3% | 261.6% | 120.2% | not measured | | | | | | | |
| 5 | 374.6% | 396.3% | 182.1% | not measured | | | | | | | |

Measured limit rps: not measured (app_bound_below_threshold: the ladder stopped below the threshold (the application was the bottleneck): no stage at or above the threshold was measured).
Predicted limit rps: point 1.0677, safe 1.0092, naive 2.1964.

## Scenario s-2

| rps | point | safe | naive | observed | valid | usable | point − obs (pt) | naive − obs (pt) | predicted over | observed over | agree |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 0.025 | 3.5% | 3.7% | 0.9% | 4.3% | yes | no | -0.84 | -3.42 | no | no | yes |
| 0.035 | 4.9% | 5.2% | 1.3% | 5.8% | yes | no | -0.86 | -4.48 | no | no | yes |
| 0.05 | 7.0% | 7.4% | 1.8% | not measured | | | | | | | |
| 0.07 | 9.8% | 10.4% | 2.5% | not measured | | | | | | | |
| 0.1 | 14.0% | 14.8% | 3.6% | not measured | | | | | | | |
| 0.15 | 21.0% | 22.2% | 5.5% | not measured | | | | | | | |
| 0.2 | 28.0% | 29.6% | 7.3% | not measured | | | | | | | |
| 0.3 | 41.9% | 44.4% | 10.9% | not measured | | | | | | | |
| 0.45 | 62.9% | 66.5% | 16.4% | not measured | | | | | | | |
| 0.7 | 97.8% | 103.5% | 25.5% | not measured | | | | | | | |
| 1 | 139.8% | 147.9% | 36.4% | not measured | | | | | | | |
| 1.5 | 209.6% | 221.8% | 54.6% | not measured | | | | | | | |
| 2.2 | 307.5% | 325.3% | 80.1% | not measured | | | | | | | |
| 3.3 | 461.2% | 488.0% | 120.2% | not measured | | | | | | | |
| 5 | 698.8% | 739.3% | 182.1% | not measured | | | | | | | |

Measured limit rps: not measured (app_bound_below_threshold: the ladder stopped below the threshold (the application was the bottleneck): no stage at or above the threshold was measured).
Predicted limit rps: point 0.5724, safe 0.541, naive 2.1964.

## Scenario s-3

| rps | point | safe | naive | observed | valid | usable | point − obs (pt) | naive − obs (pt) | predicted over | observed over | agree |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 0.025 | 0.9% | 0.9% | 0.9% | 1.8% | yes | no | -0.96 | -0.93 | no | no | yes |
| 0.035 | 1.2% | 1.3% | 1.3% | 2.2% | yes | no | -0.98 | -0.93 | no | no | yes |
| 0.05 | 1.8% | 1.9% | 1.8% | not measured | | | | | | | |
| 0.07 | 2.5% | 2.6% | 2.5% | not measured | | | | | | | |
| 0.1 | 3.5% | 3.7% | 3.6% | not measured | | | | | | | |
| 0.15 | 5.3% | 5.6% | 5.5% | not measured | | | | | | | |
| 0.2 | 7.0% | 7.4% | 7.3% | not measured | | | | | | | |
| 0.3 | 10.5% | 11.1% | 10.9% | not measured | | | | | | | |
| 0.45 | 15.8% | 16.7% | 16.4% | not measured | | | | | | | |
| 0.7 | 24.5% | 25.9% | 25.5% | not measured | | | | | | | |
| 1 | 35.0% | 37.1% | 36.4% | not measured | | | | | | | |
| 1.5 | 52.5% | 55.6% | 54.6% | not measured | | | | | | | |
| 2.2 | 77.1% | 81.5% | 80.1% | not measured | | | | | | | |
| 3.3 | 115.6% | 122.3% | 120.2% | not measured | | | | | | | |
| 5 | 175.1% | 185.3% | 182.1% | not measured | | | | | | | |

Measured limit rps: not measured (app_bound_below_threshold: the ladder stopped below the threshold (the application was the bottleneck): no stage at or above the threshold was measured).
Predicted limit rps: point 2.2839, safe 2.159, naive 2.1964.

## Scenario s-4

| rps | point | safe | naive | observed | valid | usable | point − obs (pt) | naive − obs (pt) | predicted over | observed over | agree |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 0.025 | 2.6% | 2.8% | 0.9% | 3.4% | yes | no | -0.78 | -2.49 | no | no | yes |
| 0.035 | 3.7% | 3.9% | 1.3% | 4.4% | yes | no | -0.73 | -3.12 | no | no | yes |
| 0.05 | 5.2% | 5.5% | 1.8% | not measured | | | | | | | |
| 0.07 | 7.3% | 7.8% | 2.5% | not measured | | | | | | | |
| 0.1 | 10.5% | 11.1% | 3.6% | not measured | | | | | | | |
| 0.15 | 15.7% | 16.6% | 5.5% | not measured | | | | | | | |
| 0.2 | 21.0% | 22.2% | 7.3% | not measured | | | | | | | |
| 0.3 | 31.5% | 33.3% | 10.9% | not measured | | | | | | | |
| 0.45 | 47.2% | 49.9% | 16.4% | not measured | | | | | | | |
| 0.7 | 73.4% | 77.6% | 25.5% | not measured | | | | | | | |
| 1 | 104.8% | 110.9% | 36.4% | not measured | | | | | | | |
| 1.5 | 157.3% | 166.4% | 54.6% | not measured | | | | | | | |
| 2.2 | 230.7% | 244.0% | 80.1% | not measured | | | | | | | |
| 3.3 | 346.0% | 366.1% | 120.2% | not measured | | | | | | | |
| 5 | 524.2% | 554.6% | 182.1% | not measured | | | | | | | |

Measured limit rps: not measured (app_bound_below_threshold: the ladder stopped below the threshold (the application was the bottleneck): no stage at or above the threshold was measured).
Predicted limit rps: point 0.763, safe 0.7212, naive 2.1964.

## Scenario s-5

| rps | point | safe | naive | observed | valid | usable | point − obs (pt) | naive − obs (pt) | predicted over | observed over | agree |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 0.025 | 0.7% | 0.7% | 0.9% | 1.5% | yes | no | -0.87 | -0.62 | no | no | yes |
| 0.035 | 0.9% | 1.0% | 1.3% | 1.8% | yes | no | -0.85 | -0.50 | no | no | yes |
| 0.05 | 1.3% | 1.4% | 1.8% | not measured | | | | | | | |
| 0.07 | 1.8% | 1.9% | 2.5% | not measured | | | | | | | |
| 0.1 | 2.6% | 2.8% | 3.6% | not measured | | | | | | | |
| 0.15 | 3.9% | 4.2% | 5.5% | not measured | | | | | | | |
| 0.2 | 5.3% | 5.6% | 7.3% | not measured | | | | | | | |
| 0.3 | 7.9% | 8.3% | 10.9% | not measured | | | | | | | |
| 0.45 | 11.8% | 12.5% | 16.4% | not measured | | | | | | | |
| 0.7 | 18.4% | 19.5% | 25.5% | not measured | | | | | | | |
| 1 | 26.3% | 27.8% | 36.4% | not measured | | | | | | | |
| 1.5 | 39.5% | 41.7% | 54.6% | not measured | | | | | | | |
| 2.2 | 57.9% | 61.2% | 80.1% | not measured | | | | | | | |
| 3.3 | 86.8% | 91.8% | 120.2% | not measured | | | | | | | |
| 5 | 131.5% | 139.1% | 182.1% | not measured | | | | | | | |

Measured limit rps: not measured (app_bound_below_threshold: the ladder stopped below the threshold (the application was the bottleneck): no stage at or above the threshold was measured).
Predicted limit rps: point 3.0417, safe 2.8751, naive 2.1964.

## Stages where the safe-side prediction fell below the observation

| scenario | rps | safe | observed | valid | usable |
| --- | --- | --- | --- | --- | --- |
| s-1 | 0.025 | 2.0% | 2.9% | yes | no |
| s-1 | 0.035 | 2.8% | 3.5% | yes | no |
| s-2 | 0.025 | 3.7% | 4.3% | yes | no |
| s-2 | 0.035 | 5.2% | 5.8% | yes | no |
| s-3 | 0.025 | 0.9% | 1.8% | yes | no |
| s-3 | 0.035 | 1.3% | 2.2% | yes | no |
| s-4 | 0.025 | 2.8% | 3.4% | yes | no |
| s-4 | 0.035 | 3.9% | 4.4% | yes | no |
| s-5 | 0.025 | 0.7% | 1.5% | yes | no |
| s-5 | 0.035 | 1.0% | 1.8% | yes | no |
