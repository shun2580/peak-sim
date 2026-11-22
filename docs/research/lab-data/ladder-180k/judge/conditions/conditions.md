# Conditions 1 to 3 of section 0 (conditions.py)

Counted as D153 counts them (with D167), per application for 1a (D215), and with the rule of section 7.4.4 for 1b. Every figure is read from the verdict files; nothing is typed.

## Condition 1a (the extrapolation test), rule B, per application

| Application | Verdict | Goal, unmarked, queries | Goal, unmarked, rows examined | Lowest standard, all, queries | Lowest standard, all, rows examined | Condition 1a |
| --- | --- | --- | --- | --- | --- | --- |
| bookstack | pass | 15 of 15; 12 needed; short 0 | 13 of 15; 12 needed; short 0 | 34 of 34; 28 needed; short 0 | 29 of 34; 28 needed; short 0 | met |
| fixtures | pass | 21 of 21; 17 needed; short 0 | 21 of 21; 17 needed; short 0 | 21 of 21; 17 needed; short 0 | 21 of 21; 17 needed; short 0 | met |

## Condition 1b (the lab backtest), counted on the goal

B at 180000 rows: **pass**. 1) item 1, smaller than the naive estimate: yes. 2) item 2, points over 5: 0.000. 3) item 3, agree 37 of 37 valid steps, short 0. 4) item 4, within ±10% in 5 of 5 scenarios, short 0 (lowest standard: short 0).

Counted as condition 1b met (7.4.4: the goal met, 5 times the capture's largest size or more, a count not seen before): **yes**. Multiple of the capture: 6.000.

## Condition 2 (a third application, Bagisto)

Not measured. It is not 0.

## Condition 3 (the remaining misses), provisional until conditions 1 and 2 are met

A miss is a route with queries or rows examined outside the goal, rule B. Remaining = misses whose cause is not identified, or that were not marked at the prediction (the union).

| Application | Misses | Marked | Unmarked | Cause identified | Remaining | Not measured (apart) | No estimate (apart) |
| --- | --- | --- | --- | --- | --- | --- | --- |
| bookstack | 10 | 8 | 2 | 0 | 10 (provisional) | 1 | 1 |
| fixtures | 0 | 0 | 0 | 0 | 0 (provisional) | 1 | 2 |

