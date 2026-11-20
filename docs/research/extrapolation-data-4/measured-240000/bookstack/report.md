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
| not_found | 9 |
| ok | 20 |
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
| /books/{slug} | GET | exception | not_measured | - | - | unknown | not_comparable | Illuminate\View\ViewException: SQLSTATE[HY000]: General error: 1390 Prepared statement contains too many placeholders (Connection: mysql, Host: measure-db, Port: 3306, Database: peak_sim_measure_c07f4 |
| /books/{slug} | PUT | not_found | not_measured | targeted:entities | - | unknown | not_comparable | scale 0 (axis N): not_found |
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
| /books/{bookSlug}/chapter/{chapterSlug} | DELETE | 0: 195, 240000: 240207 | 0: 211, 240000: 1380503 |
| /books/{bookSlug}/chapter/{chapterSlug} | GET | 240000: 432211 | 240000: 1584240 |
| /books/{bookSlug}/chapter/{chapterSlug} | PUT | 0: 199, 240000: 203 | 0: 223, 240000: 276232 |
| /books/{bookSlug}/chapter/{chapterSlug}/references | GET | 0: 194, 240000: 60201 | 0: 204, 240000: 84215 |
| /books/{bookSlug}/copy | GET | 240000: 192 | 240000: 198 |
| /books/{bookSlug}/copy | POST | - | - |
| /books/{bookSlug}/draft/{pageId} | DELETE | 0: 193, 240000: 240205 | 0: 206, 240000: 1248454 |
| /books/{bookSlug}/draft/{pageId} | GET | 0: 203, 240000: 203 | 0: 211, 240000: 211 |
| /books/{bookSlug}/draft/{pageId} | POST | 240000: 476 | 240000: 768544 |
| /books/{bookSlug}/export/zip | GET | 240000: 468192 | 240000: 2004196 |
| /books/{bookSlug}/page/{pageSlug} | DELETE | 0: 196, 240000: 240208 | 0: 213, 240000: 1248459 |
| /books/{bookSlug}/page/{pageSlug} | GET | 240000: 432214 | 240000: 1008237 |
| /books/{bookSlug}/page/{pageSlug} | PUT | 0: 203, 240000: 207 | 0: 248, 240000: 276257 |
| /books/{bookSlug}/page/{pageSlug}/references | GET | 0: 195, 240000: 60202 | 0: 207, 240000: 96218 |
| /books/{bookSlug}/page/{pageSlug}/revisions/{revId}/restore | PUT | 0: 201, 240000: 205 | 0: 237, 240000: 276246 |
| /books/{id} | DELETE | 0: 199, 240000: 240211 | 0: 221, 240000: 1428503 |
| /books/{slug} | GET | - | - |
| /books/{slug} | PUT | 240000: 1320187 | 240000: 3588232 |
| /books/{slug}/references | GET | 0: 192, 240000: 60199 | 0: 200, 240000: 84210 |
| /images/edit/{id} | GET | 0: 190, 240000: 190 | 0: 199, 240000: 199 |
| /import/{id} | DELETE | 240000: 187 | 240000: 194 |
| /import/{id} | GET | - | - |
| /import/{id} | POST | 0: 187, 240000: 187 | 0: 191, 240000: 191 |
| /login/service/{socialDriver}/callback | GET | - | - |
| /oidc/callback | GET | 0: 0, 240000: 0 | 0: 0, 240000: 0 |
| /register | GET | - | - |
| /register | POST | - | - |
| /settings/maintenance/cleanup-images | DELETE | 0: 186, 240000: 720186 | 0: 194, 240000: 32280434 |
| /settings/maintenance/regenerate-references | POST | 0: 186, 240000: 480186 | 0: 204, 240000: 370678285 |
| /settings/recycle-bin/empty | POST | 0: 186, 240000: 240198 | 0: 195, 240000: 1248438 |
| /settings/sorting/rules/{id} | DELETE | 240000: 189 | 240000: 197 |
| /settings/sorting/rules/{id} | GET | 240000: 190 | 240000: 196 |
| /settings/sorting/rules/{id} | PUT | 240000: 187 | 240000: 191 |
| /settings/users/create | GET | 0: 193, 240000: 240193 | 0: 202, 240000: 240202 |
| /settings/users/create | POST | - | - |
| /settings/users/{id} | DELETE | - | - |
| /settings/users/{id} | GET | 0: 198, 240000: 240198 | 0: 210, 240000: 240210 |
| /settings/users/{id} | PUT | 0: 187, 240000: 187 | 0: 193, 240000: 204 |
| /shelves/{slug} | DELETE | 0: 191, 240000: 240203 | 0: 204, 240000: 1248451 |
| /shelves/{slug} | GET | 240000: 210 | 240000: 236 |
| /shelves/{slug} | PUT | 0: 195, 240000: 199 | 0: 223, 240000: 276232 |
| /shelves/{slug}/references | GET | 0: 192, 240000: 60199 | 0: 200, 240000: 84210 |
