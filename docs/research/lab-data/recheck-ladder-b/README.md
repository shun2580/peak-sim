# Records of B (limits off) of the re-check ladder, kept before the restart

Facts only; nothing here is compared with the prediction.

- `r-1-50000/`: scenario r-1 (50,000 rows), measured 2026-11-15T05:21:46+09:00 to 06:49:13+09:00, 9 steps, exit 0.
- `r-2-50000-interrupted-0713/`: scenario r-2 (50,000 rows), started 2026-11-15T06:49:29+09:00. Step 1 finished. Step 2 (started 07:04:55) was cut at 07:13 when the host stopped (the containers exited with 255); `measure-ladder` ended with exit 13 after k6 failed with exit 255. Kept apart from the r-2 that is measured again after the restart.
- `limits-before/`, `limits-after-open/`: the php-fpm side values before B and after the limits were opened (before the restart).
- `chain.log`, `chain-B.sh`, `r-1.output.txt`, `r-2.output.txt`: the chain and the output of each scenario.
- `stats-scenarios.tsv`, `stats-steps.tsv`: `mysql.innodb_table_stats` (last_update, n_rows) read before and after each scenario and after each step.
- The k6 logs of r-1 are kept as written.

## After the restart (2026-11-15, host clock)

- `bin/up` 13:05:17 to 13:05:39 (exit 0, sealed), `bin/use-db 50000` 13:05:59 (exit 0). The working tree held the B settings of the compose files (not committed), so the containers came up with them.
- `after-restart/limits/`: the php-fpm side values read after the restart (`check-limits --require=unlimited`, exit 0): `max_execution_time` 0 (one request through php-fpm), `request_terminate_timeout` 0s, `pm = static` with `pm.max_children = 16`, nginx `fastcgi_read_timeout` 600s, k6 timeout 600s.
- `after-restart/old-before-r-2.txt` and `new.txt`: `mysql.innodb_table_stats` (table, last_update, n_rows) of the 50,000-row database before the interrupted r-2 and after the restart; `diff` gave no difference (41 tables; last_update 2026-11-11 05:17:06 in both).
- `after-restart/old-tables.txt` and `new-tables.txt`: count and CHECKSUM TABLE of the three N tables, before the interrupted r-2 and after the restart; `diff` gave no difference (took about 1 s for the 50,000-row database). Read through `bin/check-tables before` into a scratch baseline, not into the scenario's baseline.
- r-2 was started again from step 1 with `chain-B2.sh` (r-2 to r-5) at 13:06:37; the first step started 13:06:52. The write counters of the server restarted from zero, so the baseline of the new r-2 is its own, written at its start.

## The rest of B (r-2 again, r-3 to r-5; 2026-11-15, host clock)

- `chain-B2.sh` ran r-2 (13:06:37 to 14:26:51, exit 0), r-3 (14:26:51 to 15:22:51, exit 0), r-4 (15:22:52 to 15:58:54, exit 0, stopped after rung 3: rps_not_reached) and r-5 (15:58:54 to 17:19:14, exit 0, stopped after rung 8: db_utilization_at_or_above_80_percent and rps_not_reached). `chain.log` has the times.
- `r-2-50000/`, `r-3-200000/`, `r-4-200000/`, `r-5-200000/`: the ladder records as written. `r-2.output.txt` stays the output of the interrupted r-2 (as committed before); the new r-2 is `r-2-after-restart.output.txt`. `r-3.output.txt` to `r-5.output.txt` are the outputs of r-3 to r-5.
- `stats-scenarios-restart.tsv`, `stats-steps-restart.tsv`, `watch.seen.restart`: `mysql.innodb_table_stats` read before and after each scenario and after each step of the second chain. No ANALYZE was run by the measurement.
- `status500/`: read-only copies of the app-side logs (php-fpm stderr via `docker logs`, the Laravel log, the web server's docker log, the nginx access log lines that are neither 200 nor 302 by step window), taken after r-3 (snap2), after r-4 (snap3) and after r-5 (snap4); snap1 was taken during r-3. `r3-`, `r4-`, `r5-500-summary.txt` hold the counts. The copies were taken while the next scenario was already running, and no log was deleted. Each fall of the memory limit writes two lines (PHP and Laravel), so the number of falls is half the number of lines.
- `limits-after-b/`: the php-fpm side values read after r-5 and before the settings were put back (`check-limits --require=none`): `max_execution_time` 0, `request_terminate_timeout` 0s, nginx `fastcgi_read_timeout` 600s.
- No pass or fail is written here.
