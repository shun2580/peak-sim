#!/usr/bin/env bash
# Tests of the Bagisto lab as a stack of its own, started for real (it needs Docker; the first run builds the PHP 8.4 image, which needs the
# internet once, as the BookStack lab's does). It is a test of the stack and of the guards, not of Bagisto: no request is sent to any route of the
# app, no Bagisto database is built, and no load is applied. The one database it makes is a made-up one of 2,999 rows' worth of nothing (three small
# tables and the tables of a pretend list), made here and dropped here, so that the write counters of the real server can be read.
#
#   poc/lab/tests/bagisto-stack.sh
#
# It refuses to start if a container of the Bagisto lab exists. It never touches the BookStack lab (project peak-lab): the containers, the
# network and the volume of that lab are listed before and after and must be the same. It removes no volume; the Bagisto data volume is kept.
#
# 1  the stack starts sealed: bin/up exits 0 and the closed-network check names each of the five containers of the Bagisto lab
# 2  nothing is shared: its containers, its network and its volume have names of their own; no container mounts the BookStack lab's volume;
#    the BookStack lab's containers, networks and volume are what they were before
# 3  the network is internal, every container is on it alone, and no container publishes a port (docker inspect)
# 4  the image is PHP 8.4 with the calendar extension Bagisto requires; the app's root is read-only
# 5  at the entry: a POST gets 405 without the app starting; a GET with no list of the tables that may be written is a 503 that says so, and the app
#    does not start (the lab can write nothing before the list is committed)
# 6  the guards on the real server (a made-up database): the N tables are the same after a scenario, a write to a table of the list passes, and a write
#    to an N table (also through a prepared statement), to a table that is not in the list, or in another schema, stops it
# 7  the database is stopped last (docker stop -t 180), the stack is taken down without -v, and the Bagisto data volume is still there
# No test is skipped: a precondition that is not met is a failure, not a skip.
set -uo pipefail
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
lab="$(cd "$here/.." && pwd)"
repo="$(cd "$lab/../.." && pwd)"
wb="$lab/bin/with-bagisto"

passed=0; failed=0; skipped=0
pass() { passed=$((passed + 1)); printf 'ok   %s\n' "$1"; }
fail() { failed=$((failed + 1)); printf 'FAIL %s\n' "$1"; }
check() { local label="$1"; shift; if "$@"; then pass "$label"; else fail "$label"; fi; }

project=peak-lab-bagisto
names=(peak-lab-bagisto-db peak-lab-bagisto-app peak-lab-bagisto-web peak-lab-bagisto-k6 peak-lab-bagisto-tools)
net=peak-lab-bagisto_lab-internal
vol=peak-lab-bagisto-dbdata
if [ -n "$(docker ps -a --filter "label=com.docker.compose.project=$project" --format '{{.Names}}')" ]; then
  echo "bagisto-stack tests: a container of the Bagisto lab exists; take it down first (poc/lab/bin/with-bagisto poc/lab/bin/down)" >&2
  exit 2
fi

work="$(mktemp -d)"; fakedb=""; pw=""
cleanup() {
  [ -z "$fakedb" ] || [ -z "$pw" ] || docker exec -e MYSQL_PWD="$pw" peak-lab-bagisto-db mysql -uroot -e "DROP DATABASE IF EXISTS \`$fakedb\`" >/dev/null 2>&1
  rm -rf "$work"
}
trap cleanup EXIT

bookstack_state() {  # what the BookStack lab is: its containers with their state and image, its networks, its volume
  { docker ps -a --filter "label=com.docker.compose.project=peak-lab" --format '{{.Names}} {{.Status}} {{.Image}}' | sed -E 's/(Exited \([0-9]+\)|Up|Created) .*(peak-lab-php:[0-9.]+)/\1 \2/' | sort
    docker network ls --filter "label=com.docker.compose.project=peak-lab" --format 'network {{.Name}}' | sort
    docker volume inspect peak-lab-dbdata --format 'volume {{.Name}} {{.Mountpoint}} {{.CreatedAt}}' 2>&1
  }
}
bookstack_state >"$work/bookstack.before"

echo "# before: $(date '+%F %T')"; free -g | sed -n 1,2p; df -h / | tail -1

# 1 -----------------------------------------------------------------------------------------------------------------------------
# The list of the tables that may be written is named but does not exist: the case 5 tries (a list that cannot be read is a refusal).
env PEAK_LAB_ALLOWED_WRITES_FILE=poc/lab/bagisto/no-such-list.txt "$wb" "$lab/bin/up" >"$work/up.out" 2>&1; rc=$?
tail -3 "$work/up.out"
check "1a the Bagisto stack starts: bin/up exits 0" test "$rc" -eq 0
check "1b the closed-network check reports OK" grep -q '^PREFLIGHT OK' "$work/up.out"
for c in "${names[@]}"; do check "1c the check looked at $c" grep -q "$c: no published port" "$work/up.out"; done
check "1d no FAIL line" bash -c '! grep -q "^FAIL" "$1"' _ "$work/up.out"
check "1e the check never names a container of the BookStack lab" bash -c '! grep -qE "peak-lab-(db|app|web|k6|tools)\b" "$1"' _ "$work/up.out"

# 2 -----------------------------------------------------------------------------------------------------------------------------
running="$(docker ps --filter "label=com.docker.compose.project=$project" --format '{{.Names}}' | sort | tr '\n' ' ')"
check "2a the five containers of the project are running, and only they ($running)" test "$running" = "$(printf '%s\n' "${names[@]}" | sort | tr '\n' ' ')"
check "2b the volume $vol exists" docker volume inspect "$vol" >/dev/null
mounts="$(for c in "${names[@]}"; do docker inspect -f '{{range .Mounts}}{{.Name}} {{end}}' "$c"; done | tr ' ' '\n' | sort -u | grep -v '^$')"
check "2c the only named volume any container mounts is $vol ($mounts)" test "$mounts" = "$vol"
check "2d no container mounts the BookStack lab's volume" bash -c '! echo "$1" | grep -qx peak-lab-dbdata' _ "$mounts"
bookstack_state >"$work/bookstack.during"
check "2e the BookStack lab's containers, networks and volume are what they were" diff -q "$work/bookstack.before" "$work/bookstack.during"

# 3 -----------------------------------------------------------------------------------------------------------------------------
echo "# docker network inspect $net:"
docker network inspect "$net" --format 'name={{.Name}} internal={{.Internal}} driver={{.Driver}} attachable={{.Attachable}} containers={{range .Containers}}{{.Name}} {{end}}'
check "3a the network is internal" test "$(docker network inspect "$net" --format '{{.Internal}}')" = true
check "3b the network holds the five containers and no other" test "$(docker network inspect "$net" --format '{{range .Containers}}{{.Name}} {{end}}' | tr ' ' '\n' | grep -v '^$' | sort | tr '\n' ' ')" = "$(printf '%s\n' "${names[@]}" | sort | tr '\n' ' ')"
for c in "${names[@]}"; do
  nets="$(docker inspect -f '{{range $k, $v := .NetworkSettings.Networks}}{{$k}} {{end}}' "$c")"
  ports="$(docker inspect -f '{{json .NetworkSettings.Ports}} {{json .HostConfig.PortBindings}}' "$c")"   # a port the image only exposes is "null": no host side
  echo "# $c: networks=[${nets% }] ports=$ports"
  check "3c $c is on the project's network alone" test "${nets% }" = "$net"
  check "3d $c publishes no port (every entry of NetworkSettings.Ports has no host side, and PortBindings is empty)" python3 -c '
import json, sys
exposed, bindings = (json.loads(x) for x in sys.argv[1].rsplit(" ", 1))
assert bindings in ({}, None), bindings
assert all(v is None for v in (exposed or {}).values()), exposed' "$ports"
  check "3e $c is not privileged and drops no more than the lab's compose says" bash -c '[ "$(docker inspect -f "{{.HostConfig.Privileged}}" "$1")" = false ]' _ "$c"
done
check "3f no other network of the Bagisto project exists" test "$(docker network ls --filter "label=com.docker.compose.project=$project" --format '{{.Name}}')" = "$net"

# 4 -----------------------------------------------------------------------------------------------------------------------------
phpv="$(docker exec peak-lab-bagisto-app php -r 'echo PHP_VERSION;')"
check "4a the app container runs PHP 8.4 or later ($phpv)" bash -c 'php_ver="$1"; [ "${php_ver%%.*}" -ge 8 ] && [ "$(echo "$php_ver" | cut -d. -f2)" -ge 4 ]' _ "$phpv"
check "4b it has the calendar extension" bash -c 'docker exec peak-lab-bagisto-app php -m | grep -qx calendar'
check "4c the tools container has the same PHP and the extension" bash -c 'docker exec peak-lab-bagisto-tools php -m | grep -qx calendar'
check "4d the app's root is read-only (a write to it fails)" bash -c '! docker exec peak-lab-bagisto-app sh -c "echo x > /lab-test-file" 2>/dev/null'
check "4e the app container cannot write into the repository or the app" bash -c '! docker exec peak-lab-bagisto-app sh -c "echo x > /peak-sim/lab-test-file" 2>/dev/null && ! docker exec peak-lab-bagisto-app sh -c "echo x > /app/lab-test-file" 2>/dev/null'
check "4f the database is mysql 8.4 and has its own cores (0-1)" bash -c '[ "$(docker inspect -f "{{.HostConfig.CpusetCpus}}" peak-lab-bagisto-db)" = 0-1 ] && docker exec peak-lab-bagisto-db mysqld --version | grep -q "8\.4"'

check "4g the app and the web server have 10 CPUs on cores 2-7,12-15 (spec 7.4.7), and the web server shares the app's cores" bash -c '
  for c in peak-lab-bagisto-app peak-lab-bagisto-web; do
    [ "$(docker inspect -f "{{.HostConfig.CpusetCpus}} {{.HostConfig.NanoCpus}}" "$c")" = "2-7,12-15 10000000000" ] || exit 1
  done'
check "4h k6 has 8-9 and the tools 10-11, so the app's 12-15 are used by no other container of this lab" bash -c '
  [ "$(docker inspect -f "{{.HostConfig.CpusetCpus}}" peak-lab-bagisto-k6)" = 8-9 ] && [ "$(docker inspect -f "{{.HostConfig.CpusetCpus}}" peak-lab-bagisto-tools)" = 10-11 ]'
check "4i the app container sees 10 CPUs' worth of quota in its own cgroup (cpu.max) and its allowed cores are 2-7,12-15" bash -c '
  [ "$(docker exec peak-lab-bagisto-app cat /sys/fs/cgroup/cpu.max | cut -d" " -f1)" -eq "$(( $(docker exec peak-lab-bagisto-app cat /sys/fs/cgroup/cpu.max | cut -d" " -f2) * 10 ))" ] &&
  [ "$(docker exec peak-lab-bagisto-app cat /sys/fs/cgroup/cpuset.cpus.effective)" = 2-7,12-15 ]'
check "4j php-fpm in the app container has a static pool of 16 workers (php-fpm -tt)" bash -c 'out="$(docker exec peak-lab-bagisto-app php-fpm -tt 2>&1)"; echo "$out" | grep -q "pm = static" && echo "$out" | grep -q "pm.max_children = 16"'

# 5 -----------------------------------------------------------------------------------------------------------------------------
status="$(docker exec peak-lab-bagisto-k6 sh -c 'wget -q -S -O /dev/null --post-data=x http://lab-web:8080/ 2>&1 | head -1')"
check "5a a POST at the entry gets 405 (got: ${status:-nothing})" bash -c 'echo "$1" | grep -q "405"' _ "$status"
docker exec peak-lab-bagisto-k6 sh -c 'wget -q -S -O /tmp/body http://lab-web:8080/ 2>/tmp/hdr; cat /tmp/hdr | head -1; cat /tmp/body' >"$work/get.out" 2>&1
check "5b a GET with no list of the tables that may be written is a 503 (got: $(head -1 "$work/get.out"))" grep -q '503' "$work/get.out"
check "5c ... and the entry's own log says why (the list cannot be used), not the app's" bash -c 'docker logs peak-lab-bagisto-app 2>&1 | grep -q "\"why\":\"the list of tables the lab may write cannot be used\""'
check "5d the app did not start for it: no latch file and no app log of a boot" bash -c '! docker exec peak-lab-bagisto-app test -e /scratch/peak-measure-lab/contaminated.flag'

# 6 -----------------------------------------------------------------------------------------------------------------------------
echo "# the guards on the real server, on a made-up database"
state="$("$wb" bash -c 'echo "$PEAK_LAB_STATE_DIR"')"
run_id="$(sed -n 's/^PEAK_MEASURE_RUN_ID=//p' "$state/state.env")"
pw="$(sed -n 's/^PEAK_MEASURE_DB_ROOT_PASSWORD=//p' "$state/state.env")"
scale=2999   # a count no real database of the lab has; below the counts already seen, so the guard reads it without a prediction
fakedb="peak_sim_measure_${run_id}_lab_${scale}"
sql() { docker exec -e MYSQL_PWD="$pw" peak-lab-bagisto-db mysql -uroot -N -B -e "$1"; }
sql "DROP DATABASE IF EXISTS \`$fakedb\`; CREATE DATABASE \`$fakedb\`; CREATE DATABASE IF NOT EXISTS peak_other_schema_test;
     USE \`$fakedb\`;
     CREATE TABLE products (id INT PRIMARY KEY AUTO_INCREMENT, name VARCHAR(40)); CREATE TABLE orders (id INT PRIMARY KEY AUTO_INCREMENT, n INT);
     CREATE TABLE customers (id INT PRIMARY KEY AUTO_INCREMENT, email VARCHAR(40));
     CREATE TABLE sessions (id INT PRIMARY KEY AUTO_INCREMENT, payload VARCHAR(40)); CREATE TABLE visits (id INT PRIMARY KEY AUTO_INCREMENT, n INT);
     CREATE TABLE views (id INT PRIMARY KEY AUTO_INCREMENT, views INT);
     INSERT INTO products (name) VALUES ('a'),('b'),('c'); INSERT INTO orders (n) VALUES (1),(2); INSERT INTO customers (email) VALUES ('x@example.test');
     INSERT INTO sessions (payload) VALUES ('s'); INSERT INTO visits (n) VALUES (1); INSERT INTO views (views) VALUES (1);
     CREATE TABLE peak_other_schema_test.t (id INT PRIMARY KEY AUTO_INCREMENT, v INT);" >"$work/mk.out" 2>&1
check "6a the made-up database $fakedb was made" test "$(sql "SELECT COUNT(*) FROM \`$fakedb\`.products")" = 3
mkdir -p "$work/repo/poc/lab/bagisto"
printf '# a made-up list for this test\nsessions\nvisits\n' >"$work/repo/poc/lab/bagisto/allowed.txt"
printf 'products\norders\ncustomers\n' >"$work/n-tables.txt"
ct() { PEAK_REPO_ROOT="$work/repo" PEAK_LAB_ALLOWED_WRITES_FILE=poc/lab/bagisto/allowed.txt PEAK_LAB_TABLES_FILE="$work/n-tables.txt" "$wb" "$lab/bin/check-tables" "$@" >"$work/ct.out" 2>"$work/ct.err"; }
base="$work/c/tables-before.tsv"
ct before --scale=$scale --baseline="$base"; rc=$?
check "6b before: the baseline of the three N tables and the server's counters is written (exit $rc)" test "$rc" -eq 0
check "6c it holds the three N tables with a count and a checksum" bash -c '[ "$(grep -vc "^write" "$1")" = 3 ] && grep -q "^products	3	[0-9]" "$1"' _ "$base"
check "6d it holds the server's counter row for the made-up database's tables" bash -c 'grep -q "^write	'"$fakedb"'	sessions	" "$1"' _ "$base"
ct after --scale=$scale --baseline="$base" --out="$work/c/after0.tsv"; rc=$?
check "6e after, nothing written: 0 (exit $rc)" test "$rc" -eq 0
sql "INSERT INTO \`$fakedb\`.sessions (payload) VALUES ('more'); UPDATE \`$fakedb\`.visits SET n = n + 1" >/dev/null
ct after --scale=$scale --baseline="$base" --out="$work/c/after1.tsv"; rc=$?
check "6f a write to sessions (insert) and to visits (update), both in the list: allowed (exit $rc)" test "$rc" -eq 0
ct step --scale=$scale --baseline="$base" --out="$work/c/step1.tsv"; rc=$?
check "6g the same read between two steps (counters only): allowed (exit $rc)" test "$rc" -eq 0
sql "UPDATE \`$fakedb\`.orders SET n = n + 1; UPDATE \`$fakedb\`.orders SET n = n - 1" >/dev/null   # the rows end as they were: only the counters moved
ct step --scale=$scale --baseline="$base" --out="$work/c/step2.tsv"; rc=$?
check "6h updates of an N table that leave every row as it was (count and checksum the same): stopped by the server's counter (exit $rc, wanted 16)" test "$rc" -eq 16
check "6i ... and the message names the table" grep -q 'orders' "$work/ct.err"
ct after --scale=$scale --baseline="$base" --out="$work/c/after2.tsv"; rc=$?
check "6j the end of the scenario says the same (exit $rc)" test "$rc" -eq 16
# a fresh baseline for each of the next cases
fresh() { rm -f "$work/c/b.tsv"; ct before --scale=$scale --baseline="$work/c/b.tsv"; }
fresh; sql "SET @s = 'UPDATE \`$fakedb\`.products SET name = ? WHERE id = 1'; PREPARE p FROM @s; SET @v = 'zz'; EXECUTE p USING @v; SET @v = 'a'; EXECUTE p USING @v; DEALLOCATE PREPARE p" >/dev/null 2>&1
ct after --scale=$scale --baseline="$work/c/b.tsv" --out="$work/c/a.tsv"; rc=$?
check "6k writes to an N table through a prepared statement that leave the rows as they were: stopped (exit $rc, wanted 16)" test "$rc" -eq 16
fresh; sql "UPDATE \`$fakedb\`.products SET name = 'changed' WHERE id = 2" >/dev/null
ct after --scale=$scale --baseline="$work/c/b.tsv" --out="$work/c/a.tsv"; rc=$?
check "6l a changed N table: stopped (exit $rc, wanted 16)" test "$rc" -eq 16
fresh; sql "UPDATE \`$fakedb\`.views SET views = views + 1" >/dev/null
ct after --scale=$scale --baseline="$work/c/b.tsv" --out="$work/c/a.tsv"; rc=$?
check "6m a write to views, which is not in this lab's list: stopped (exit $rc, wanted 16)" test "$rc" -eq 16
fresh; sql "INSERT INTO peak_other_schema_test.t (v) VALUES (1)" >/dev/null
ct after --scale=$scale --baseline="$work/c/b.tsv" --out="$work/c/a.tsv"; rc=$?
check "6n a write in another schema of the server: stopped (exit $rc, wanted 16)" test "$rc" -eq 16
ct allowed --scale=70000; rc=$?
check "6o a count above 30,000 is refused before any query (exit $rc, wanted 17): none has been seen for this lab" test "$rc" -eq 17
sql "DROP DATABASE IF EXISTS \`$fakedb\`; DROP DATABASE IF EXISTS peak_other_schema_test" >/dev/null 2>&1
check "6p the made-up databases are dropped" test "$(sql "SHOW DATABASES LIKE '${fakedb}'")" = ""
fakedb=""

# 7 -----------------------------------------------------------------------------------------------------------------------------
docker stop -t 180 peak-lab-bagisto-db >/dev/null 2>&1; rc=$?
check "7a the database is stopped with docker stop -t 180 (exit $rc)" test "$rc" -eq 0
check "7b the InnoDB log of that stop says it shut down cleanly" bash -c 'docker logs peak-lab-bagisto-db 2>&1 | tail -20 | grep -qi "Shutdown complete\|ready for shutdown\|Normal shutdown"'
"$wb" "$lab/bin/down" >"$work/down.out" 2>&1; rc=$?
check "7c down (no --purge, no -v) exits 0 (exit $rc)" test "$rc" -eq 0
check "7d no container of the Bagisto project is left" test -z "$(docker ps -a --filter "label=com.docker.compose.project=$project" --format '{{.Names}}')"
check "7e the Bagisto data volume is still there" docker volume inspect "$vol" >/dev/null
bookstack_state >"$work/bookstack.after"
check "7f the BookStack lab's containers, networks and volume are what they were at the start" diff -q "$work/bookstack.before" "$work/bookstack.after"

echo
echo "bagisto-stack tests: $passed passed, $failed failed, $skipped skipped"
echo "# after: $(date '+%F %T')"; free -g | sed -n 1,2p; df -h / | tail -1
[ "$failed" -eq 0 ] && [ "$skipped" -eq 0 ]
