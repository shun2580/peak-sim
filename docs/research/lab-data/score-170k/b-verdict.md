# B verdict (judge_b.py)

Prediction: `poc/lab/predictions/ladder-prediction-5-b170k.json`; rows 170000; captured max 30000; multiple of the capture 5.667; seen-before check: not seen (11 counts in seen.txt).

Scenarios 5, steps measured 39, valid 39, usable (valid and 10% to 90%) 32, scenarios whose limit rps was measured 5.

**Verdict: PASS.**

| Item | Value | Lowest standard | Goal |
| --- | --- | --- | --- |
| 1. mean abs. difference smaller than the naive estimate | point 2.541 pt; naive 32.984 pt | met | met |
| 2. mean abs. difference (15 / 5 points) | 2.541 pt over 32 usable steps | met | met |
| 3. "over 80% or not" agrees (all valid steps, 80% or more) | 38 of 39 = 97.44% | met | met |
| 4. predicted limit rps within ±20% / ±10% (80% or more of the scenarios with a limit) | 5 / 1 of 5 | met | not met |

Numbers condition 1b is counted with (D153, D167; the goal): item 1 smaller than naive: yes; item 2 over the 5 points: 0.000; item 3 short: 0 (38 of 39 valid steps agree, 32 needed); item 4 short: 3 at the goal, 0 at the lowest standard (1 of 5 within ±10%, 5 within ±20%).

Condition 1b counted as met (spec 7.4.4: goal met, 5 times the capture or more, a count not seen before): **no** (B passes the lowest standard but not the goal).

- s-1: limit rps measured 0.21953 (interpolated); predicted point 0.18863
- s-2: limit rps measured 0.11697 (interpolated); predicted point 0.10107
- s-3: limit rps measured 0.44943 (interpolated); predicted point 0.40408
- s-4: limit rps measured 0.14756 (interpolated); predicted point 0.13475
- s-5: limit rps measured 0.63518 (interpolated); predicted point 0.53867
