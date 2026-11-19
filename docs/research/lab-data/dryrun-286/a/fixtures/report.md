# Runtime measurement run

profile: none

Every verdict here was measured on this input, along the path that was taken, in the scope named beside it. A flat count on a path that never read the scaled tables, or that returned an empty response, is `no_info`, not O(1). Where the static analysis and the measurement disagree, both are shown as they are, and a difference is not fitted to either side.

## Conflicts

No conflicts: no route where the static analysis was determinate and the measurement said otherwise.

## Summary

Routes: 4. Tried: 4.

| outcome class | routes |
| --- | ---: |
| exception | 1 |
| ok | 3 |

| verdict | routes |
| --- | ---: |
| measured_O1 | 0 |
| measured_ON | 0 |
| measured_ONM | 0 |
| inconclusive | 0 |
| no_info | 0 |
| not_measured | 4 |

| against the static analysis | routes |
| --- | ---: |
| agree | 0 |
| runtime_only | 0 |
| static_stronger | 0 |
| conflict | 0 |
| not_comparable | 4 |

## Routes

| route | method | outcome | verdict | scope | slope | static | against static | note |
| --- | --- | --- | --- | --- | ---: | --- | --- | --- |
| /ng/first-or-create-in-loop | GET | ok | not_measured | targeted:products | - | O(N) | not_comparable | insufficient_points |
| /ng/n-plus-one | GET | ok | not_measured | targeted:orders | - | O(N) | not_comparable | insufficient_points |
| /ok/bulk | POST | exception | not_measured | - | - | O(1) | not_comparable | no_attempts |
| /ok/eager | GET | ok | not_measured | targeted:orders | - | O(1) | not_comparable | insufficient_points |

## Rows per route (cold attempt)

Two different numbers, not to be mixed up. **Returned rows** are the rows the statements gave back to the app. **Examined rows** are the rows the database looked at to answer them (the growth of the session's `Handler_read_*` counters, with the cost of reading the counters taken off); a statement that scans a table to return one row examined the whole table. `scale: value`, `-` where it could not be measured.

| route | method | returned rows | examined rows |
| --- | --- | --- | --- |
| /ng/first-or-create-in-loop | GET | 0: 0, 30000: 30000 | 0: 3, 30000: 60003 |
| /ng/n-plus-one | GET | 0: 0, 30000: 60000 | 0: 3, 30000: 60003 |
| /ok/bulk | POST | 0: 0 | 0: 2 |
| /ok/eager | GET | 0: 0, 30000: 30020 | 0: 3, 30000: 30026 |
