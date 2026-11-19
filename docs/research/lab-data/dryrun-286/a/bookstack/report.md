# Runtime measurement run

profile: bookstack

Every verdict here was measured on this input, along the path that was taken, in the scope named beside it. A flat count on a path that never read the scaled tables, or that returned an empty response, is `no_info`, not O(1). Where the static analysis and the measurement disagree, both are shown as they are, and a difference is not fitted to either side.

## Conflicts

No conflicts: no route where the static analysis was determinate and the measurement said otherwise.

## Summary

Routes: 9. Tried: 9.

| outcome class | routes |
| --- | ---: |
| not_found | 3 |
| ok | 4 |
| validation_422 | 2 |

| verdict | routes |
| --- | ---: |
| measured_O1 | 0 |
| measured_ON | 0 |
| measured_ONM | 0 |
| inconclusive | 0 |
| no_info | 0 |
| not_measured | 9 |

| against the static analysis | routes |
| --- | ---: |
| agree | 0 |
| runtime_only | 0 |
| static_stronger | 0 |
| conflict | 0 |
| not_comparable | 9 |

## Routes

| route | method | outcome | verdict | scope | slope | static | against static | note |
| --- | --- | --- | --- | --- | ---: | --- | --- | --- |
| /books/{slug} | GET | not_found | not_measured | targeted:entities | - | unknown | not_comparable | scale 0 (axis N): not_found |
| /books/{slug} | PUT | not_found | not_measured | targeted:entities | - | unknown | not_comparable | scale 0 (axis N): not_found |
| /books/{slug}/references | GET | ok | not_measured | targeted:references | - | unknown | not_comparable | insufficient_points |
| /settings/maintenance/regenerate-references | POST | ok | not_measured | targeted:entities | - | unknown | not_comparable | insufficient_points |
| /settings/users/create | GET | ok | not_measured | targeted:roles | - | unknown | not_comparable | insufficient_points |
| /settings/users/create | POST | validation_422 | not_measured | - | - | unknown | not_comparable | The email field is required. (and 2 more errors) \| fields: email, password, password-confirm |
| /shelves/{slug} | DELETE | ok | not_measured | targeted:deletions | - | unknown | not_comparable | insufficient_points |
| /shelves/{slug} | GET | not_found | not_measured | targeted:entities | - | unknown | not_comparable | scale 0 (axis N): not_found |
| /shelves/{slug} | PUT | validation_422 | not_measured | targeted:references | - | unknown | not_comparable | scale 0 (axis N): The name field is required. \| fields: name |

## Rows per route (cold attempt)

Two different numbers, not to be mixed up. **Returned rows** are the rows the statements gave back to the app. **Examined rows** are the rows the database looked at to answer them (the growth of the session's `Handler_read_*` counters, with the cost of reading the counters taken off); a statement that scans a table to return one row examined the whole table. `scale: value`, `-` where it could not be measured.

| route | method | returned rows | examined rows |
| --- | --- | --- | --- |
| /books/{slug} | GET | 30000: 108310 | 30000: 234454 |
| /books/{slug} | PUT | 30000: 165187 | 30000: 382733 |
| /books/{slug}/references | GET | 0: 192, 30000: 7699 | 0: 200, 30000: 10710 |
| /settings/maintenance/regenerate-references | POST | 0: 186, 30000: 60186 | 0: 204, 30000: 4503507 |
| /settings/users/create | GET | 0: 193, 30000: 30193 | 0: 202, 30000: 30202 |
| /settings/users/create | POST | - | - |
| /shelves/{slug} | DELETE | 0: 191, 30000: 30203 | 0: 204, 30000: 239015 |
| /shelves/{slug} | GET | 30000: 210 | 30000: 236 |
| /shelves/{slug} | PUT | 0: 195, 30000: 199 | 0: 223, 30000: 10730 |
