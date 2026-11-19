# Conditions 1 to 3 of section 0 (conditions.py)

Counted as D153 counts them (with D167), per application for 1a (D215), and with the rule of section 7.4.4 for 1b. Every figure is read from the verdict files; nothing is typed.

## Condition 1a (the extrapolation test), rule B, per application

| Application | Verdict | Goal, unmarked, queries | Goal, unmarked, rows examined | Lowest standard, all, queries | Lowest standard, all, rows examined | Condition 1a |
| --- | --- | --- | --- | --- | --- | --- |
| bookstack | undecidable | 4 of 4; undecidable, no number short | 3 of 4; undecidable, no number short | 7 of 8; undecidable, no number short | 7 of 8; undecidable, no number short | undecidable |
| fixtures | undecidable | 3 of 3; undecidable, no number short | 3 of 3; undecidable, no number short | 3 of 3; undecidable, no number short | 3 of 3; undecidable, no number short | undecidable |

## Condition 1b (the lab backtest), counted on the goal

B is **undecidable** (usable steps 0, fewer than 10; scenarios whose limit rps was measured 0, fewer than 4). No number short is given (D153). Condition 1b is not met.

Counted as condition 1b met (7.4.4: the goal met, 5 times the capture's largest size or more, a count not seen before): **no** (B is undecidable; the multiple of the capture is 1.000, below 5; the count is seen before). Multiple of the capture: 1.000.

## Condition 2 (a third application, Bagisto)

Not measured. It is not 0.

## Condition 3 (the remaining misses), provisional until conditions 1 and 2 are met

A miss is a route with queries or rows examined outside the goal, rule B. Remaining = misses whose cause is not identified, or that were not marked at the prediction (the union).

| Application | Misses | Marked | Unmarked | Cause identified | Remaining | Not measured (apart) | No estimate (apart) |
| --- | --- | --- | --- | --- | --- | --- | --- |
| bookstack | 2 | 1 | 1 | 0 | 2 (provisional) | 27 | 1 |
| fixtures | 0 | 0 | 0 | 0 | 0 (provisional) | 19 | 2 |

