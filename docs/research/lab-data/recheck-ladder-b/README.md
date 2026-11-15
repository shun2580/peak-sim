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
