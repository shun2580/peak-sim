-- The `measure` user (created by MYSQL_USER, with no privilege) may touch only databases named
-- peak_sim_measure_*. The underscores are escaped so they are literal, not LIKE wildcards.
-- Nothing else is granted: it cannot create, drop, or read any other database. The PHP container gets this user
-- only; the root password is never passed to it.
GRANT ALL PRIVILEGES ON `peak\_sim\_measure\_%`.* TO 'measure'@'%';
