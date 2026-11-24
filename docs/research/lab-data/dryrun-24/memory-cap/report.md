# Runtime measurement run

profile: bagisto

Every verdict here was measured on this input, along the path that was taken, in the scope named beside it. A flat count on a path that never read the scaled tables, or that returned an empty response, is `no_info`, not O(1). Where the static analysis and the measurement disagree, both are shown as they are, and a difference is not fitted to either side.

## Conflicts

No conflicts: no route where the static analysis was determinate and the measurement said otherwise.

## Summary

Routes: 2. Tried: 2.

| outcome class | routes |
| --- | ---: |
| ok | 1 |
| runner_failed | 1 |

| verdict | routes |
| --- | ---: |
| measured_O1 | 0 |
| measured_ON | 0 |
| measured_ONM | 0 |
| inconclusive | 0 |
| no_info | 0 |
| not_measured | 2 |

| against the static analysis | routes |
| --- | ---: |
| agree | 0 |
| runtime_only | 0 |
| static_stronger | 0 |
| conflict | 0 |
| not_comparable | 2 |

## Routes

| route | method | outcome | verdict | scope | slope | static | against static | note |
| --- | --- | --- | --- | --- | ---: | --- | --- | --- |
| /admin/customers/search | GET | ok | not_measured | global | - | unknown | not_comparable | insufficient_points |
| /admin/customers/view/{id} | GET | runner_failed | not_measured | - | - | unknown | not_comparable | exit 9: ayStore","session_driver":"array","mail":"Illuminate\\Support\\Testing\\Fakes\\MailFake","notification":"Illuminate\\Support\\Testing\\Fakes\\NotificationFake","queue":"Illuminate\\Support\\Te |

## Rows per route (cold attempt)

Two different numbers, not to be mixed up. **Returned rows** are the rows the statements gave back to the app. **Examined rows** are the rows the database looked at to answer them (the growth of the session's `Handler_read_*` counters, with the cost of reading the counters taken off); a statement that scans a table to return one row examined the whole table. `scale: value`, `-` where it could not be measured.

| route | method | returned rows | examined rows |
| --- | --- | --- | --- |
| /admin/customers/search | GET | 0: 5, 30000: 18 | 0: 95, 30000: 300041 |
| /admin/customers/view/{id} | GET | - | - |
