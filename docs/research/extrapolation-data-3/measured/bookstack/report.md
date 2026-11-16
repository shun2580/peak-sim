# Runtime measurement run

profile: bookstack

Every verdict here was measured on this input, along the path that was taken, in the scope named beside it. A flat count on a path that never read the scaled tables, or that returned an empty response, is `no_info`, not O(1). Where the static analysis and the measurement disagree, both are shown as they are, and a difference is not fitted to either side.

## Conflicts

No conflicts: no route where the static analysis was determinate and the measurement said otherwise.

## Summary

Routes: 63. Tried: 63.

| outcome class | routes |
| --- | ---: |
| exception | 7 |
| not_found | 8 |
| ok | 20 |
| timeout | 1 |
| unauthenticated | 21 |
| validation_422 | 6 |

| verdict | routes |
| --- | ---: |
| measured_O1 | 0 |
| measured_ON | 0 |
| measured_ONM | 0 |
| inconclusive | 0 |
| no_info | 0 |
| not_measured | 63 |

| against the static analysis | routes |
| --- | ---: |
| agree | 0 |
| runtime_only | 0 |
| static_stronger | 0 |
| conflict | 0 |
| not_comparable | 63 |

## Routes

| route | method | outcome | verdict | scope | slope | static | against static | note |
| --- | --- | --- | --- | --- | ---: | --- | --- | --- |
| /api/books/{id} | DELETE | unauthenticated | not_measured | - | - | unknown | not_comparable | BookStack\Exceptions\ApiAuthException: No authorization token found on the request |
| /api/books/{id} | GET | unauthenticated | not_measured | - | - | unknown | not_comparable | BookStack\Exceptions\ApiAuthException: No authorization token found on the request |
| /api/books/{id} | PUT | unauthenticated | not_measured | - | - | unknown | not_comparable | BookStack\Exceptions\ApiAuthException: No authorization token found on the request |
| /api/books/{id}/export/zip | GET | unauthenticated | not_measured | - | - | unknown | not_comparable | BookStack\Exceptions\ApiAuthException: No authorization token found on the request |
| /api/chapters/{id} | DELETE | unauthenticated | not_measured | - | - | unknown | not_comparable | BookStack\Exceptions\ApiAuthException: No authorization token found on the request |
| /api/chapters/{id} | GET | unauthenticated | not_measured | - | - | unknown | not_comparable | BookStack\Exceptions\ApiAuthException: No authorization token found on the request |
| /api/chapters/{id} | PUT | unauthenticated | not_measured | - | - | unknown | not_comparable | BookStack\Exceptions\ApiAuthException: No authorization token found on the request |
| /api/imports/{id} | DELETE | unauthenticated | not_measured | - | - | unknown | not_comparable | BookStack\Exceptions\ApiAuthException: No authorization token found on the request |
| /api/imports/{id} | GET | unauthenticated | not_measured | - | - | unknown | not_comparable | BookStack\Exceptions\ApiAuthException: No authorization token found on the request |
| /api/imports/{id} | POST | unauthenticated | not_measured | - | - | unknown | not_comparable | BookStack\Exceptions\ApiAuthException: No authorization token found on the request |
| /api/pages/{id} | DELETE | unauthenticated | not_measured | - | - | unknown | not_comparable | BookStack\Exceptions\ApiAuthException: No authorization token found on the request |
| /api/pages/{id} | GET | unauthenticated | not_measured | - | - | unknown | not_comparable | BookStack\Exceptions\ApiAuthException: No authorization token found on the request |
| /api/pages/{id} | PUT | unauthenticated | not_measured | - | - | unknown | not_comparable | BookStack\Exceptions\ApiAuthException: No authorization token found on the request |
| /api/shelves/{id} | DELETE | unauthenticated | not_measured | - | - | unknown | not_comparable | BookStack\Exceptions\ApiAuthException: No authorization token found on the request |
| /api/shelves/{id} | GET | unauthenticated | not_measured | - | - | unknown | not_comparable | BookStack\Exceptions\ApiAuthException: No authorization token found on the request |
| /api/shelves/{id} | PUT | unauthenticated | not_measured | - | - | unknown | not_comparable | BookStack\Exceptions\ApiAuthException: No authorization token found on the request |
| /api/users | GET | unauthenticated | not_measured | - | - | unknown | not_comparable | BookStack\Exceptions\ApiAuthException: No authorization token found on the request |
| /api/users | POST | unauthenticated | not_measured | - | - | unknown | not_comparable | BookStack\Exceptions\ApiAuthException: No authorization token found on the request |
| /api/users/{id} | DELETE | unauthenticated | not_measured | - | - | unknown | not_comparable | BookStack\Exceptions\ApiAuthException: No authorization token found on the request |
| /api/users/{id} | GET | unauthenticated | not_measured | - | - | unknown | not_comparable | BookStack\Exceptions\ApiAuthException: No authorization token found on the request |
| /api/users/{id} | PUT | unauthenticated | not_measured | - | - | unknown | not_comparable | BookStack\Exceptions\ApiAuthException: No authorization token found on the request |
| /books/{bookSlug}/chapter/{chapterSlug} | DELETE | ok | not_measured | targeted:deletions | - | unknown | not_comparable | insufficient_points |
| /books/{bookSlug}/chapter/{chapterSlug} | GET | not_found | not_measured | targeted:entities | - | unknown | not_comparable | scale 0 (axis N): not_found |
| /books/{bookSlug}/chapter/{chapterSlug} | PUT | validation_422 | not_measured | targeted:references | - | unknown | not_comparable | scale 0 (axis N): The name field is required. \| fields: name |
| /books/{bookSlug}/chapter/{chapterSlug}/references | GET | ok | not_measured | targeted:references | - | unknown | not_comparable | insufficient_points |
| /books/{bookSlug}/copy | GET | not_found | not_measured | targeted:entities | - | unknown | not_comparable | scale 0 (axis N): not_found |
| /books/{bookSlug}/copy | POST | exception | not_measured | - | - | unknown | not_comparable | no_attempts |
| /books/{bookSlug}/draft/{pageId} | DELETE | ok | not_measured | targeted:deletions | - | unknown | not_comparable | insufficient_points |
| /books/{bookSlug}/draft/{pageId} | GET | ok | not_measured | targeted:page_revisions | - | unknown | not_comparable | insufficient_points |
| /books/{bookSlug}/draft/{pageId} | POST | validation_422 | not_measured | targeted:entities | - | unknown | not_comparable | scale 0 (axis N): The name field is required. \| fields: name |
| /books/{bookSlug}/export/zip | GET | not_found | not_measured | targeted:entities | - | unknown | not_comparable | scale 0 (axis N): not_found |
| /books/{bookSlug}/page/{pageSlug} | DELETE | ok | not_measured | targeted:deletions | - | unknown | not_comparable | insufficient_points |
| /books/{bookSlug}/page/{pageSlug} | GET | not_found | not_measured | targeted:entities | - | unknown | not_comparable | scale 0 (axis N): not_found |
| /books/{bookSlug}/page/{pageSlug} | PUT | validation_422 | not_measured | targeted:references | - | unknown | not_comparable | scale 0 (axis N): The name field is required. \| fields: name |
| /books/{bookSlug}/page/{pageSlug}/references | GET | ok | not_measured | targeted:references | - | unknown | not_comparable | insufficient_points |
| /books/{bookSlug}/page/{pageSlug}/revisions/{revId}/restore | PUT | ok | not_measured | targeted:references | - | unknown | not_comparable | insufficient_points |
| /books/{id} | DELETE | ok | not_measured | targeted:deletions | - | unknown | not_comparable | insufficient_points |
| /books/{slug} | GET | exception | not_measured | - | - | unknown | not_comparable | Illuminate\View\ViewException: SQLSTATE[HY000]: General error: 1390 Prepared statement contains too many placeholders (Connection: mysql, Host: measure-db, Port: 3306, Database: peak_sim_measure_cebbe |
| /books/{slug} | PUT | timeout | not_measured | - | - | unknown | not_comparable | PeakSim\Runtime\Run\RequestTimeout: the request ran longer than 600 seconds |
| /books/{slug}/references | GET | ok | not_measured | targeted:references | - | unknown | not_comparable | insufficient_points |
| /images/edit/{id} | GET | ok | not_measured | targeted:entities | - | unknown | not_comparable | insufficient_points |
| /import/{id} | DELETE | not_found | not_measured | targeted:imports | - | unknown | not_comparable | scale 0 (axis N): No query results for model [BookStack\Exports\Import] 1 |
| /import/{id} | GET | exception | not_measured | - | - | unknown | not_comparable | Illuminate\View\ViewException: Attempt to read property "name" on null (View: /app/resources/views/exports/parts/import-item.blade.php) (View: /app/resources/views/exports/parts/import-item.blade.php) |
| /import/{id} | POST | ok | not_measured | targeted:references | - | unknown | not_comparable | insufficient_points |
| /login/service/{socialDriver}/callback | GET | exception | not_measured | - | - | unknown | not_comparable | exception |
| /oidc/callback | GET | ok | not_measured | targeted:images | - | unknown | not_comparable | insufficient_points |
| /register | GET | exception | not_measured | - | - | unknown | not_comparable | exception |
| /register | POST | exception | not_measured | - | - | unknown | not_comparable | exception |
| /settings/maintenance/cleanup-images | DELETE | ok | not_measured | targeted:images | - | unknown | not_comparable | insufficient_points |
| /settings/maintenance/regenerate-references | POST | ok | not_measured | targeted:entities | - | unknown | not_comparable | insufficient_points |
| /settings/recycle-bin/empty | POST | ok | not_measured | targeted:deletions | - | unknown | not_comparable | insufficient_points |
| /settings/sorting/rules/{id} | DELETE | not_found | not_measured | targeted:sort_rules | - | unknown | not_comparable | scale 0 (axis N): No query results for model [BookStack\Sorting\SortRule] 1 |
| /settings/sorting/rules/{id} | GET | not_found | not_measured | targeted:sort_rules | - | unknown | not_comparable | scale 0 (axis N): No query results for model [BookStack\Sorting\SortRule] 1 |
| /settings/sorting/rules/{id} | PUT | validation_422 | not_measured | targeted:sort_rules | - | unknown | not_comparable | scale 0 (axis N): The name field is required. (and 1 more error) \| fields: name, sequence |
| /settings/users/create | GET | ok | not_measured | targeted:roles | - | unknown | not_comparable | insufficient_points |
| /settings/users/create | POST | validation_422 | not_measured | - | - | unknown | not_comparable | The email field is required. (and 2 more errors) \| fields: email, password, password-confirm |
| /settings/users/{id} | DELETE | exception | not_measured | - | - | unknown | not_comparable | exception |
| /settings/users/{id} | GET | ok | not_measured | targeted:roles | - | unknown | not_comparable | insufficient_points |
| /settings/users/{id} | PUT | ok | not_measured | targeted:users | - | unknown | not_comparable | insufficient_points |
| /shelves/{slug} | DELETE | ok | not_measured | targeted:deletions | - | unknown | not_comparable | insufficient_points |
| /shelves/{slug} | GET | not_found | not_measured | targeted:entities | - | unknown | not_comparable | scale 0 (axis N): not_found |
| /shelves/{slug} | PUT | validation_422 | not_measured | targeted:references | - | unknown | not_comparable | scale 0 (axis N): The name field is required. \| fields: name |
| /shelves/{slug}/references | GET | ok | not_measured | targeted:references | - | unknown | not_comparable | insufficient_points |

## Rows per route (cold attempt)

Two different numbers, not to be mixed up. **Returned rows** are the rows the statements gave back to the app. **Examined rows** are the rows the database looked at to answer them (the growth of the session's `Handler_read_*` counters, with the cost of reading the counters taken off); a statement that scans a table to return one row examined the whole table. `scale: value`, `-` where it could not be measured.

| route | method | returned rows | examined rows |
| --- | --- | --- | --- |
| /api/books/{id} | DELETE | - | - |
| /api/books/{id} | GET | - | - |
| /api/books/{id} | PUT | - | - |
| /api/books/{id}/export/zip | GET | - | - |
| /api/chapters/{id} | DELETE | - | - |
| /api/chapters/{id} | GET | - | - |
| /api/chapters/{id} | PUT | - | - |
| /api/imports/{id} | DELETE | - | - |
| /api/imports/{id} | GET | - | - |
| /api/imports/{id} | POST | - | - |
| /api/pages/{id} | DELETE | - | - |
| /api/pages/{id} | GET | - | - |
| /api/pages/{id} | PUT | - | - |
| /api/shelves/{id} | DELETE | - | - |
| /api/shelves/{id} | GET | - | - |
| /api/shelves/{id} | PUT | - | - |
| /api/users | GET | - | - |
| /api/users | POST | - | - |
| /api/users/{id} | DELETE | - | - |
| /api/users/{id} | GET | - | - |
| /api/users/{id} | PUT | - | - |
| /books/{bookSlug}/chapter/{chapterSlug} | DELETE | 0: 195, 300000: 300207 | 0: 211, 300000: 2550453 |
| /books/{bookSlug}/chapter/{chapterSlug} | GET | 300000: 540211 | 300000: 1980240 |
| /books/{bookSlug}/chapter/{chapterSlug} | PUT | 0: 199, 300000: 203 | 0: 223, 300000: 345232 |
| /books/{bookSlug}/chapter/{chapterSlug}/references | GET | 0: 194, 300000: 75201 | 0: 204, 300000: 105215 |
| /books/{bookSlug}/copy | GET | 300000: 192 | 300000: 198 |
| /books/{bookSlug}/copy | POST | - | - |
| /books/{bookSlug}/draft/{pageId} | DELETE | 0: 193, 300000: 300205 | 0: 206, 300000: 1560454 |
| /books/{bookSlug}/draft/{pageId} | GET | 0: 203, 300000: 203 | 0: 211, 300000: 211 |
| /books/{bookSlug}/draft/{pageId} | POST | 300000: 476 | 300000: 960544 |
| /books/{bookSlug}/export/zip | GET | 300000: 585192 | 300000: 2505196 |
| /books/{bookSlug}/page/{pageSlug} | DELETE | 0: 196, 300000: 300208 | 0: 213, 300000: 1560459 |
| /books/{bookSlug}/page/{pageSlug} | GET | 300000: 540214 | 300000: 1260237 |
| /books/{bookSlug}/page/{pageSlug} | PUT | 0: 203, 300000: 207 | 0: 248, 300000: 345257 |
| /books/{bookSlug}/page/{pageSlug}/references | GET | 0: 195, 300000: 75202 | 0: 207, 300000: 120219 |
| /books/{bookSlug}/page/{pageSlug}/revisions/{revId}/restore | PUT | 0: 201, 300000: 205 | 0: 237, 300000: 345246 |
| /books/{id} | DELETE | 0: 199, 300000: 300211 | 0: 221, 300000: 1560500 |
| /books/{slug} | GET | - | - |
| /books/{slug} | PUT | - | - |
| /books/{slug}/references | GET | 0: 192, 300000: 75199 | 0: 200, 300000: 105210 |
| /images/edit/{id} | GET | 0: 190, 300000: 190 | 0: 199, 300000: 199 |
| /import/{id} | DELETE | 300000: 187 | 300000: 194 |
| /import/{id} | GET | - | - |
| /import/{id} | POST | 0: 187, 300000: 187 | 0: 191, 300000: 191 |
| /login/service/{socialDriver}/callback | GET | - | - |
| /oidc/callback | GET | 0: 0, 300000: 0 | 0: 0, 300000: 0 |
| /register | GET | - | - |
| /register | POST | - | - |
| /settings/maintenance/cleanup-images | DELETE | 0: 186, 300000: 900186 | 0: 194, 300000: 49350494 |
| /settings/maintenance/regenerate-references | POST | 0: 186, 300000: 583386 | 0: 204, 300000: 544458853 |
| /settings/recycle-bin/empty | POST | 0: 186, 300000: 300198 | 0: 195, 300000: 2325466 |
| /settings/sorting/rules/{id} | DELETE | 300000: 189 | 300000: 197 |
| /settings/sorting/rules/{id} | GET | 300000: 190 | 300000: 196 |
| /settings/sorting/rules/{id} | PUT | 300000: 187 | 300000: 191 |
| /settings/users/create | GET | 0: 193, 300000: 300193 | 0: 202, 300000: 300202 |
| /settings/users/create | POST | - | - |
| /settings/users/{id} | DELETE | - | - |
| /settings/users/{id} | GET | 0: 198, 300000: 300198 | 0: 210, 300000: 300210 |
| /settings/users/{id} | PUT | 0: 187, 300000: 187 | 0: 193, 300000: 204 |
| /shelves/{slug} | DELETE | 0: 191, 300000: 300203 | 0: 204, 300000: 2550456 |
| /shelves/{slug} | GET | 300000: 210 | 300000: 375226 |
| /shelves/{slug} | PUT | 0: 195, 300000: 199 | 0: 223, 300000: 345232 |
| /shelves/{slug}/references | GET | 0: 192, 300000: 75199 | 0: 200, 300000: 105210 |
