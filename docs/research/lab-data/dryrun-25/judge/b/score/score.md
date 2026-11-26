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
| 0.025 | 0.6% | 4.3% | 0.3% | 1.4% | yes | no | -0.80 | -1.04 | no | no | yes |
| 0.035 | 0.8% | 6.1% | 0.5% | 1.6% | yes | no | -0.81 | -1.14 | no | no | yes |
| 0.05 | 1.1% | 8.7% | 0.7% | not measured | | | | | | | |
| 0.07 | 1.6% | 12.1% | 0.9% | not measured | | | | | | | |
| 0.1 | 2.3% | 17.3% | 1.3% | not measured | | | | | | | |
| 0.15 | 3.4% | 26.0% | 2.0% | not measured | | | | | | | |
| 0.2 | 4.5% | 34.7% | 2.6% | not measured | | | | | | | |
| 0.3 | 6.8% | 52.0% | 3.9% | not measured | | | | | | | |
| 0.45 | 10.1% | 78.0% | 5.9% | not measured | | | | | | | |
| 0.7 | 15.8% | 121.3% | 9.1% | not measured | | | | | | | |
| 1 | 22.5% | 173.3% | 13.1% | not measured | | | | | | | |
| 1.5 | 33.8% | 259.9% | 19.6% | not measured | | | | | | | |
| 2.2 | 49.6% | 381.2% | 28.7% | not measured | | | | | | | |
| 3.3 | 74.4% | 571.8% | 43.1% | not measured | | | | | | | |
| 5 | 112.7% | 866.3% | 65.3% | not measured | | | | | | | |

Measured limit rps: not measured (app_bound_below_threshold: the ladder stopped below the threshold (the application was the bottleneck): no stage at or above the threshold was measured).
Predicted limit rps: point 3.5489, safe 0.4617, naive 6.1221.

## Scenario s-2

| rps | point | safe | naive | observed | valid | usable | point − obs (pt) | naive − obs (pt) | predicted over | observed over | agree |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 0.025 | 0.8% | 6.8% | 0.3% | 1.6% | yes | no | -0.82 | -1.28 | no | no | yes |
| 0.035 | 1.1% | 9.5% | 0.5% | 1.9% | yes | no | -0.76 | -1.40 | no | no | yes |
| 0.05 | 1.6% | 13.6% | 0.7% | not measured | | | | | | | |
| 0.07 | 2.2% | 19.1% | 0.9% | not measured | | | | | | | |
| 0.1 | 3.1% | 27.3% | 1.3% | not measured | | | | | | | |
| 0.15 | 4.7% | 40.9% | 2.0% | not measured | | | | | | | |
| 0.2 | 6.3% | 54.5% | 2.6% | not measured | | | | | | | |
| 0.3 | 9.4% | 81.8% | 3.9% | not measured | | | | | | | |
| 0.45 | 14.1% | 122.7% | 5.9% | not measured | | | | | | | |
| 0.7 | 22.0% | 190.8% | 9.1% | not measured | | | | | | | |
| 1 | 31.4% | 272.6% | 13.1% | not measured | | | | | | | |
| 1.5 | 47.1% | 408.9% | 19.6% | not measured | | | | | | | |
| 2.2 | 69.1% | 599.7% | 28.7% | not measured | | | | | | | |
| 3.3 | 103.7% | 899.5% | 43.1% | not measured | | | | | | | |
| 5 | 157.1% | 1362.9% | 65.3% | not measured | | | | | | | |

Measured limit rps: not measured (app_bound_below_threshold: the ladder stopped below the threshold (the application was the bottleneck): no stage at or above the threshold was measured).
Predicted limit rps: point 2.5455, safe 0.2935, naive 6.1221.

## Scenario s-3

| rps | point | safe | naive | observed | valid | usable | point − obs (pt) | naive − obs (pt) | predicted over | observed over | agree |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 0.025 | 0.3% | 1.8% | 0.3% | 1.2% | yes | no | -0.81 | -0.83 | no | no | yes |
| 0.035 | 0.5% | 2.6% | 0.5% | 1.3% | yes | no | -0.80 | -0.82 | no | no | yes |
| 0.05 | 0.7% | 3.7% | 0.7% | not measured | | | | | | | |
| 0.07 | 1.0% | 5.2% | 0.9% | not measured | | | | | | | |
| 0.1 | 1.4% | 7.4% | 1.3% | not measured | | | | | | | |
| 0.15 | 2.0% | 11.1% | 2.0% | not measured | | | | | | | |
| 0.2 | 2.7% | 14.8% | 2.6% | not measured | | | | | | | |
| 0.3 | 4.1% | 22.2% | 3.9% | not measured | | | | | | | |
| 0.45 | 6.1% | 33.3% | 5.9% | not measured | | | | | | | |
| 0.7 | 9.6% | 51.8% | 9.1% | not measured | | | | | | | |
| 1 | 13.7% | 73.9% | 13.1% | not measured | | | | | | | |
| 1.5 | 20.5% | 110.9% | 19.6% | not measured | | | | | | | |
| 2.2 | 30.0% | 162.7% | 28.7% | not measured | | | | | | | |
| 3.3 | 45.1% | 244.0% | 43.1% | not measured | | | | | | | |
| 5 | 68.3% | 369.7% | 65.3% | not measured | | | | | | | |

Measured limit rps: not measured (app_bound_below_threshold: the ladder stopped below the threshold (the application was the bottleneck): no stage at or above the threshold was measured).
Predicted limit rps: point 5.8583, safe 1.0819, naive 6.1221.

## Scenario s-4

| rps | point | safe | naive | observed | valid | usable | point − obs (pt) | naive − obs (pt) | predicted over | observed over | agree |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 0.025 | 0.8% | 6.8% | 0.3% | 1.6% | yes | no | -0.81 | -1.26 | no | no | yes |
| 0.035 | 1.1% | 9.5% | 0.5% | 1.9% | yes | no | -0.77 | -1.42 | no | no | yes |
| 0.05 | 1.6% | 13.6% | 0.7% | not measured | | | | | | | |
| 0.07 | 2.2% | 19.1% | 0.9% | not measured | | | | | | | |
| 0.1 | 3.1% | 27.3% | 1.3% | not measured | | | | | | | |
| 0.15 | 4.7% | 40.9% | 2.0% | not measured | | | | | | | |
| 0.2 | 6.3% | 54.5% | 2.6% | not measured | | | | | | | |
| 0.3 | 9.4% | 81.8% | 3.9% | not measured | | | | | | | |
| 0.45 | 14.1% | 122.7% | 5.9% | not measured | | | | | | | |
| 0.7 | 22.0% | 190.8% | 9.1% | not measured | | | | | | | |
| 1 | 31.4% | 272.6% | 13.1% | not measured | | | | | | | |
| 1.5 | 47.1% | 408.9% | 19.6% | not measured | | | | | | | |
| 2.2 | 69.1% | 599.7% | 28.7% | not measured | | | | | | | |
| 3.3 | 103.7% | 899.5% | 43.1% | not measured | | | | | | | |
| 5 | 157.1% | 1362.9% | 65.3% | not measured | | | | | | | |

Measured limit rps: not measured (app_bound_below_threshold: the ladder stopped below the threshold (the application was the bottleneck): no stage at or above the threshold was measured).
Predicted limit rps: point 2.5464, safe 0.2935, naive 6.1221.

## Scenario s-5

| rps | point | safe | naive | observed | valid | usable | point − obs (pt) | naive − obs (pt) | predicted over | observed over | agree |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 0.025 | 0.7% | 6.7% | 0.3% | 1.6% | yes | no | -0.85 | -1.22 | no | no | yes |
| 0.035 | 1.0% | 9.4% | 0.5% | 1.8% | yes | no | -0.85 | -1.37 | no | no | yes |
| 0.05 | 1.4% | 13.5% | 0.7% | not measured | | | | | | | |
| 0.07 | 2.0% | 18.8% | 0.9% | not measured | | | | | | | |
| 0.1 | 2.8% | 26.9% | 1.3% | not measured | | | | | | | |
| 0.15 | 4.2% | 40.4% | 2.0% | not measured | | | | | | | |
| 0.2 | 5.6% | 53.8% | 2.6% | not measured | | | | | | | |
| 0.3 | 8.4% | 80.8% | 3.9% | not measured | | | | | | | |
| 0.45 | 12.7% | 121.1% | 5.9% | not measured | | | | | | | |
| 0.7 | 19.7% | 188.4% | 9.1% | not measured | | | | | | | |
| 1 | 28.1% | 269.2% | 13.1% | not measured | | | | | | | |
| 1.5 | 42.2% | 403.8% | 19.6% | not measured | | | | | | | |
| 2.2 | 61.9% | 592.3% | 28.7% | not measured | | | | | | | |
| 3.3 | 92.9% | 888.4% | 43.1% | not measured | | | | | | | |
| 5 | 140.7% | 1346.1% | 65.3% | not measured | | | | | | | |

Measured limit rps: not measured (app_bound_below_threshold: the ladder stopped below the threshold (the application was the bottleneck): no stage at or above the threshold was measured).
Predicted limit rps: point 2.8433, safe 0.2972, naive 6.1221.

## Stages where the safe-side prediction fell below the observation

None.
