-- Two SELECT grants, for the test that checks the "rows examined" figure against the server's own count
-- (tests/Docker/RowsExaminedMatchesServerTest.php). Nothing wider is granted: no other performance_schema or sys table,
-- no PROCESS, no SUPER. See docs/decisions.md for the record.
GRANT SELECT ON performance_schema.events_statements_history_long TO 'measure'@'%';
GRANT SELECT ON performance_schema.threads TO 'measure'@'%';
