# Restarting the lab and checking the databases (2026-11-15)

The lab's containers had been stopped by Docker Desktop going down on 2026-11-11 (the database exited with 137). Docker was running
again on 2026-11-15 and the lab was started with the usual steps. **No request other than the one limits probe below, no load, no
EXPLAIN, no ANALYZE and no CHECKSUM TABLE was run; the only statements run on the data of the six databases are the
`SELECT COUNT(*)` of the `counts-<scale>.tsv` files.** The 50,000 and 200,000-row databases got nothing but those counts.

## Start

| Step | Result |
| --- | --- |
| `poc/lab/bin/up` (2026-11-15 01:53:26 to 01:53:50) | exit 0, "lab is up and sealed", closed-network check passed for all five containers |
| `poc/lab/bin/use-db 10000` | exit 0, closed-network check passed; the app container reads scale 10000 |
| `poc/lab/bin/check-limits --require=limited` | exit 0 ("LIMITS OK (require limited)") |
| `git diff poc/lab/compose` | 0 lines (the lab runs on the committed configuration) |

The lab was left at 10,000 rows with PHP's `max_execution_time` at 30 seconds.

## The limits, as php-fpm and nginx hold them (not the CLI's `php -i`)

| Setting | Value | Read from |
| --- | --- | --- |
| `max_execution_time` | 30 | one request through php-fpm (`limits-ini-probe.json`, `ini_get`; the probe opens no database connection) |
| php-fpm `request_terminate_timeout` | 0s | `php-fpm -tt` (`limits-fpm-pool.txt`) |
| php-fpm pool | `pm = static`, `pm.max_children = 16` | the same output |
| nginx `fastcgi_read_timeout` | 120s | `nginx -T` (`limits-nginx.txt`) |

`limits-settings.json` is the reading `check-limits` itself recorded.

## Row counts

Every database exists, and every count equals the one recorded when it was built (the 10,000, 30,000, 50,000 and 70,000-row counts
in `../build-70k-50k/counts-<scale>.tsv`, the 200,000-row counts in `../build-200k/counts.tsv`): 12 tables at exactly the scale,
`joint_permissions` at 9 times the scale, `entity_container_data` at 0.6 times and `entity_page_data` at 0.4 times.

| Scale | Counted (2026-11-15, host clock) | Against the record |
| --- | --- | --- |
| 10,000 | 01:54 | equal |
| 30,000 | 01:54 | equal |
| 70,000 | 01:54 | equal |
| 100,000 | 01:54 | 3 tables (`entities`, `images`, `references`, 100,000 each) equal the record in `../diagnosis/per-request/100000/table-counts.json` and `../diagnosis/zip-limit/unlimited-100000/table-counts.json`; the other 12 tables are checked only against the multiples of the scale used at the other scales |
| 50,000 | 01:54 | equal |
| 200,000 | 01:54 | equal |

The exact time of each count is on the first line of its file. Nothing was rebuilt.

## Files

- `counts-<scale>.tsv`: the counts, in the format of the earlier records.
- `limits-settings.json`, `limits-ini-probe.json`, `limits-fpm-pool.txt`, `limits-nginx.txt`: the limits above.
