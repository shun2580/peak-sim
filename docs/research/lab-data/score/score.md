# Lab backtest: score of the ladders

Threshold: 80.0%. Usable stage: valid and observed 10.0% to 90.0%.
Scenarios 5 · stages measured 47 · valid 47 · usable 31 · scenarios whose limit rps was measured 5.

## Verdict: FAIL (the minimum is not met)

| Item | Value | Minimum | Target |
| --- | --- | --- | --- |
| 1. mean abs. difference smaller than the naive estimate | 12.25 pt vs naive 23.10 pt | met | met |
| 2. mean abs. difference (lines 15 / 5 points) | 12.25 pt | met | not met |
| 3. "exceeded the threshold" agrees, all valid stages (80% or more) | 44/47 = 93.6% (naive 87.2%) | met | met |
| 4. predicted limit rps within ±20.0% / ±10.0% of the measured one (80% or more) | 2/5 and 1/5 | not met | not met |

Item 4 for the other two predictions, for the record (not used for the verdict): safe side 1/5 and 0/5; naive estimate 0/5 and 0/5 (within the minimum and the target tolerance).

## Scenario t-1

| rps | point | safe | naive | observed | valid | usable | point − obs (pt) | naive − obs (pt) | predicted over | observed over | agree |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 0.05 | 3.6% | 3.7% | 1.8% | 4.2% | yes | no | -0.57 | -2.33 | no | no | yes |
| 0.07 | 5.0% | 5.2% | 2.5% | 5.4% | yes | no | -0.41 | -2.88 | no | no | yes |
| 0.1 | 7.2% | 7.4% | 3.6% | 7.4% | yes | no | -0.19 | -3.72 | no | no | yes |
| 0.15 | 10.8% | 11.1% | 5.5% | 10.6% | yes | yes | +0.14 | -5.16 | no | no | yes |
| 0.2 | 14.3% | 14.8% | 7.3% | 13.8% | yes | yes | +0.54 | -6.52 | no | no | yes |
| 0.3 | 21.5% | 22.2% | 10.9% | 20.3% | yes | yes | +1.23 | -9.36 | no | no | yes |
| 0.45 | 32.3% | 33.3% | 16.4% | 30.4% | yes | yes | +1.82 | -14.07 | no | no | yes |
| 0.7 | 50.2% | 51.8% | 25.5% | 48.4% | yes | yes | +1.82 | -22.89 | no | no | yes |
| 1 | 71.7% | 74.0% | 36.4% | 67.0% | yes | yes | +4.72 | -30.58 | no | no | yes |
| 1.5 | 107.6% | 111.0% | 54.6% | 87.8% | yes | yes | +19.77 | -33.18 | yes | yes | yes |
| 2.2 | 157.8% | 162.8% | 80.1% | not measured | | | | | | | |
| 3.3 | 236.6% | 244.2% | 120.1% | not measured | | | | | | | |
| 5 | 358.5% | 370.0% | 182.0% | not measured | | | | | | | |

Measured limit rps: 1.3128 (interpolated between two valid stages).
Predicted limit rps: point 1.1157, safe 1.0811, naive 2.1975. Relative error: point -15.0%, safe -17.7%, naive +67.4%.

## Scenario t-2

| rps | point | safe | naive | observed | valid | usable | point − obs (pt) | naive − obs (pt) | predicted over | observed over | agree |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 0.05 | 13.1% | 13.1% | 1.8% | 9.2% | yes | no | +3.92 | -7.35 | no | no | yes |
| 0.07 | 18.3% | 18.3% | 2.5% | 12.5% | yes | yes | +5.80 | -9.97 | no | no | yes |
| 0.1 | 26.2% | 26.2% | 3.6% | 17.5% | yes | yes | +8.70 | -13.84 | no | no | yes |
| 0.15 | 39.3% | 39.3% | 5.5% | 25.8% | yes | yes | +13.45 | -20.36 | no | no | yes |
| 0.2 | 52.4% | 52.4% | 7.3% | 34.3% | yes | yes | +18.10 | -26.98 | no | no | yes |
| 0.3 | 78.5% | 78.5% | 10.9% | 51.2% | yes | yes | +27.35 | -40.27 | no | no | yes |
| 0.45 | 117.8% | 117.8% | 16.4% | 75.6% | yes | yes | +42.16 | -59.27 | yes | no | no |
| 0.7 | 183.3% | 183.3% | 25.5% | 99.0% | yes | no | +84.27 | -73.51 | yes | yes | yes |
| 1 | 261.8% | 261.8% | 36.4% | not measured | | | | | | | |
| 1.5 | 392.7% | 392.7% | 54.6% | not measured | | | | | | | |
| 2.2 | 575.9% | 576.0% | 80.1% | not measured | | | | | | | |
| 3.3 | 863.9% | 864.0% | 120.1% | not measured | | | | | | | |
| 5 | 1309.0% | 1309.1% | 182.0% | not measured | | | | | | | |

Measured limit rps: 0.4966 (interpolated between two valid stages).
Predicted limit rps: point 0.3056, safe 0.3055, naive 2.1975. Relative error: point -38.5%, safe -38.5%, naive +342.5%.

## Scenario t-3

| rps | point | safe | naive | observed | valid | usable | point − obs (pt) | naive − obs (pt) | predicted over | observed over | agree |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 0.05 | 20.9% | 20.9% | 1.8% | 14.6% | yes | yes | +6.32 | -12.73 | no | no | yes |
| 0.07 | 29.2% | 29.2% | 2.5% | 19.6% | yes | yes | +9.57 | -17.09 | no | no | yes |
| 0.1 | 41.7% | 41.7% | 3.6% | 27.5% | yes | yes | +14.20 | -23.89 | no | no | yes |
| 0.15 | 62.6% | 62.6% | 5.5% | 41.1% | yes | yes | +21.50 | -35.64 | no | no | yes |
| 0.2 | 83.5% | 83.5% | 7.3% | 54.8% | yes | yes | +28.64 | -47.55 | yes | no | no |
| 0.3 | 125.2% | 125.2% | 10.9% | 81.2% | yes | yes | +44.02 | -70.26 | yes | yes | yes |
| 0.45 | 187.8% | 187.8% | 16.4% | not measured | | | | | | | |
| 0.7 | 292.1% | 292.2% | 25.5% | not measured | | | | | | | |
| 1 | 417.3% | 417.4% | 36.4% | not measured | | | | | | | |
| 1.5 | 626.0% | 626.1% | 54.6% | not measured | | | | | | | |
| 2.2 | 918.1% | 918.2% | 80.1% | not measured | | | | | | | |
| 3.3 | 1377.2% | 1377.4% | 120.1% | not measured | | | | | | | |
| 5 | 2086.7% | 2086.9% | 182.0% | not measured | | | | | | | |

Measured limit rps: 0.2955 (interpolated between two valid stages).
Predicted limit rps: point 0.1917, safe 0.1917, naive 2.1975. Relative error: point -35.1%, safe -35.1%, naive +643.6%.

## Scenario t-4

| rps | point | safe | naive | observed | valid | usable | point − obs (pt) | naive − obs (pt) | predicted over | observed over | agree |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 0.05 | 1.2% | 1.5% | 1.8% | 2.0% | yes | no | -0.81 | -0.14 | no | no | yes |
| 0.07 | 1.6% | 2.1% | 2.5% | 2.4% | yes | no | -0.80 | +0.14 | no | no | yes |
| 0.1 | 2.3% | 3.0% | 3.6% | 3.1% | yes | no | -0.75 | +0.59 | no | no | yes |
| 0.15 | 3.5% | 4.6% | 5.5% | 4.1% | yes | no | -0.68 | +1.32 | no | no | yes |
| 0.2 | 4.6% | 6.1% | 7.3% | 5.2% | yes | no | -0.61 | +2.06 | no | no | yes |
| 0.3 | 6.9% | 9.1% | 10.9% | 7.3% | yes | no | -0.42 | +3.59 | no | no | yes |
| 0.45 | 10.4% | 13.7% | 16.4% | 10.6% | yes | yes | -0.24 | +5.77 | no | no | yes |
| 0.7 | 16.1% | 21.3% | 25.5% | 15.9% | yes | yes | +0.21 | +9.56 | no | no | yes |
| 1 | 23.0% | 30.4% | 36.4% | 22.4% | yes | yes | +0.64 | +13.99 | no | no | yes |
| 1.5 | 34.6% | 45.6% | 54.6% | 34.1% | yes | yes | +0.48 | +20.52 | no | no | yes |
| 2.2 | 50.7% | 66.9% | 80.1% | 50.0% | yes | yes | +0.68 | +30.07 | no | no | yes |
| 3.3 | 76.1% | 100.3% | 120.1% | 73.8% | yes | yes | +2.29 | +46.37 | no | no | yes |
| 5 | 115.2% | 152.0% | 182.0% | 96.6% | yes | no | +18.65 | +85.44 | yes | yes | yes |

Measured limit rps: 3.7646 (interpolated between two valid stages).
Predicted limit rps: point 3.4713, safe 2.6318, naive 2.1975. Relative error: point -7.8%, safe -30.1%, naive -41.6%.

## Scenario t-5

| rps | point | safe | naive | observed | valid | usable | point − obs (pt) | naive − obs (pt) | predicted over | observed over | agree |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 0.05 | 5.3% | 5.3% | 1.8% | 4.2% | yes | no | +1.09 | -2.40 | no | no | yes |
| 0.07 | 7.4% | 7.4% | 2.5% | 5.5% | yes | no | +1.89 | -3.00 | no | no | yes |
| 0.1 | 10.6% | 10.6% | 3.6% | 7.6% | yes | no | +3.07 | -3.91 | no | no | yes |
| 0.15 | 15.9% | 15.9% | 5.5% | 10.8% | yes | yes | +5.12 | -5.35 | no | no | yes |
| 0.2 | 21.3% | 21.3% | 7.3% | 14.1% | yes | yes | +7.11 | -6.86 | no | no | yes |
| 0.3 | 31.9% | 31.9% | 10.9% | 20.8% | yes | yes | +11.07 | -9.88 | no | no | yes |
| 0.45 | 47.8% | 47.8% | 16.4% | 30.9% | yes | yes | +16.89 | -14.55 | no | no | yes |
| 0.7 | 74.4% | 74.4% | 25.5% | 47.8% | yes | yes | +26.56 | -22.33 | no | no | yes |
| 1 | 106.3% | 106.3% | 36.4% | 67.6% | yes | yes | +38.61 | -31.24 | yes | no | no |
| 1.5 | 159.4% | 159.4% | 54.6% | 97.1% | yes | no | +62.32 | -42.45 | yes | yes | yes |
| 2.2 | 233.8% | 233.8% | 80.1% | not measured | | | | | | | |
| 3.3 | 350.6% | 350.7% | 120.1% | not measured | | | | | | | |
| 5 | 531.3% | 531.3% | 182.0% | not measured | | | | | | | |

Measured limit rps: 1.21 (interpolated between two valid stages).
Predicted limit rps: point 0.7529, safe 0.7528, naive 2.1975. Relative error: point -37.8%, safe -37.8%, naive +81.6%.

## Stages where the safe-side prediction fell below the observation

| scenario | rps | safe | observed | valid | usable |
| --- | --- | --- | --- | --- | --- |
| t-1 | 0.05 | 3.7% | 4.2% | yes | no |
| t-1 | 0.07 | 5.2% | 5.4% | yes | no |
| t-4 | 0.05 | 1.5% | 2.0% | yes | no |
| t-4 | 0.07 | 2.1% | 2.4% | yes | no |
| t-4 | 0.1 | 3.0% | 3.1% | yes | no |
