# Count on the listed routes (count_listed.py)

Routes in the list: 19 (list SHA-256 `47b366f9fa412cfca34256076902570e04ea5c334db18cf11629aa6a5fefcae4`). Application: `bagisto`. The tool is `judge_a.py`, unchanged; the comparison tool it pins is unchanged.

**Verdict (rule B): PASS.** queries: 16 of 18 inside +-5% (enough), needs 80%; rows_examined: 15 of 18 inside +-20% (enough), needs 80%

Rule A, shown and not deciding: pass. queries: 16 of 18 inside +-5% (enough), needs 80%; rows_examined: 15 of 18 inside +-20% (enough), needs 80%

| Quantity | Routes in list | With a complete estimate | Measured (denominator) | Not measured | Denominator under 5 | Not measured over 20% of those with an estimate |
| --- | --- | --- | --- | --- | --- | --- |
| queries | 19 | 19 | 18 | 1 | no | no |
| rows_examined | 19 | 19 | 18 | 1 | no | no |

| Condition 1a on the listed routes | Inside | Denominator | Needed | Short |
| --- | --- | --- | --- | --- |
| goal_unmarked_queries | 5 | 6 | 5 | 0 |
| goal_unmarked_rows_examined | 6 | 6 | 5 | 0 |
| minimum_all_queries | 16 | 18 | 15 | 0 |
| minimum_all_rows_examined | 15 | 18 | 15 | 0 |

**The line is met on the listed routes.** All four short numbers are 0.

Every route of the list has a complete estimate.

Routes outside the goal on the listed routes (condition 3 shown for these routes only): 5. Not measured, with an estimate: 1.

