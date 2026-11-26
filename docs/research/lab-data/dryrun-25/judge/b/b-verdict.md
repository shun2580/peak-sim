# B verdict (judge_b.py)

Prediction: `prediction/ladder-prediction-dryrun25-b30k.json`; rows 30000; captured max 30000; multiple of the capture 1.000; seen-before check: seen (1 counts in seen-counts.txt).

Scenarios 5, steps measured 10, valid 10, usable (valid and 10% to 90%) 0, scenarios whose limit rps was measured 0.

**Verdict: UNDECIDABLE.** Undecidable because: usable steps 0, fewer than 10; scenarios whose limit rps was measured 0, fewer than 4.

| Item | Value | Lowest standard | Goal |
| --- | --- | --- | --- |
| 1. mean abs. difference smaller than the naive estimate | point none (no usable step); naive none (no usable step) | not met | not met |
| 2. mean abs. difference (15 / 5 points) | none (no usable step) over 0 usable steps | not met | not met |
| 3. "over 80% or not" agrees (all valid steps, 80% or more) | 10 of 10 = 100.00% | met | met |
| 4. predicted limit rps within ±20% / ±10% (80% or more of the scenarios with a limit) | 0 / 0 of 0 | not met | not met |

Numbers condition 1b is counted with (D153, D167; the goal): item 1 smaller than naive: no; item 2 over the 5 points: none (no usable step); item 3 short: 0 (10 of 10 valid steps agree, 8 needed); item 4 short: 0 at the goal, 0 at the lowest standard (0 of 0 within ±10%, 0 within ±20%).

Condition 1b: **undecidable**; no number short is given (D153).

Condition 1b counted as met (spec 7.4.4: goal met, 5 times the capture or more, a count not seen before): **no** (B is undecidable; the multiple of the capture is 1.000, below 5; the count is seen before).

- s-1: limit rps measured not measured (app_bound_below_threshold); predicted point 3.54892
- s-2: limit rps measured not measured (app_bound_below_threshold); predicted point 2.54548
- s-3: limit rps measured not measured (app_bound_below_threshold); predicted point 5.85831
- s-4: limit rps measured not measured (app_bound_below_threshold); predicted point 2.54641
- s-5: limit rps measured not measured (app_bound_below_threshold); predicted point 2.84325
