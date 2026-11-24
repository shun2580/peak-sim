# Runtime measurement run

profile: bagisto

Every verdict here was measured on this input, along the path that was taken, in the scope named beside it. A flat count on a path that never read the scaled tables, or that returned an empty response, is `no_info`, not O(1). Where the static analysis and the measurement disagree, both are shown as they are, and a difference is not fitted to either side.

## Conflicts

No conflicts: no route where the static analysis was determinate and the measurement said otherwise.

## Summary

Routes: 11. Tried: 11.

| outcome class | routes |
| --- | ---: |
| exception | 4 |
| not_found | 2 |
| ok | 1 |
| unfilled_param | 4 |

| verdict | routes |
| --- | ---: |
| measured_O1 | 0 |
| measured_ON | 0 |
| measured_ONM | 0 |
| inconclusive | 0 |
| no_info | 0 |
| not_measured | 11 |

| against the static analysis | routes |
| --- | ---: |
| agree | 0 |
| runtime_only | 0 |
| static_stronger | 0 |
| conflict | 0 |
| not_comparable | 11 |

## Routes

| route | method | outcome | verdict | scope | slope | static | against static | note |
| --- | --- | --- | --- | --- | ---: | --- | --- | --- |
| /admin/customers/search | GET | ok | not_measured | global | - | unknown | not_comparable | insufficient_points |
| /admin/customers/view/{id} | GET | not_found | not_measured | global | - | unknown | not_comparable | scale 0 (axis N): not_found |
| /admin/sales/invoices/create/{order_id} | POST | not_found | not_measured | global | - | unknown | not_comparable | scale 0 (axis N): not_found |
| /admin/sales/rma/rules/delete/{id} | DELETE | exception | not_measured | global | - | unknown | not_comparable | scale 0 (axis N): exception |
| /api/checkout/cart | PUT | exception | not_measured | global | - | unknown | not_comparable | scale 0 (axis N): exception |
| /api/products | GET | exception | not_measured | global | - | unknown | not_comparable | scale 0 (axis N): exception |
| /customer/account | GET | unfilled_param | not_measured | global | - | unknown | not_comparable | scale 0 (axis N): act-as: no Webkul\Customer\Models\Customer with key 1 in the measurement database |
| /customer/account/orders/cancel/{id} | POST | unfilled_param | not_measured | global | - | unknown | not_comparable | scale 0 (axis N): act-as: no Webkul\Customer\Models\Customer with key 1 in the measurement database |
| /customer/account/profile/destroy | POST | unfilled_param | not_measured | global | - | unknown | not_comparable | scale 0 (axis N): act-as: no Webkul\Customer\Models\Customer with key 1 in the measurement database |
| /customer/account/wishlist | GET | unfilled_param | not_measured | global | - | unknown | not_comparable | scale 0 (axis N): act-as: no Webkul\Customer\Models\Customer with key 1 in the measurement database |
| /search | GET | exception | not_measured | global | - | unknown | not_comparable | scale 0 (axis N): exception |

## Rows per route (cold attempt)

Two different numbers, not to be mixed up. **Returned rows** are the rows the statements gave back to the app. **Examined rows** are the rows the database looked at to answer them (the growth of the session's `Handler_read_*` counters, with the cost of reading the counters taken off); a statement that scans a table to return one row examined the whole table. `scale: value`, `-` where it could not be measured.

| route | method | returned rows | examined rows |
| --- | --- | --- | --- |
| /admin/customers/search | GET | 0: 5, 30000: 18 | 0: 95, 30000: 300041 |
| /admin/customers/view/{id} | GET | 30000: 220013 | 30000: 570079 |
| /admin/sales/invoices/create/{order_id} | POST | 30000: 30005 | 30000: 270028 |
| /admin/sales/rma/rules/delete/{id} | DELETE | 30000: 5 | 30000: 150028 |
| /api/checkout/cart | PUT | 30000: 1 | 30000: 4 |
| /api/products | GET | 30000: 215 | 30000: 1770285 |
| /customer/account | GET | 30000: 10 | 30000: 600082 |
| /customer/account/orders/cancel/{id} | POST | 30000: 30002 | 30000: 120006 |
| /customer/account/profile/destroy | POST | 30000: 2 | 30000: 5 |
| /customer/account/wishlist | GET | 30000: 10 | 30000: 600082 |
| /search | GET | 30000: 1 | 30000: 30008 |
