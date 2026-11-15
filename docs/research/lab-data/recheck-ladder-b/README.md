# Records of B (limits off) of the re-check ladder, kept before the restart

Facts only; nothing here is compared with the prediction.

- `r-1-50000/`: scenario r-1 (50,000 rows), measured 2026-11-15T05:21:46+09:00 to 06:49:13+09:00, 9 steps, exit 0.
- `r-2-50000-interrupted-0713/`: scenario r-2 (50,000 rows), started 2026-11-15T06:49:29+09:00. Step 1 finished. Step 2 (started 07:04:55) was cut at 07:13 when the host stopped (the containers exited with 255); `measure-ladder` ended with exit 13 after k6 failed with exit 255. Kept apart from the r-2 that is measured again after the restart.
- `limits-before/`, `limits-after-open/`: the php-fpm side values before B and after the limits were opened (before the restart).
- `chain.log`, `chain-B.sh`, `r-1.output.txt`, `r-2.output.txt`: the chain and the output of each scenario.
- `stats-scenarios.tsv`, `stats-steps.tsv`: `mysql.innodb_table_stats` (last_update, n_rows) read before and after each scenario and after each step.
- The k6 logs of r-1 are kept as written.
