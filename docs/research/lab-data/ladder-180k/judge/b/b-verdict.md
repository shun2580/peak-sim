# B verdict (judge_b.py)

Prediction: `poc/lab/predictions/ladder-prediction-6-b180k.json`; rows 180000; captured max 30000; multiple of the capture 6.000; seen-before check: not seen (14 counts in seen-counts.txt).

Scenarios 5, steps measured 37, valid 37, usable (valid and 10% to 90%) 29, scenarios whose limit rps was measured 5.

**Verdict: PASS.**

| Item | Value | Lowest standard | Goal |
| --- | --- | --- | --- |
| 1. mean abs. difference smaller than the naive estimate | point 0.866 pt; naive 33.351 pt | met | met |
| 2. mean abs. difference (15 / 5 points) | 0.866 pt over 29 usable steps | met | met |
| 3. "over 80% or not" agrees (all valid steps, 80% or more) | 37 of 37 = 100.00% | met | met |
| 4. predicted limit rps within ±20% / ±10% (80% or more of the scenarios with a limit) | 5 / 5 of 5 | met | met |

Numbers condition 1b is counted with (D153, D167; the goal): item 1 smaller than naive: yes; item 2 over the 5 points: 0.000; item 3 short: 0 (37 of 37 valid steps agree, 30 needed); item 4 short: 0 at the goal, 0 at the lowest standard (5 of 5 within ±10%, 5 within ±20%).

Condition 1b counted as met (spec 7.4.4: goal met, 5 times the capture or more, a count not seen before): **yes**.

- s-1: limit rps measured 0.18366 (interpolated); predicted point 0.17816
- s-2: limit rps measured 0.09744 (interpolated); predicted point 0.09545
- s-3: limit rps measured 0.39425 (interpolated); predicted point 0.38165
- s-4: limit rps measured 0.12911 (interpolated); predicted point 0.12726
- s-5: limit rps measured 0.53194 (interpolated); predicted point 0.50876
