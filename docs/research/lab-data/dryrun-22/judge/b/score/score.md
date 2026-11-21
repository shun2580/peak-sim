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
| 0.025 | 1.7% | 2.1% | 0.9% | 2.8% | yes | no | -1.07 | -1.86 | no | no | yes |
| 0.035 | 2.4% | 2.9% | 1.3% | 3.5% | yes | no | -1.09 | -2.19 | no | no | yes |
| 0.05 | 3.4% | 4.2% | 1.8% | not measured | | | | | | | |
| 0.07 | 4.8% | 5.9% | 2.5% | not measured | | | | | | | |
| 0.1 | 6.8% | 8.4% | 3.6% | not measured | | | | | | | |
| 0.15 | 10.2% | 12.6% | 5.5% | not measured | | | | | | | |
| 0.2 | 13.6% | 16.8% | 7.3% | not measured | | | | | | | |
| 0.3 | 20.4% | 25.2% | 10.9% | not measured | | | | | | | |
| 0.45 | 30.5% | 37.7% | 16.4% | not measured | | | | | | | |
| 0.7 | 47.5% | 58.7% | 25.5% | not measured | | | | | | | |
| 1 | 67.9% | 83.9% | 36.4% | not measured | | | | | | | |
| 1.5 | 101.8% | 125.8% | 54.6% | not measured | | | | | | | |
| 2.2 | 149.3% | 184.5% | 80.1% | not measured | | | | | | | |
| 3.3 | 223.9% | 276.7% | 120.2% | not measured | | | | | | | |
| 5 | 339.3% | 419.3% | 182.1% | not measured | | | | | | | |

Measured limit rps: not measured (app_bound_below_threshold: the ladder stopped below the threshold (the application was the bottleneck): no stage at or above the threshold was measured).
Predicted limit rps: point 1.1788, safe 0.954, naive 2.1964.

## Scenario s-2

| rps | point | safe | naive | observed | valid | usable | point − obs (pt) | naive − obs (pt) | predicted over | observed over | agree |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 0.025 | 2.7% | 3.4% | 0.9% | 3.8% | yes | no | -1.13 | -2.93 | no | no | yes |
| 0.035 | 3.8% | 4.7% | 1.3% | 5.0% | yes | no | -1.16 | -3.68 | no | no | yes |
| 0.05 | 5.4% | 6.7% | 1.8% | not measured | | | | | | | |
| 0.07 | 7.6% | 9.4% | 2.5% | not measured | | | | | | | |
| 0.1 | 10.9% | 13.4% | 3.6% | not measured | | | | | | | |
| 0.15 | 16.3% | 20.1% | 5.5% | not measured | | | | | | | |
| 0.2 | 21.7% | 26.8% | 7.3% | not measured | | | | | | | |
| 0.3 | 32.6% | 40.2% | 10.9% | not measured | | | | | | | |
| 0.45 | 48.8% | 60.3% | 16.4% | not measured | | | | | | | |
| 0.7 | 76.0% | 93.9% | 25.5% | not measured | | | | | | | |
| 1 | 108.5% | 134.1% | 36.4% | not measured | | | | | | | |
| 1.5 | 162.8% | 201.2% | 54.6% | not measured | | | | | | | |
| 2.2 | 238.7% | 295.0% | 80.1% | not measured | | | | | | | |
| 3.3 | 358.1% | 442.5% | 120.2% | not measured | | | | | | | |
| 5 | 542.6% | 670.5% | 182.1% | not measured | | | | | | | |

Measured limit rps: not measured (app_bound_below_threshold: the ladder stopped below the threshold (the application was the bottleneck): no stage at or above the threshold was measured).
Predicted limit rps: point 0.7373, safe 0.5966, naive 2.1964.

## Scenario s-3

| rps | point | safe | naive | observed | valid | usable | point − obs (pt) | naive − obs (pt) | predicted over | observed over | agree |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 0.025 | 0.7% | 0.8% | 0.9% | 1.6% | yes | no | -0.90 | -0.67 | no | no | yes |
| 0.035 | 1.0% | 1.2% | 1.3% | 1.8% | yes | no | -0.87 | -0.55 | no | no | yes |
| 0.05 | 1.4% | 1.7% | 1.8% | not measured | | | | | | | |
| 0.07 | 1.9% | 2.4% | 2.5% | not measured | | | | | | | |
| 0.1 | 2.7% | 3.4% | 3.6% | not measured | | | | | | | |
| 0.15 | 4.1% | 5.0% | 5.5% | not measured | | | | | | | |
| 0.2 | 5.4% | 6.7% | 7.3% | not measured | | | | | | | |
| 0.3 | 8.2% | 10.1% | 10.9% | not measured | | | | | | | |
| 0.45 | 12.2% | 15.1% | 16.4% | not measured | | | | | | | |
| 0.7 | 19.1% | 23.5% | 25.5% | not measured | | | | | | | |
| 1 | 27.2% | 33.6% | 36.4% | not measured | | | | | | | |
| 1.5 | 40.8% | 50.4% | 54.6% | not measured | | | | | | | |
| 2.2 | 59.9% | 74.0% | 80.1% | not measured | | | | | | | |
| 3.3 | 89.8% | 110.9% | 120.2% | not measured | | | | | | | |
| 5 | 136.1% | 168.1% | 182.1% | not measured | | | | | | | |

Measured limit rps: not measured (app_bound_below_threshold: the ladder stopped below the threshold (the application was the bottleneck): no stage at or above the threshold was measured).
Predicted limit rps: point 2.9394, safe 2.38, naive 2.1964.

## Scenario s-4

| rps | point | safe | naive | observed | valid | usable | point − obs (pt) | naive − obs (pt) | predicted over | observed over | agree |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 0.025 | 2.6% | 2.9% | 0.9% | 3.5% | yes | no | -0.85 | -2.58 | no | no | yes |
| 0.035 | 3.7% | 4.1% | 1.3% | 4.5% | yes | no | -0.81 | -3.23 | no | no | yes |
| 0.05 | 5.3% | 5.8% | 1.8% | not measured | | | | | | | |
| 0.07 | 7.4% | 8.2% | 2.5% | not measured | | | | | | | |
| 0.1 | 10.6% | 11.7% | 3.6% | not measured | | | | | | | |
| 0.15 | 15.9% | 17.5% | 5.5% | not measured | | | | | | | |
| 0.2 | 21.2% | 23.3% | 7.3% | not measured | | | | | | | |
| 0.3 | 31.7% | 35.0% | 10.9% | not measured | | | | | | | |
| 0.45 | 47.6% | 52.5% | 16.4% | not measured | | | | | | | |
| 0.7 | 74.0% | 81.7% | 25.5% | not measured | | | | | | | |
| 1 | 105.8% | 116.7% | 36.4% | not measured | | | | | | | |
| 1.5 | 158.6% | 175.1% | 54.6% | not measured | | | | | | | |
| 2.2 | 232.7% | 256.8% | 80.1% | not measured | | | | | | | |
| 3.3 | 349.0% | 385.2% | 120.2% | not measured | | | | | | | |
| 5 | 528.8% | 583.6% | 182.1% | not measured | | | | | | | |

Measured limit rps: not measured (app_bound_below_threshold: the ladder stopped below the threshold (the application was the bottleneck): no stage at or above the threshold was measured).
Predicted limit rps: point 0.7564, safe 0.6854, naive 2.1964.

## Scenario s-5

| rps | point | safe | naive | observed | valid | usable | point − obs (pt) | naive − obs (pt) | predicted over | observed over | agree |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 0.025 | 0.7% | 1.3% | 0.9% | 1.6% | yes | no | -0.87 | -0.71 | no | no | yes |
| 0.035 | 1.0% | 1.8% | 1.3% | 1.9% | yes | no | -0.87 | -0.64 | no | no | yes |
| 0.05 | 1.5% | 2.5% | 1.8% | not measured | | | | | | | |
| 0.07 | 2.1% | 3.6% | 2.5% | not measured | | | | | | | |
| 0.1 | 3.0% | 5.1% | 3.6% | not measured | | | | | | | |
| 0.15 | 4.5% | 7.6% | 5.5% | not measured | | | | | | | |
| 0.2 | 6.0% | 10.2% | 7.3% | not measured | | | | | | | |
| 0.3 | 9.0% | 15.3% | 10.9% | not measured | | | | | | | |
| 0.45 | 13.5% | 22.9% | 16.4% | not measured | | | | | | | |
| 0.7 | 21.0% | 35.7% | 25.5% | not measured | | | | | | | |
| 1 | 30.0% | 51.0% | 36.4% | not measured | | | | | | | |
| 1.5 | 44.9% | 76.5% | 54.6% | not measured | | | | | | | |
| 2.2 | 65.9% | 112.2% | 80.1% | not measured | | | | | | | |
| 3.3 | 98.9% | 168.3% | 120.2% | not measured | | | | | | | |
| 5 | 149.8% | 255.0% | 182.1% | not measured | | | | | | | |

Measured limit rps: not measured (app_bound_below_threshold: the ladder stopped below the threshold (the application was the bottleneck): no stage at or above the threshold was measured).
Predicted limit rps: point 2.6699, safe 1.5687, naive 2.1964.

## Stages where the safe-side prediction fell below the observation

| scenario | rps | safe | observed | valid | usable |
| --- | --- | --- | --- | --- | --- |
| s-1 | 0.025 | 2.1% | 2.8% | yes | no |
| s-1 | 0.035 | 2.9% | 3.5% | yes | no |
| s-2 | 0.025 | 3.4% | 3.8% | yes | no |
| s-2 | 0.035 | 4.7% | 5.0% | yes | no |
| s-3 | 0.025 | 0.8% | 1.6% | yes | no |
| s-3 | 0.035 | 1.2% | 1.8% | yes | no |
| s-4 | 0.025 | 2.9% | 3.5% | yes | no |
| s-4 | 0.035 | 4.1% | 4.5% | yes | no |
| s-5 | 0.025 | 1.3% | 1.6% | yes | no |
| s-5 | 0.035 | 1.8% | 1.9% | yes | no |
