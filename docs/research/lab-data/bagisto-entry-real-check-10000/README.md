# Bagisto lab: first real check of the entry, 10,000 rows (failed)

This is not a trial. It is the record of a real check of the lab entry: one request to each of the eight listed routes, with the writes watched. It is kept as it was taken.

The check failed. The write guard refused an insert into `notifications` (a table outside the allowed list) on the third route (an admin route), the latch was set, and the five routes after it answered 503. The cause was the Host of the lab requests, which differed from the Host of `APP_URL`. The repeat with the Hosts aligned passed: see `../bagisto-entry-recheck-10000/`.
