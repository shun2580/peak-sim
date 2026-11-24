# Count on the listed routes (count_listed.py)

Routes in the list: 19 (list SHA-256 `47b366f9fa412cfca34256076902570e04ea5c334db18cf11629aa6a5fefcae4`). Application: `bagisto`. The tool is `judge_a.py`, unchanged; the comparison tool it pins is unchanged.

**Verdict (rule B): UNDECIDABLE.** queries: 14 of 19 routes with an estimate could not be measured, more than 20%; rows_examined: 14 of 19 routes with an estimate could not be measured, more than 20%

Rule A, shown and not deciding: undecidable. queries: 14 of 19 routes with an estimate could not be measured, more than 20%; rows_examined: 14 of 19 routes with an estimate could not be measured, more than 20%

| Quantity | Routes in list | With a complete estimate | Measured (denominator) | Not measured | Denominator under 5 | Not measured over 20% of those with an estimate |
| --- | --- | --- | --- | --- | --- | --- |
| queries | 19 | 19 | 5 | 14 | no | yes |
| rows_examined | 19 | 19 | 5 | 14 | no | yes |

| Condition 1a on the listed routes | Inside | Denominator | Needed | Short |
| --- | --- | --- | --- | --- |
| goal_unmarked_queries | 1 | 1 | n/a | n/a (undecidable) |
| goal_unmarked_rows_examined | 0 | 1 | n/a | n/a (undecidable) |
| minimum_all_queries | 5 | 5 | n/a | n/a (undecidable) |
| minimum_all_rows_examined | 4 | 5 | n/a | n/a (undecidable) |

**The line is not met on the listed routes.** The count is undecidable, which does not meet the line.

Every route of the list has a complete estimate.

Routes outside the goal on the listed routes (condition 3 shown for these routes only): 1. Not measured, with an estimate: 14.

