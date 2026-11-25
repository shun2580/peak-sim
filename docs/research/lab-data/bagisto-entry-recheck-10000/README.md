# Bagisto lab: repeat of the real check of the entry, 10,000 rows (passed)

This is not a trial. It is the record of the repeat of the real check of the lab entry, taken after the lab requests were given the same Host as `APP_URL`. The first attempt failed (see `../bagisto-entry-real-check-10000/`: the write guard refused an insert into `notifications`). This time all eight routes answered 200 with the login the route list names, no write outside the allowed list was sent, the latch stayed unset, and the tables were the same before and after.

`statement-replay/` holds a one-off replay of the same steps with every statement logged from the start of the boot, used to compare the lab's statements with the capture's. `replay.php` is a scratch tool written for that; its paths are those inside the lab's containers. Differences in statement and row counts against the capture are recorded, not judged.
