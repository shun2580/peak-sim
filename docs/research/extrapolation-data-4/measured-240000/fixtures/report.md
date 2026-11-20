# Runtime measurement run

profile: none

Every verdict here was measured on this input, along the path that was taken, in the scope named beside it. A flat count on a path that never read the scaled tables, or that returned an empty response, is `no_info`, not O(1). Where the static analysis and the measurement disagree, both are shown as they are, and a difference is not fitted to either side.

## Conflicts

No conflicts: no route where the static analysis was determinate and the measurement said otherwise.

## Summary

Routes: 24. Tried: 24.

| outcome class | routes |
| --- | ---: |
| ok | 22 |
| runner_failed | 1 |
| timeout | 1 |

| verdict | routes |
| --- | ---: |
| measured_O1 | 0 |
| measured_ON | 0 |
| measured_ONM | 0 |
| inconclusive | 0 |
| no_info | 0 |
| not_measured | 24 |

| against the static analysis | routes |
| --- | ---: |
| agree | 0 |
| runtime_only | 0 |
| static_stronger | 0 |
| conflict | 0 |
| not_comparable | 24 |

## Routes

| route | method | outcome | verdict | scope | slope | static | against static | note |
| --- | --- | --- | --- | --- | ---: | --- | --- | --- |
| /hidden/appends | GET | ok | not_measured | targeted:orders | - | O(N) | not_comparable | insufficient_points |
| /hidden/blade | GET | ok | not_measured | targeted:orders | - | O(N) | not_comparable | insufficient_points |
| /hidden/cache | GET | ok | not_measured | targeted:orders | - | O(N) | not_comparable | insufficient_points |
| /hidden/conditional-binding | GET | ok | not_measured | targeted:orders | - | O(N) | not_comparable | insufficient_points |
| /hidden/cursor | GET | ok | not_measured | targeted:orders | - | O(1) | not_comparable | insufficient_points |
| /hidden/model-with | GET | ok | not_measured | targeted:orders | - | O(1) | not_comparable | insufficient_points |
| /hidden/resource | GET | ok | not_measured | targeted:orders | - | O(N) | not_comparable | insufficient_points |
| /ng/collection-filter | GET | ok | not_measured | targeted:orders | - | O(1) | not_comparable | insufficient_points |
| /ng/count-in-loop | GET | ok | not_measured | targeted:orders | - | O(N) | not_comparable | insufficient_points |
| /ng/first-or-create-in-loop | GET | runner_failed | not_measured | - | - | O(N) | not_comparable | no_attempts |
| /ng/get-then-count | GET | ok | not_measured | targeted:orders | - | O(1) | not_comparable | insufficient_points |
| /ng/layered | GET | ok | not_measured | targeted:orders | - | O(N) | not_comparable | insufficient_points |
| /ng/n-plus-one | GET | ok | not_measured | targeted:orders | - | O(N) | not_comparable | insufficient_points |
| /ng/nested | GET | ok | not_measured | targeted:orders | - | O(N*M) | not_comparable | insufficient_points |
| /ng/save-in-loop | GET | ok | not_measured | targeted:customers | - | O(N) | not_comparable | insufficient_points |
| /ng/service | GET | ok | not_measured | targeted:orders | - | O(N) | not_comparable | insufficient_points |
| /ng/unbounded | GET | ok | not_measured | targeted:orders | - | O(1) | not_comparable | insufficient_points |
| /ok/bulk | POST | timeout | not_measured | - | - | O(1) | not_comparable | no_attempts |
| /ok/chunk | GET | ok | not_measured | targeted:orders | - | O(N) | not_comparable | insufficient_points |
| /ok/eager | GET | ok | not_measured | targeted:orders | - | O(1) | not_comparable | insufficient_points |
| /ok/lazy | GET | ok | not_measured | targeted:orders | - | O(N) | not_comparable | insufficient_points |
| /ok/paginate | GET | ok | not_measured | targeted:orders | - | O(1) | not_comparable | insufficient_points |
| /ok/with-count | GET | ok | not_measured | targeted:orders | - | O(1) | not_comparable | insufficient_points |
| /unknown/dynamic | GET | ok | not_measured | global | - | unknown | not_comparable | insufficient_points |

## Rows per route (cold attempt)

Two different numbers, not to be mixed up. **Returned rows** are the rows the statements gave back to the app. **Examined rows** are the rows the database looked at to answer them (the growth of the session's `Handler_read_*` counters, with the cost of reading the counters taken off); a statement that scans a table to return one row examined the whole table. `scale: value`, `-` where it could not be measured.

| route | method | returned rows | examined rows |
| --- | --- | --- | --- |
| /hidden/appends | GET | 0: 0, 240000: 480000 | 0: 3, 240000: 480003 |
| /hidden/blade | GET | 0: 0, 240000: 480000 | 0: 3, 240000: 480003 |
| /hidden/cache | GET | 0: 0, 240000: 480000 | 0: 3, 240000: 480003 |
| /hidden/conditional-binding | GET | 0: 0, 240000: 480000 | 0: 3, 240000: 480003 |
| /hidden/cursor | GET | 0: 0, 240000: 240000 | 0: 3, 240000: 240003 |
| /hidden/model-with | GET | 0: 0, 240000: 240020 | 0: 3, 240000: 240026 |
| /hidden/resource | GET | 0: 0, 240000: 480000 | 0: 3, 240000: 480003 |
| /ng/collection-filter | GET | 0: 0, 240000: 240000 | 0: 3, 240000: 240003 |
| /ng/count-in-loop | GET | 0: 0, 240000: 480000 | 0: 3, 240000: 1200003 |
| /ng/first-or-create-in-loop | GET | 0: 0 | 0: 3 |
| /ng/get-then-count | GET | 0: 0, 240000: 240000 | 0: 3, 240000: 240003 |
| /ng/layered | GET | 0: 0, 240000: 480000 | 0: 3, 240000: 480003 |
| /ng/n-plus-one | GET | 0: 0, 240000: 480000 | 0: 3, 240000: 480003 |
| /ng/nested | GET | 0: 0, 240000: 1680000 | 0: 3, 240000: 1920003 |
| /ng/save-in-loop | GET | 0: 0, 240000: 240000 | 0: 3, 240000: 480003 |
| /ng/service | GET | 0: 0, 240000: 480000 | 0: 3, 240000: 480003 |
| /ng/unbounded | GET | 0: 0, 240000: 240000 | 0: 3, 240000: 240003 |
| /ok/bulk | POST | 0: 0 | 0: 2 |
| /ok/chunk | GET | 0: 0, 240000: 240000 | 0: 2, 240000: 288362402 |
| /ok/eager | GET | 0: 0, 240000: 240020 | 0: 3, 240000: 240026 |
| /ok/lazy | GET | 0: 0, 240000: 240000 | 0: 2, 240000: 288362402 |
| /ok/paginate | GET | 0: 1, 240000: 16 | 0: 2, 240000: 240019 |
| /ok/with-count | GET | 0: 0, 240000: 480000 | 0: 3, 240000: 2160003 |
| /unknown/dynamic | GET | 0: 1, 240000: 1 | 0: 2, 240000: 240002 |
