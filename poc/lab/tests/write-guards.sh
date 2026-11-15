#!/usr/bin/env bash
# Tests of the lab's write guards (decisions.md D187, D189): the lab may write the view-count table `views` and nothing else, and (i) the record of the
# statements is checked before a measurement, (iii) the N tables are counted and checksummed before and after a scenario (a ladder: at its start and its end,
# and between its steps only the server's counters of writes are compared), and a count of rows that has not been seen is refused. They never touch the lab: the `docker` they put on PATH is a stand-in that answers the lab's reads from files the test writes, hands the
# throw-away PHP containers (no network, no database) to the real docker, and records every other call. "Nothing was measured" is shown by the load
# tool (`k6`) never being called, and "no query was sent" by no COUNT or CHECKSUM reaching the stand-in. The records and tables are made up here.
#
#   poc/lab/tests/write-guards.sh
#
# A  bin/check-writes on made-up records: a record with writes to `views` only passes; a write to another table, a DDL statement, a multi-table
#    update, two statements in one text, a schema-qualified name, an empty text, an incomplete record, a route that was not captured and a route that is
#    missing are each refused with exit 15 and the message names the route and the statement type
# B  bin/check-tables (the N tables, and the server's own counters of writes: a prepared statement is counted, and so is a write that leaves every row as
#    it was): the baseline is written; a restart compares with it; a changed count, a changed checksum (the count the same) and an unreadable
#    table are found (16, 16, 18); `views` cannot be named; the refusal for counts not seen (17): 50,000 and 200,000 are refused with no prediction, with an
#    uncommitted one, with a committed one that holds no scenario of that scale, and with a committed one that does the scale is allowed; counts seen already are
#    allowed; every refusal sends no query. A counter row of zeros that appears (a table the app opened for the first time) is no write and does not stop
#    it, and taken out of a copy of the tool the check does stop on it; a new row with a write in it stops it. `step` reads only the counters: it sends
#    no COUNT and no CHECKSUM, finds a write to a table other than views, and does not see a checksum change (the end of the scenario does)
# C  the wiring in bin/measure-calibration and bin/measure-ladder, on the real scripts in a copy of the lab's bin directory:
#    each of the three guards stops a measurement before the load tool; each, taken out of a copy, no longer does; a lab whose N tables change under the
#    (stand-in) load is stopped before the run is summarized; a restart of a ladder scenario whose tables moved is stopped; a whole ladder (the tools that
#    summarize and record a step are made no-ops in a copy, so it runs to its end): COUNT and CHECKSUM are sent only at its start and its end, the
#    counters after every step, a write to another table stops it at that step, a changed checksum at the end, and each guard taken out of a copy no longer does; each marker is on one line,
#    in the right order; the files the guards write are outside .gitignore
# D  the memory guard (bin/check-oom and its place in bin/measure-ladder; 2026-11-16 ruling, D206 and D208): the app container's OOMKilled, RestartCount,
#    StartedAt and cgroup oom / oom_kill counters are saved at the start of a scenario and compared before and after every step; any change, or a reading that
#    cannot be made, stops it (19) at that step with no record. A worker killed by the kernel (the container does not exit) is seen by the cgroup counter alone.
#    Each of the two lines taken out of a copy no longer stops it; the markers are on one line each, in the right order
# E  the memory record (bin/read-memory and its three lines in bin/measure-ladder; D210): the machine's MemAvailable, the app container's memory.current / peak /
#    max and the oom counters of the app and database containers are written at a scenario's baseline, before every step and just after k6; a record only: a reading
#    that cannot be made is written as `unreadable` and the ladder goes on, a database container's oom_kill that moves does not stop it but shows in the record; the
#    memory limit in the lab's compose file is on the app container alone, the same for memory and swap, and can be set from the environment
# No test is skipped: a precondition that is not met is a failure, not a skip.
set -uo pipefail
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
lab="$(cd "$here/.." && pwd)"
repo_real="$(cd "$lab/../.." && pwd)"
php_image="${PEAK_DEV_IMAGE:-peak-sim-php-dev:8.3}"
real_docker="$(command -v docker)"

passed=0; failed=0; skipped=0
pass() { passed=$((passed + 1)); printf 'ok   %s\n' "$1"; }
fail() { failed=$((failed + 1)); printf 'FAIL %s\n' "$1"; }
expect_code() { if [ "$3" -eq "$2" ]; then pass "$1"; else fail "$1 (exit $3, wanted $2)"; fi; }
has() { grep -q -- "$2" "$1"; }
hasnt() { ! grep -q -- "$2" "$1"; }

[ -n "$real_docker" ] && "$real_docker" image inspect "$php_image" >/dev/null 2>&1 || { echo "write-guards tests: docker and the image $php_image are needed (to run the PHP tools)" >&2; exit 2; }
command -v python3 >/dev/null || { echo "write-guards tests: python3 is needed" >&2; exit 2; }

work="$(mktemp -d)"; trap 'rm -rf "$work"' EXIT
mkdir -p "$work/state" "$work/out" "$work/fakebin" "$work/s" "$work/lab/bin" "$work/lab/observe" "$work/plans" "$work/rec"
out="$(cd "$work/out" && pwd)"
data="$repo_real/docs/research/lab-data"
est="$data/estimate-r3/rule/100k"
mkrec() { python3 "$here/make-statement-record.py" "$lab/routes/route-list.json" "$work/rec/$1.json" --variant="$1"; }
for v in ok other-table ddl join-update two-statements schema-qualified empty incomplete not-captured missing-route prepared-insert prepared-delete prepared-update; do mkrec "$v"; done
good="$work/rec/ok.json"; bad="$work/rec/other-table.json"

# ---- A: check-writes ----------------------------------------------------------------------------------------------------------
cw() { "$lab/bin/check-writes" --statements="$1" >"$work/cw.out" 2>"$work/cw.err"; }
cw "$good"; expect_code "A1 writes to views only: passes" 0 $?
has "$work/cw.out" 'WRITE CHECK OK' && has "$work/cw.out" 'insert views in GET /shelves/{slug}' && has "$work/cw.out" 'update views in GET /books/{slug}' && pass "A1b it lists the writes it found" || fail "A1b ($(cat "$work/cw.out"))"
for v in other-table ddl join-update two-statements schema-qualified empty incomplete not-captured missing-route; do
  cw "$work/rec/$v.json"; expect_code "A2 $v: refused" 15 $?
  has "$work/cw.err" 'WRITE CHECK REFUSED' && has "$work/cw.err" 'GET /books/{slug}' && pass "A2b $v: the message names the route" || fail "A2b $v ($(cat "$work/cw.err"))"
done
for v in prepared-insert prepared-delete prepared-update; do
  cw "$work/rec/$v.json"; expect_code "A2d a write sent as a prepared statement ($v: placeholders and bindings) outside views: refused" 15 $?
  has "$work/cw.err" 'writes somewhere other than' && pass "A2e $v: the message says the write is outside views" || fail "A2e $v ($(cat "$work/cw.err"))"
done
cw "$bad"; has "$work/cw.err" 's000000093' && pass "A2c other-table: the message names the statement type" || fail "A2c ($(cat "$work/cw.err"))"
cw "$work/nothing.json"; expect_code "A3 a record that cannot be read: refused" 15 $?
"$lab/bin/check-writes" >/dev/null 2>&1; expect_code "A4 no argument: usage" 2 $?
"$lab/bin/check-writes" --statements="$good" --nonsense >/dev/null 2>&1; expect_code "A4b an unknown option: usage" 2 $?
# the record of the real capture (outside the repository; only if it is there: this is not a precondition of the tests)
real_capture="$repo_real/../peak-sim-trials/_out/cmd277_recapture2/bookstack/capture.json"
if [ -f "$real_capture" ]; then
  cw "$real_capture"; expect_code "A5 the real capture of the eight routes passes" 0 $?
else
  pass "A5 (the real capture is not on this machine; the made-up records above are what is tested)"
fi

# ---- the stand-in docker ------------------------------------------------------------------------------------------------------
# State of the pretend lab: files in $work/s; a missing file means the default (PHP 30, pool 0s, nginx 120s, scale 10000, rows 10000, checksum 111).
#   mysql_fail     every mysql call fails            k6_ok     the stand-in k6 succeeds (it writes the summary the load tool would)
#   k6_flip        ... and the load "writes a table": the checksum changes when k6 has run
#   k6_flip_io     ... and the load "updates a row of entities to the values it had": only the server's write counter of the table moves
#   k6_flip_views  ... and the load updates views (allowed)
#   k6_open        ... and the app opens a table (pages) for the first time: its counter row appears, all zeros (no write)
#   k6_open_write  ... and the app opens it and writes to it: the new row has an insert in it
#   io_entities / io_views / io_other   the server's counters of updates (entities, views) and of a write in another schema; io_empty: no counters
#   io_pages0 / io_pages1   a counter row for the table pages: all zeros / one insert
cat >"$work/fakebin/docker" <<FAKE
#!/usr/bin/env bash
w="$work"; s="\$w/s"
if [ "\$1" = run ] || [ "\$1" = image ]; then exec "$real_docker" "\$@"; fi
echo "\$*" >>"\$w/docker.calls"
val() { if [ -f "\$s/\$1" ]; then cat "\$s/\$1"; else echo "\$2"; fi; }
if [ "\$1" = inspect ]; then
  case "\$*" in
    *State.OOMKilled*) [ -f "\$s/inspect_fail" ] && exit 1; echo "\$(val oom_killed false) \$(val restart 0) \$(val started 2026-11-16T00:00:00Z)"; exit 0 ;;
    *) echo "PEAK_LAB_SCALE=\$(val scale 10000)"; exit 0 ;;
  esac
fi
if [ "\$1" = exec ]; then
  shift
  summary=""
  while [ "\${1:-}" = "-i" ] || [ "\${1:-}" = "-e" ]; do
    if [ "\$1" = "-e" ]; then case "\$2" in SUMMARY=*) summary="\${2#SUMMARY=}" ;; esac; shift 2; else shift; fi
  done
  c="\$1"; shift
  if [ "\$c" = peak-lab-db ] && [ "\$1" = mysql ]; then
    case "\$*" in
      *CHECKSUM*) [ -f "\$s/mysql_fail" ] && { echo "ERROR 1049 (42000): Unknown database" >&2; exit 1; }
                  printf '%s\n%s\t%s\n' "\$(val rows 10000)" "db.t" "\$(val checksum 111)"; exit 0 ;;
      *performance_schema*) [ -f "\$s/mysql_fail" ] && { echo "ERROR 1146 (42S02): Table 'performance_schema.table_io_waits_summary_by_table' doesn't exist" >&2; exit 1; }
                  [ -f "\$s/io_empty" ] && exit 0
                  d="\$(printf '%s' "\$*" | sed -n "s/.*object_schema = '\([^']*\)'.*/\1/p")"
                  printf '%s\tentities\t0\t%s\t0\n%s\timages\t0\t0\t0\n%s\treferences\t0\t0\t0\n%s\tviews\t1\t%s\t0\n' "\$d" "\$(val io_entities 0)" "\$d" "\$d" "\$d" "\$(val io_views 0)"
                  [ -f "\$s/io_other" ] && printf 'other_db\tt\t%s\t0\t0\n' "\$(cat "\$s/io_other")"
                  [ -f "\$s/io_pages0" ] && printf '%s\tpages\t0\t0\t0\n' "\$d"
                  [ -f "\$s/io_pages1" ] && printf '%s\tpages\t1\t0\t0\n' "\$d"
                  exit 0 ;;
    esac
  fi
  case "\$c \$1 \$2" in
    "peak-lab-web nginx -T")
      printf 'http {\n    server {\n        location / {\n            fastcgi_pass lab_app;\n            fastcgi_read_timeout %s;\n        }\n    }\n}\n' "\$(val nginx 120s)"; exit 0 ;;
    "peak-lab-app cat /sys/fs/cgroup/memory.events")
      [ -f "\$s/events_fail" ] && exit 1
      [ -f "\$s/events_bad" ] && { printf 'low 0\nhigh 0\nmax 0\n'; exit 0; }
      printf 'low 0\nhigh 0\nmax 0\noom %s\noom_kill %s\noom_group_kill 0\n' "\$(val cg_oom 0)" "\$(val cg_oom_kill 0)"; exit 0 ;;
    "peak-lab-app cat /sys/fs/cgroup/memory.current") [ -f "\$s/mem_fail" ] && exit 1; val mem_current 1073741824; exit 0 ;;
    "peak-lab-app cat /sys/fs/cgroup/memory.peak") [ -f "\$s/mem_fail" ] && exit 1; [ -f "\$s/mem_nopeak" ] && exit 1; val mem_peak 2147483648; exit 0 ;;
    "peak-lab-app cat /sys/fs/cgroup/memory.max") [ -f "\$s/mem_fail" ] && exit 1; val mem_max 6442450944; exit 0 ;;
    "peak-lab-db cat /sys/fs/cgroup/memory.events")
      [ -f "\$s/mem_fail" ] && exit 1
      printf 'low 0\nhigh 0\nmax 0\noom %s\noom_kill %s\noom_group_kill 0\n' "\$(val db_oom 0)" "\$(val db_oom_kill 0)"; exit 0 ;;
    "peak-lab-app php-fpm -tt")
      printf '[11-Nov-2026 10:00:00] NOTICE: \trequest_terminate_timeout = %s\n' "\$(val pool 0s)"; exit 0 ;;
    "peak-lab-db mysql -uroot")
      printf 'entities\t2026-11-11 05:17:04\t10000\t100\t50\n'; exit 0 ;;
  esac
  if [ "\$c" = peak-lab-tools ] && [ "\$1" = php ] && [ "\$2" = /peak-sim/poc/lab/diagnosis/fcgi_get.php ]; then
    printf '{"sapi":"fpm-fcgi","php":"8.3.36","max_execution_time":"%s","memory_limit":"%s"}\n' "\$(val php 30)" "\$(val mem 512M)"; exit 0
  fi
  if [ "\$c" = peak-lab-tools ] && [ "\$1" = php ]; then
    shift
    exec "$real_docker" run --rm -i --pull=never --network none --cpuset-cpus="${PEAK_DEV_CPUSET:-12-15}" -u "$(id -u):$(id -g)" -v "$repo_real:/peak-sim:ro" -v "$out:/out" "$php_image" nice php "\$@"
  fi
  if [ "\$c" = peak-lab-k6 ]; then
    echo "\$*" >>"\$w/k6.calls"
    if [ -f "\$s/k6_ok" ]; then
      echo '{}' >"$out/\${summary#/out/}"
      [ -f "\$s/k6_flip" ] && printf '222' >"\$s/checksum"
      [ -f "\$s/k6_flip_io" ] && printf '1' >"\$s/io_entities"     # a write that leaves every row as it was: the count and the checksum do not move
      [ -f "\$s/k6_flip_views" ] && printf '5' >"\$s/io_views"       # the one write that is allowed
      [ -f "\$s/k6_open" ] && : >"\$s/io_pages0"                      # a table opened for the first time: a row of zeros
      [ -f "\$s/k6_open_write" ] && : >"\$s/io_pages1"                # ... and written to
      [ -f "\$s/k6_oom" ] && { printf '1' >"\$s/cg_oom_kill"; printf '1' >"\$s/cg_oom"; }   # the kernel kills a php-fpm worker for memory: the container neither exits nor restarts
      [ -f "\$s/k6_dbkill" ] && { printf '1' >"\$s/db_oom"; printf '1' >"\$s/db_oom_kill"; }   # the kernel kills mysqld for memory: only the record shows it
      [ -f "\$s/k6_restart" ] && printf '1' >"\$s/restart"                                  # the container is restarted in place
      [ -f "\$s/k6_oomkilled" ] && printf 'true' >"\$s/oom_killed"
      exit 0
    fi
    exit 99
  fi
fi
exit 99
FAKE
chmod +x "$work/fakebin/docker"

for f in measure-calibration measure-ladder check-limits check-writes check-tables check-oom read-memory prediction-gate _lab.sh; do cp "$lab/bin/$f" "$work/lab/bin/$f"; done
mkdir -p "$work/lab/routes"; cp "$lab/routes/route-list.json" "$work/lab/routes/route-list.json"
ln -s "$lab/scenarios" "$work/lab/scenarios"
printf '#!/usr/bin/env bash\nexit 0\n' >"$work/lab/bin/preflight"; chmod +x "$work/lab/bin/preflight"
printf '#!/usr/bin/env bash\nexit 0\n' >"$work/lab/observe/sample-cpu"; chmod +x "$work/lab/observe/sample-cpu"
for scale in 3000 10000 30000 50000 200000; do echo '{}' >"$out/targets-$scale.json"; done

# a git repository for the prediction gate: a committed prediction that holds the scenarios of the re-check (their tables: 50,000 and 200,000), and a
# committed one that holds only seen scales
grepo="$work/repo"; mkdir -p "$grepo/poc/lab/predictions"
git -C "$grepo" init -q . && git -C "$grepo" config user.email t@example.invalid && git -C "$grepo" config user.name t
python3 - "$grepo/poc/lab/predictions" <<'PY'
import json, os, sys
d = sys.argv[1]
def scen(i, n): return {"id": i, "tables": {"entities": n, "images": n, "references": n}}
json.dump({"scenarios": [scen("r-1", 50000), scen("r-2", 50000), scen("r-3", 200000), scen("r-4", 200000), scen("r-5", 200000)]}, open(os.path.join(d, "recheck.json"), "w"))
json.dump({"scenarios": [scen("t-1", 30000), scen("t-2", 100000), scen("t-4", 10000)]}, open(os.path.join(d, "first.json"), "w"))
PY
git -C "$grepo" add -A && git -C "$grepo" commit -q -m "chore: seed"
pred="$grepo/poc/lab/predictions/recheck.json"; pred_first="$grepo/poc/lab/predictions/first.json"

reset_lab() { rm -f "$work"/s/*; : >"$work/docker.calls"; : >"$work/k6.calls"; rm -rf "$out/calibration" "$out/ladder" "$out/limits" "$out/trial"; }
set_lab() { reset_lab; local kv; for kv in "$@"; do printf '%s' "${kv#*=}" >"$work/s/${kv%%=*}"; done; }
env_run() { PATH="$work/fakebin:$PATH" PEAK_LAB_MEMINFO="$work/meminfo" PEAK_REPO_ROOT="$grepo" PEAK_LAB_STATE_DIR="$work/state" PEAK_LAB_OUT_DIR="$out" "$@"; }
k6_calls() { wc -l <"$work/k6.calls" | tr -d ' '; }
queries() { grep -c 'CHECKSUM\|COUNT(' "$work/docker.calls" | tr -d ' '; }
B=(php=0 pool=0s nginx=600s mem=4G)

# ---- B: check-tables ----------------------------------------------------------------------------------------------------------
ct() { env_run "$work/lab/bin/check-tables" "$@" >"$work/ct.out" 2>"$work/ct.err"; }
base="$work/b/tables-before.tsv"; aft="$work/b/tables-after.tsv"
set_lab; rm -rf "$work/b"
ct before --scale=10000 --baseline="$base"; expect_code "B1 before: the baseline is written" 0 $?
[ "$(grep -vc '^write' "$base" | tr -d ' ')" = 3 ] && has "$base" '^entities	10000	111$' && has "$base" '^images	' && has "$base" '^references	' && pass "B1b it holds a count and a checksum for entities, images and references" || fail "B1b ($(cat "$base" 2>/dev/null))"
! grep -v '^write' "$base" | grep -q 'views' && pass "B1c views (the one table the lab may write) is not among the N tables" || fail "B1c"
has "$base" '^write	[^	]*	entities	0	0	0$' && has "$base" '^write	[^	]*	views	1	0	0$' && pass "B1d it holds the server's write counters (inserts, updates, deletes) of every table, views included" || fail "B1d ($(cat "$base" 2>/dev/null))"
ct before --scale=10000 --baseline="$base"; expect_code "B2 a restart with the same tables: 0" 0 $?
has "$work/ct.out" 'TABLES SAME' && pass "B2b it says the tables are the same" || fail "B2b ($(cat "$work/ct.out"))"
printf '222' >"$work/s/checksum"
ct before --scale=10000 --baseline="$base"; expect_code "B3 a restart after a table's checksum changed (the count the same): 16" 16 $?
has "$work/ct.err" 'THE N TABLES CHANGED' && has "$work/ct.err" 'entities' && pass "B3b it names the table" || fail "B3b ($(cat "$work/ct.err"))"
rm -f "$work/s/checksum"; printf '10001' >"$work/s/rows"
ct before --scale=10000 --baseline="$base"; expect_code "B4 a restart after a count changed: 16" 16 $?
rm -f "$work/s/rows"
ct after --scale=10000 --baseline="$base" --out="$aft"; expect_code "B5 after, unchanged: 0" 0 $?
[ -s "$aft" ] && pass "B5b the reading after is kept" || fail "B5b"
printf '222' >"$work/s/checksum"
ct after --scale=10000 --baseline="$base" --out="$aft"; expect_code "B6 after, a checksum changed (rows the same): 16" 16 $?
rm -f "$work/s/checksum"; printf '9999' >"$work/s/rows"
ct after --scale=10000 --baseline="$base" --out="$aft"; expect_code "B6b after, a count changed: 16" 16 $?
rm -f "$work/s/rows"
ct after --scale=10000 --baseline="$work/b/none.tsv" --out="$aft"; expect_code "B7 after with no baseline: 18 (it cannot say)" 18 $?
: >"$work/s/mysql_fail"; rm -f "$work/b2/tables-before.tsv"
ct before --scale=10000 --baseline="$work/b2/tables-before.tsv"; expect_code "B8 a table that cannot be read: 18" 18 $?
[ ! -s "$work/b2/tables-before.tsv" ] && pass "B8b no half-written baseline is left" || fail "B8b a baseline was left"
ct after --scale=10000 --baseline="$base" --out="$aft"; expect_code "B8c after, unreadable: 18" 18 $?
rm -f "$work/s/mysql_fail"
ct before --scale=10000 --baseline="$work/b3/x.tsv" --tables=entities,views; expect_code "B9 views cannot be named among the checked tables: usage" 2 $?
ct before --scale=abc --baseline="$work/b3/x.tsv"; expect_code "B9b a scale that is not a number: usage" 2 $?
ct sideways --scale=10000; expect_code "B9c an unknown mode: usage" 2 $?

echo "# the server's own counters of writes (they see a prepared statement and a write that leaves every row as it was)"
set_lab; rm -rf "$work/bw"; bw="$work/bw/tables-before.tsv"
ct before --scale=10000 --baseline="$bw"; expect_code "B20 baseline with the write counters" 0 $?
printf '5' >"$work/s/io_views"
ct after --scale=10000 --baseline="$bw" --out="$work/bw/after.tsv"; expect_code "B21 views written (updates 0 -> 5): allowed, the one table the lab may write" 0 $?
rm -f "$work/s/io_views"
printf '1' >"$work/s/io_entities"
ct after --scale=10000 --baseline="$bw" --out="$work/bw/after.tsv"; expect_code "B22 an UPDATE of entities that leaves count and checksum as they were: 16 (only the counter moved)" 16 $?
has "$work/ct.err" 'entities' && has "$work/ct.err" 'other than views' && pass "B22b it names the table and says a table other than views was written" || fail "B22b ($(cat "$work/ct.err"))"
ct before --scale=10000 --baseline="$bw"; expect_code "B22c the same on a restart (before, with a baseline): 16" 16 $?
rm -f "$work/s/io_entities"; printf '3' >"$work/s/io_other"
ct before --scale=10000 --baseline="$bw"; expect_code "B23 a write in another schema (a table of a different database): 16" 16 $?
rm -f "$work/s/io_other"
: >"$work/s/io_empty"; rm -rf "$work/bw2"
ct before --scale=10000 --baseline="$work/bw2/tables-before.tsv"; expect_code "B24 a server that gives no counters for the measured schema: 18 (not read at all)" 18 $?
[ ! -s "$work/bw2/tables-before.tsv" ] && pass "B24b no baseline is left" || fail "B24b"
rm -f "$work/s/io_empty"; : >"$work/s/mysql_fail"; rm -rf "$work/bw3"
ct before --scale=10000 --baseline="$work/bw3/tables-before.tsv"; expect_code "B25 the counters table cannot be read: 18" 18 $?
rm -f "$work/s/mysql_fail"

echo "# a counter row of zeros is no write (the server lists only the tables it has opened)"
nozero="$work/lab/bin/check-tables-without-zero-rows"
sed '/\$4 == 0 && \$5 == 0 && \$6 == 0/d' "$work/lab/bin/check-tables" >"$nozero"; chmod +x "$nozero"
[ "$(diff "$work/lab/bin/check-tables" "$nozero" | grep -c '^<')" = 1 ] && pass "B26 the copy without the zero-row rule differs from the tool by that one line" || fail "B26 the copy differs by another number of lines"
set_lab; rm -rf "$work/bz"; bz="$work/bz/tables-before.tsv"
ct before --scale=10000 --baseline="$bz"; expect_code "B26b baseline" 0 $?
: >"$work/s/io_pages0"
ct after --scale=10000 --baseline="$bz" --out="$work/bz/after.tsv"; expect_code "B27 after: a new row of all zeros (a table opened for the first time): allowed" 0 $?
env_run "$nozero" after --scale=10000 --baseline="$bz" --out="$work/bz/after2.tsv" >"$work/ct.out" 2>"$work/ct.err"; expect_code "B27b WITHOUT the zero-row rule the same reading stops (16): the rule is what lets it pass" 16 $?
rm -f "$work/s/io_pages0"; rm -rf "$work/bz2"; bz2="$work/bz2/tables-before.tsv"
: >"$work/s/io_pages0"; ct before --scale=10000 --baseline="$bz2"; expect_code "B27c baseline taken with the zero row there" 0 $?
rm -f "$work/s/io_pages0"
ct after --scale=10000 --baseline="$bz2" --out="$work/bz2/after.tsv"; expect_code "B27d after: a row of zeros that was in the baseline is gone: allowed too (both readings are cleaned)" 0 $?
: >"$work/s/io_pages1"
ct after --scale=10000 --baseline="$bz" --out="$work/bz/after3.tsv"; expect_code "B28 after: a new row with an insert in it: 16" 16 $?
has "$work/ct.err" 'pages' && pass "B28b it names the table" || fail "B28b ($(cat "$work/ct.err"))"
ct step --scale=10000 --baseline="$bz" --out="$work/bz/step3.tsv"; expect_code "B28c step: a new row with an insert in it: 16" 16 $?
rm -f "$work/s/io_pages1"

echo "# step: the counters only (no table is read between the steps of a ladder)"
set_lab; rm -rf "$work/bs"; bs="$work/bs/tables-before.tsv"
ct before --scale=10000 --baseline="$bs"; expect_code "B30 baseline" 0 $?
: >"$work/docker.calls"
ct step --scale=10000 --baseline="$bs" --out="$work/bs/step.tsv"; expect_code "B31 step, nothing written: 0" 0 $?
[ "$(queries)" = 0 ] && pass "B31b step sent no COUNT and no CHECKSUM" || fail "B31b ($(grep 'CHECKSUM\|COUNT(' "$work/docker.calls" | head -2))"
[ "$(grep -c performance_schema "$work/docker.calls" | tr -d ' ')" = 1 ] && [ -s "$work/bs/step.tsv" ] && ! grep -qv '^write' "$work/bs/step.tsv" && pass "B31c it read the counters once and kept them (write lines only)" || fail "B31c"
has "$work/ct.out" 'WRITES SAME' && pass "B31d it says the writes are the same" || fail "B31d ($(cat "$work/ct.out"))"
printf '5' >"$work/s/io_views"
ct step --scale=10000 --baseline="$bs" --out="$work/bs/step.tsv"; expect_code "B32 step, views written: allowed" 0 $?
rm -f "$work/s/io_views"; printf '1' >"$work/s/io_entities"
ct step --scale=10000 --baseline="$bs" --out="$work/bs/step.tsv"; expect_code "B33 step, an UPDATE of entities: 16" 16 $?
has "$work/ct.err" 'entities' && has "$work/ct.err" 'other than views' && pass "B33b it names the table and says a table other than views was written" || fail "B33b ($(cat "$work/ct.err"))"
rm -f "$work/s/io_entities"; printf '3' >"$work/s/io_other"
ct step --scale=10000 --baseline="$bs" --out="$work/bs/step.tsv"; expect_code "B34 step, a write in another schema: 16" 16 $?
rm -f "$work/s/io_other"; printf '222' >"$work/s/checksum"
ct step --scale=10000 --baseline="$bs" --out="$work/bs/step.tsv"; expect_code "B35 step does not read tables: a changed checksum is not seen by it (the end of the scenario sees it)" 0 $?
ct after --scale=10000 --baseline="$bs" --out="$work/bs/after.tsv"; expect_code "B35b after sees the same changed checksum: 16" 16 $?
rm -f "$work/s/checksum"
ct step --scale=10000 --baseline="$work/bs/none.tsv" --out="$work/bs/step.tsv"; expect_code "B36 step with no baseline: 18" 18 $?
: >"$work/s/mysql_fail"
ct step --scale=10000 --baseline="$bs" --out="$work/bs/step.tsv"; expect_code "B36b step with the counters unreadable: 18" 18 $?
rm -f "$work/s/mysql_fail"; : >"$work/s/io_empty"
ct step --scale=10000 --baseline="$bs" --out="$work/bs/step.tsv"; expect_code "B36c step with no counters for the measured schema: 18" 18 $?
rm -f "$work/s/io_empty"
ct step --scale=10000 --baseline="$bs"; expect_code "B36d step without --out: usage" 2 $?
set_lab; ct step --scale=50000 --baseline="$work/bs/x.tsv" --out="$work/bs/y.tsv"; expect_code "B36e step at a scale not seen, no prediction: refused (17), no query" 17 $?
[ "$(queries)" = 0 ] && [ "$(grep -c performance_schema "$work/docker.calls" | tr -d ' ')" = 0 ] && pass "B36f the refusal sent nothing, not even the counters query" || fail "B36f"

echo "# the refusal for counts not seen"
for sc in 100 3000 30000 70000 100000; do
  set_lab; ct allowed --scale=$sc; expect_code "B10 scale $sc was seen already: allowed with no prediction" 0 $?
done
for sc in 50000 200000 60000 31000 1000000; do
  set_lab
  ct before --scale=$sc --baseline="$work/b4/$sc.tsv"; expect_code "B11 scale $sc, no prediction: refused" 17 $?
  [ "$(queries)" = 0 ] && [ ! -e "$work/b4/$sc.tsv" ] && pass "B11b scale $sc: no query was sent and no baseline made" || fail "B11b scale $sc: ($(cat "$work/docker.calls"))"
  has "$work/ct.err" 'REFUSED' && pass "B11c scale $sc: it says it refused" || fail "B11c ($(cat "$work/ct.err"))"
done
set_lab
echo '{"scenarios":[{"id":"r-1","tables":{"entities":50000,"images":50000,"references":50000}}]}' >"$grepo/poc/lab/predictions/draft.json"
ct before --scale=50000 --baseline="$work/b4/d.tsv" --prediction="$grepo/poc/lab/predictions/draft.json"; expect_code "B12 a prediction file that is not committed: refused" 17 $?
has "$work/ct.err" 'gate is closed' && [ "$(queries)" = 0 ] && pass "B12b it is the prediction gate that refused, and no query was sent" || fail "B12b ($(cat "$work/ct.err"))"
rm -f "$grepo/poc/lab/predictions/draft.json"
ct before --scale=50000 --baseline="$work/b4/f.tsv" --prediction="$pred_first"; expect_code "B13 a committed prediction that holds no scenario of 50,000: refused" 17 $?
has "$work/ct.err" 'no scenario of this scale' && [ "$(queries)" = 0 ] && pass "B13b it says why, and no query was sent" || fail "B13b ($(cat "$work/ct.err"))"
ct before --scale=50000 --baseline="$work/b4/ok50.tsv" --prediction="$pred"; expect_code "B14 a committed prediction that holds 50,000: allowed, and read" 0 $?
[ "$(queries)" -ge 3 ] && pass "B14b the tables were read" || fail "B14b"
ct before --scale=200000 --baseline="$work/b4/ok200.tsv" --prediction="$pred"; expect_code "B14c the same for 200,000" 0 $?
ct before --scale=60000 --baseline="$work/b4/ok60.tsv" --prediction="$pred"; expect_code "B15 a scale the committed prediction does not hold (60,000): refused" 17 $?
echo '{}' >"$grepo/poc/lab/predictions/stray.json"
ct before --scale=50000 --baseline="$work/b4/ok50b.tsv" --prediction="$pred"; expect_code "B16 an uncommitted second file in predictions/: refused (the gate's own rule)" 17 $?
rm -f "$grepo/poc/lab/predictions/stray.json"

# ---- C: the wiring ------------------------------------------------------------------------------------------------------------
# the markers: one line each, in the right place
for m in WRITE-CHECK TABLES-BEFORE TABLES-AFTER; do
  for f in measure-calibration measure-ladder; do
    n="$(grep -c "# $m\$" "$lab/bin/$f")"
    [ "$n" = 1 ] && pass "C1 $f has one line marked $m" || fail "C1 $f has $n lines marked $m"
  done
done
n="$(grep -c "# TABLES-STEP\$" "$lab/bin/measure-ladder")"; [ "$n" = 1 ] && pass "C1a measure-ladder has one line marked TABLES-STEP" || fail "C1a measure-ladder has $n lines marked TABLES-STEP"
[ "$(grep -c "# TABLES-STEP\$" "$lab/bin/measure-calibration")" = 0 ] && pass "C1a2 measure-calibration has no TABLES-STEP (one run, one before and one after)" || fail "C1a2"
line() { grep -n "$2" "$lab/bin/$1" | head -1 | cut -d: -f1; }
lw="$(line measure-ladder '# WRITE-CHECK$')"; lp="$(line measure-ladder 'bindir/preflight')"
lb="$(line measure-ladder '# TABLES-BEFORE$')"; lk="$(line measure-ladder 'peak-lab-k6 k6 run')"; lt="$(line measure-ladder '# TABLES-STEP$')"; la="$(line measure-ladder '# TABLES-AFTER$')"; ls_="$(line measure-ladder '^  php_tool poc/lab/load/summarize-run.php')"; lc="$(line measure-ladder 'ladder.php collect')"
[ "$lw" -lt "$lp" ] && pass "C1b ladder: the write check is before the closed-network check" || fail "C1b ladder ($lw, $lp)"
[ "$lb" -lt "$lk" ] && [ "$lk" -lt "$lt" ] && [ "$lt" -lt "$ls_" ] && pass "C1c ladder: tables before < the load tool < the step's counters < the summary of the step" || fail "C1c ladder ($lb $lk $lt $ls_)"
ld="$(awk 'NR>1 && /^done$/ {l=NR} END {print l}' "$lab/bin/measure-ladder")"
[ "$ld" -lt "$la" ] && [ "$la" -lt "$lc" ] && pass "C1c2 ladder: tables after (the end of the scenario) is after the loop and before the scenario's record is collected" || fail "C1c2 ladder ($ld $la $lc)"
cw_="$(line measure-calibration '# WRITE-CHECK$')"; cp_="$(line measure-calibration 'bindir/preflight')"
cb="$(line measure-calibration '# TABLES-BEFORE$')"; ck="$(line measure-calibration 'peak-lab-k6 k6 run')"; ca="$(line measure-calibration '# TABLES-AFTER$')"; cs="$(line measure-calibration '^  php_tool poc/lab/load/summarize-run.php')"
[ "$cw_" -lt "$cp_" ] && pass "C1d calibration: the write check is before the closed-network check" || fail "C1d calibration ($cw_, $cp_)"
[ "$cb" -lt "$ck" ] && [ "$ck" -lt "$ca" ] && [ "$ca" -lt "$cs" ] && pass "C1e calibration: tables before < the load tool < tables after < the summary" || fail "C1e calibration ($cb $ck $ca $cs)"

# the copy of a script with one guard's line (and nothing else) taken out; the line after it, which reads the guard's exit status, is left as it is
without() { sed "/# $2\$/d" "$lab/bin/$1" >"$work/lab/bin/$1-without-$2"; chmod +x "$work/lab/bin/$1-without-$2"; echo "$work/lab/bin/$1-without-$2"; }

# the five runs plus two of a different mix, as tests/calibration-gate.sh makes them, so that the calibration gate opens
d5="$data/calibration-limits-off"
python3 - "$work/plans" "$d5/cal-1/plan.json" <<'PY'
import json, os, sys
dest, base = sys.argv[1], json.load(open(sys.argv[2]))
routes = []
for r in base['routes']:
    v = 0.0 if r['route'] == 'GET /books/{bookSlug}/export/zip' else (0.8 * 2.0 if r['route'] == 'GET /books/{slug}' else 0.2 * 2.0 / 6)
    routes.append({'route': r['route'], 'rps': v})
json.dump({'mix': 'book80', 'rps': sum(x['rps'] for x in routes), 'duration_sec': 600, 'warmup_sec': 120, 'routes': routes}, open(os.path.join(dest, 'book80.json'), 'w'))
PY
runs=(); for n in 1 2 3 4 5; do runs+=(--run=cal-$n:$d5/cal-$n/plan.json); done
runs+=(--run=cal-6:"$work/plans/book80.json" --run=cal-7:"$work/plans/book80.json")
scales=(--run-scale=cal-4:3000 --run-scale=cal-5:30000 --run-scale=cal-6:30000 --run-scale=cal-7:30000)
mc_args=(--estimate="$est" "${runs[@]}" "${scales[@]}" --request-timeout=600s)
mc() { local script="$1"; shift; env_run "$script" "${mc_args[@]}" "$@" >"$work/mc.out" 2>"$work/mc.err"; }
mcr="$work/lab/bin/measure-calibration"

echo "# calibration"
set_lab "${B[@]}"
mc "$mcr" --measure=cal-1; expect_code "C2 a calibration with no --statement-record: usage" 2 $?
[ "$(k6_calls)" = 0 ] && [ "$(queries)" = 0 ] && pass "C2b nothing was touched" || fail "C2b"
set_lab "${B[@]}"
mc "$mcr" --measure=cal-1 --statement-record="$bad"; expect_code "C3 a record with a write outside views: 15" 15 $?
[ "$(k6_calls)" = 0 ] && [ "$(queries)" = 0 ] && ! grep -q 'peak-lab' "$work/docker.calls" && pass "C3b no container of the lab was touched, no load" || fail "C3b ($(head -3 "$work/docker.calls" | tr '\n' ' '))"
has "$work/mc.err" 'write check did not pass' && has "$work/mc.err" 'WRITE CHECK REFUSED' && pass "C3c it says so and names the statement" || fail "C3c ($(cat "$work/mc.err"))"
set_lab "${B[@]}"
mc "$(without measure-calibration WRITE-CHECK)" --measure=cal-1 --statement-record="$bad"
[ "$(k6_calls)" -ge 1 ] && pass "C3d WITHOUT the write-check line the same record reaches the load tool: the guard is what stopped it" || fail "C3d (rc $?, $(head -3 "$work/mc.err" | tr '\n' ' '))"
set_lab "${B[@]}"
mc "$mcr" --measure=cal-1 --statement-record="$good"; rc=$?
[ "$(k6_calls)" -ge 1 ] && pass "C4 a good record, tables unchanged: the run reaches the load tool" || fail "C4 (rc $rc, $(head -3 "$work/mc.err" | tr '\n' ' '))"
has "$work/mc.out" 'TABLES BASELINE scale 3000\|TABLES BASELINE scale 10000' && pass "C4b the baseline was taken before the run" || fail "C4b ($(cat "$work/mc.out" | tail -4 | tr '\n' ' '))"
[ -s "$out/calibration/cal-1/tables-before.tsv" ] && pass "C4c tables-before.tsv is in the run's directory" || fail "C4c"
# the load changes a table (the stand-in k6 succeeds and 'writes')
set_lab "${B[@]}" k6_ok=1 k6_flip=1
mc "$mcr" --measure=cal-1 --statement-record="$good"; rc=$?
expect_code "C5 the N tables change under the load: 16" 16 $rc
has "$work/mc.err" 'THE N TABLES CHANGED' && has "$work/mc.err" 'after the run' && pass "C5b it says the tables changed, after the run" || fail "C5b ($(cat "$work/mc.err"))"
[ ! -e "$out/calibration/cal-1/record.json" ] && [ ! -e "$out/calibration/cal-1/summarize.err" ] && pass "C5c the run was not summarized: no record" || fail "C5c a record or a summary was made"
[ -s "$out/calibration/cal-1/tables-after.tsv" ] && pass "C5d tables-after.tsv is kept" || fail "C5d"
set_lab "${B[@]}" k6_ok=1 k6_flip=1
mc "$(without measure-calibration TABLES-AFTER)" --measure=cal-1 --statement-record="$good"; rc=$?
[ "$rc" -ne 16 ] && has "$work/mc.err" 'summarize-run failed' && pass "C5e WITHOUT the tables-after line the same run goes on to the summary (exit $rc): the guard is what stopped it" || fail "C5e (rc $rc, $(head -3 "$work/mc.err" | tr '\n' ' '))"
set_lab "${B[@]}" k6_ok=1 k6_flip_io=1
mc "$mcr" --measure=cal-1 --statement-record="$good"; rc=$?
expect_code "C5g the load updates a row of entities to the values it had (no count or checksum moves): 16 from the server's counter" 16 $rc
[ ! -e "$out/calibration/cal-1/record.json" ] && has "$work/mc.err" 'other than views' && pass "C5h it says a table other than views was written, and no record was made" || fail "C5h ($(cat "$work/mc.err"))"
set_lab "${B[@]}" k6_ok=1 k6_flip_views=1
mc "$mcr" --measure=cal-1 --statement-record="$good"; rc=$?
has "$work/mc.err" 'summarize-run failed' && pass "C5i a write to views alone does not stop the run" || fail "C5i (rc $rc, $(head -3 "$work/mc.err" | tr '\n' ' '))"
set_lab "${B[@]}" k6_ok=1
mc "$mcr" --measure=cal-1 --statement-record="$good"; rc=$?
has "$work/mc.err" 'summarize-run failed' && has "$work/mc.out" 'TABLES SAME after the load' && pass "C5f the tables unchanged: the run goes on past the check to the summary" || fail "C5f (rc $rc, $(head -3 "$work/mc.err" | tr '\n' ' '))"
# the scale has not been seen
set_lab "${B[@]}" scale=50000
echo '{}' >"$out/targets-50000.json"
mc_unseen() { env_run "$1" --estimate="$est" "${runs[@]}" "${scales[@]}" --run-scale=cal-1:50000 --request-timeout=600s --measure=cal-1 --statement-record="$good" >"$work/mc.out" 2>"$work/mc.err"; }
mc_unseen "$mcr"; expect_code "C6 a calibration run at 50,000 rows: refused before any query (17)" 17 $?
[ "$(queries)" = 0 ] && [ "$(k6_calls)" = 0 ] && pass "C6b no query, no load" || fail "C6b"
set_lab "${B[@]}" scale=50000
mc_unseen "$(without measure-calibration TABLES-BEFORE)"
[ "$(k6_calls)" -ge 1 ] && pass "C6c WITHOUT the tables-before line the same run reaches the load tool: the guard is what refused" || fail "C6c ($(head -3 "$work/mc.err" | tr '\n' ' '))"

echo "# ladder"
ml() { local script="$1"; shift; env_run "$script" "$@" >"$work/ml.out" 2>"$work/ml.err"; }
mlr="$work/lab/bin/measure-ladder"
set_lab "${B[@]}" scale=50000
ml "$mlr" --scenario=r-1 --predictions="$pred" --scheme=B --request-timeout=600s; expect_code "C7 a ladder with no --statement-record: usage" 2 $?
[ "$(k6_calls)" = 0 ] && ! grep -q 'peak-lab' "$work/docker.calls" && pass "C7b nothing of the lab was touched" || fail "C7b"
set_lab "${B[@]}" scale=50000
ml "$mlr" --scenario=r-1 --predictions="$pred" --scheme=B --request-timeout=600s --statement-record="$bad"; expect_code "C8 a record with a write outside views: 15" 15 $?
[ "$(k6_calls)" = 0 ] && [ "$(queries)" = 0 ] && ! grep -q 'peak-lab' "$work/docker.calls" && pass "C8b no container touched, no load (the gate for the prediction was open)" || fail "C8b ($(head -3 "$work/docker.calls" | tr '\n' ' '))"
has "$work/ml.out" 'PREDICTION OK' && pass "C8c the prediction gate had passed, so it is the write check that stopped it" || fail "C8c"
set_lab "${B[@]}" scale=50000
ml "$(without measure-ladder WRITE-CHECK)" --scenario=r-1 --predictions="$pred" --scheme=B --request-timeout=600s --statement-record="$bad"
[ "$(k6_calls)" -ge 1 ] && pass "C8d WITHOUT the write-check line the same record reaches the load tool" || fail "C8d ($(head -3 "$work/ml.err" | tr '\n' ' '))"
set_lab "${B[@]}" scale=50000
ml "$mlr" --scenario=r-1 --predictions="$pred" --scheme=B --request-timeout=600s --statement-record="$good"; rc=$?
[ "$(k6_calls)" -ge 1 ] && expect_code "C9 a good record, a committed prediction holding 50,000: the ladder reaches the load tool (the stand-in fails it: 13)" 13 $rc || fail "C9 (rc $rc, $(head -3 "$work/ml.err" | tr '\n' ' '))"
[ -s "$out/ladder/r-1-50000/tables-before.tsv" ] && pass "C9b the baseline is kept in the scenario's directory" || fail "C9b"
# a count not seen, and the prediction does not hold it: refused before any query
set_lab "${B[@]}" scale=50000
ml "$mlr" --scenario=r-1 --predictions="$pred_first" --scheme=B --request-timeout=600s --statement-record="$good"; rc=$?
expect_code "C10 r-1 (50,000 rows) with a committed prediction that does not hold that scale: 17" 17 $rc
[ "$(queries)" = 0 ] && [ "$(k6_calls)" = 0 ] && pass "C10b no query was sent, no load" || fail "C10b ($(grep 'CHECKSUM\|COUNT(' "$work/docker.calls" | head -2))"
set_lab "${B[@]}" scale=50000
ml "$(without measure-ladder TABLES-BEFORE)" --scenario=r-1 --predictions="$pred_first" --scheme=B --request-timeout=600s --statement-record="$good"
[ "$(k6_calls)" -ge 1 ] && pass "C10c WITHOUT the tables-before line the same call reaches the load tool: the guard is what refused" || fail "C10c ($(head -3 "$work/ml.err" | tr '\n' ' '))"
# a seen scale needs no prediction scenario of its own (t-4 at 10,000 rows)
set_lab php=30 pool=0s nginx=120s scale=10000
ml "$mlr" --scenario=t-4 --predictions="$pred_first" --scheme=B --statement-record="$good"; rc=$?
[ "$(k6_calls)" -ge 1 ] && pass "C11 t-4 at 10,000 rows is not held back by the refusal for unseen counts" || fail "C11 (rc $rc, $(head -3 "$work/ml.err" | tr '\n' ' '))"
# a restart of a scenario whose tables moved in between
printf '222' >"$work/s/checksum"; : >"$work/k6.calls"
ml "$mlr" --scenario=t-4 --predictions="$pred_first" --scheme=B --statement-record="$good"; rc=$?
expect_code "C12 a restart of a scenario after a table changed since it began: 16" 16 $rc
[ "$(k6_calls)" = 0 ] && has "$work/ml.err" 'THE N TABLES CHANGED' && pass "C12b it stops before the load tool and names the change" || fail "C12b"
# after a step: only the server's counters are read, and the stand-in summary makes the real summarize-run fail (13) once the step has passed
set_lab php=30 pool=0s nginx=120s scale=10000 k6_ok=1 k6_flip=1
ml "$mlr" --scenario=t-4 --predictions="$pred_first" --scheme=B --statement-record="$good"; rc=$?
expect_code "C13 the checksum of an N table changes under the load of a step: the step does not read tables, so it goes on to the summary (13)" 13 $rc
has "$work/ml.err" 'summarize-run failed' && pass "C13b it was the summary that stopped it, not the table guard" || fail "C13b ($(cat "$work/ml.err"))"
[ "$(queries)" = 3 ] && pass "C13c COUNT and CHECKSUM were sent only at the start (3 tables), none after the step" || fail "C13c ($(queries) reads)"
[ -s "$(ls "$out"/ladder/t-4-10000/rung-01-*/writes-after.tsv | head -1)" ] && [ ! -e "$(ls -d "$out"/ladder/t-4-10000/rung-01-* | head -1)/tables-after.tsv" ] && pass "C13d writes-after.tsv (the counters) is kept in the step's directory, and no tables-after.tsv is made per step" || fail "C13d"
set_lab php=30 pool=0s nginx=120s scale=10000 k6_ok=1 k6_flip_io=1
ml "$mlr" --scenario=t-4 --predictions="$pred_first" --scheme=B --statement-record="$good"; rc=$?
expect_code "C13g the load updates a row of entities to the values it had: 16 from the server's counter, after the step" 16 $rc
has "$work/ml.err" 'after rung 1' && [ -z "$(ls "$out"/ladder/t-4-10000/rung-01-*/record.json 2>/dev/null)" ] && [ -z "$(ls "$out"/ladder/t-4-10000/rung-01-*/run.json 2>/dev/null)" ] && pass "C13g2 it says after which step, and the step was not summarized and has no record" || fail "C13g2 ($(cat "$work/ml.err"))"
set_lab php=30 pool=0s nginx=120s scale=10000 k6_ok=1 k6_flip_io=1
ml "$(without measure-ladder TABLES-STEP)" --scenario=t-4 --predictions="$pred_first" --scheme=B --statement-record="$good"; rc=$?
[ "$rc" -ne 16 ] && has "$work/ml.err" 'summarize-run failed' && pass "C13e WITHOUT the TABLES-STEP line the same step goes on to the summary (exit $rc): the guard is what stopped it" || fail "C13e (rc $rc, $(head -3 "$work/ml.err" | tr '\n' ' '))"
set_lab php=30 pool=0s nginx=120s scale=10000 k6_ok=1 k6_flip_views=1
ml "$mlr" --scenario=t-4 --predictions="$pred_first" --scheme=B --statement-record="$good"; rc=$?
has "$work/ml.err" 'summarize-run failed' && pass "C13h a write to views alone does not stop the step" || fail "C13h (rc $rc, $(head -3 "$work/ml.err" | tr '\n' ' '))"
set_lab php=30 pool=0s nginx=120s scale=10000 k6_ok=1 k6_open=1
ml "$mlr" --scenario=t-4 --predictions="$pred_first" --scheme=B --statement-record="$good"; rc=$?
has "$work/ml.err" 'summarize-run failed' && pass "C13i a table the app opens for the first time during the step (a new row of zeros) does not stop it" || fail "C13i (rc $rc, $(head -3 "$work/ml.err" | tr '\n' ' '))"
set_lab php=30 pool=0s nginx=120s scale=10000 k6_ok=1 k6_open_write=1
ml "$mlr" --scenario=t-4 --predictions="$pred_first" --scheme=B --statement-record="$good"; rc=$?
expect_code "C13j a table opened and written to during the step (a new row with an insert): 16" 16 $rc
set_lab php=30 pool=0s nginx=120s scale=10000 k6_ok=1
ml "$mlr" --scenario=t-4 --predictions="$pred_first" --scheme=B --statement-record="$good"; rc=$?
has "$work/ml.err" 'summarize-run failed' && has "$work/ml.out" 'WRITES SAME after a step' && pass "C13f unchanged counters: the step goes on past the check" || fail "C13f (rc $rc, $(head -3 "$work/ml.err" | tr '\n' ' '))"

# a whole ladder, to its end: a copy in which the three tools that summarize and record a step are no-ops (and the 5 s wait is gone), so that the loop
# runs through every step. The real scripts of the guards are the subject; the stand-in k6 writes an empty summary.
through() { sed -e 's|^  php_tool poc/lab/load/summarize-run.php|  true|' -e 's|^  php_tool poc/lab/load/ladder.php record|  true|' -e 's|^php_tool poc/lab/load/ladder.php collect|true|' -e 's|^  sleep 5 .*|  :|' "$1" >"$work/lab/bin/measure-ladder-through"; chmod +x "$work/lab/bin/measure-ladder-through"; echo "$work/lab/bin/measure-ladder-through"; }
n_ps() { grep -c performance_schema "$work/docker.calls" | tr -d ' '; }
set_lab php=30 pool=0s nginx=120s scale=10000 k6_ok=1
ml "$(through "$lab/bin/measure-ladder")" --scenario=t-4 --predictions="$pred_first" --scheme=B --statement-record="$good"; rc=$?
steps="$(k6_calls)"
[ "$rc" = 0 ] && [ "$steps" -ge 2 ] && pass "C16 a whole ladder of $steps steps runs to its end with the tables unchanged (exit 0)" || fail "C16 (rc $rc, $steps steps, $(head -3 "$work/ml.err" | tr '\n' ' '))"
[ "$(queries)" = 6 ] && pass "C16b COUNT and CHECKSUM were sent 6 times in all: 3 tables at the start and 3 at the end, none between the $steps steps" || fail "C16b ($(queries) reads)"
[ "$(n_ps)" = $((steps + 2)) ] && pass "C16c the counters were read at the start, after each of the $steps steps and at the end ($(n_ps) times)" || fail "C16c ($(n_ps) reads, $steps steps)"
[ -s "$out/ladder/t-4-10000/tables-after.tsv" ] && pass "C16d tables-after.tsv is kept in the scenario's directory" || fail "C16d"
set_lab php=30 pool=0s nginx=120s scale=10000 k6_ok=1 k6_flip=1
ml "$(through "$lab/bin/measure-ladder")" --scenario=t-4 --predictions="$pred_first" --scheme=B --statement-record="$good"; rc=$?
expect_code "C17 the checksum of an N table changed during the ladder: found at its end (16)" 16 $rc
[ "$(k6_calls)" = "$steps" ] && has "$work/ml.err" 'at its end' && pass "C17b every step was measured (the steps do not read tables), the end stopped it and says so" || fail "C17b ($(k6_calls) steps, $(cat "$work/ml.err"))"
set_lab php=30 pool=0s nginx=120s scale=10000 k6_ok=1 k6_flip=1
# (the line is replaced by `true`, not deleted: the status the next line reads would otherwise be that of the loop's last command)
sed 's|^"\$bindir/check-tables" after .*# TABLES-AFTER$|true|' "$lab/bin/measure-ladder" >"$work/lab/bin/measure-ladder-no-end"; chmod +x "$work/lab/bin/measure-ladder-no-end"
ml "$(through "$work/lab/bin/measure-ladder-no-end")" --scenario=t-4 --predictions="$pred_first" --scheme=B --statement-record="$good"; rc=$?
[ "$rc" = 0 ] && pass "C17c WITHOUT the TABLES-AFTER line the same ladder ends with exit 0: the guard at the end is what stopped it" || fail "C17c (rc $rc, $(head -3 "$work/ml.err" | tr '\n' ' '))"
set_lab php=30 pool=0s nginx=120s scale=10000 k6_ok=1 k6_flip_io=1
ml "$(through "$lab/bin/measure-ladder")" --scenario=t-4 --predictions="$pred_first" --scheme=B --statement-record="$good"; rc=$?
expect_code "C18 a write to entities during the ladder: stopped at the first step (16)" 16 $rc
[ "$(k6_calls)" = 1 ] && [ "$(queries)" = 3 ] && has "$work/ml.err" 'after rung 1' && pass "C18b it stopped after step 1: one load, and no table was read after the start" || fail "C18b ($(k6_calls) loads, $(queries) reads, $(cat "$work/ml.err"))"
set_lab php=30 pool=0s nginx=120s scale=10000 k6_ok=1 k6_open=1
ml "$(through "$lab/bin/measure-ladder")" --scenario=t-4 --predictions="$pred_first" --scheme=B --statement-record="$good"; rc=$?
[ "$rc" = 0 ] && pass "C19 a ladder in which the app opens a table for the first time at step 1 (a new row of zeros) runs to its end" || fail "C19 (rc $rc, $(cat "$work/ml.err" | head -3 | tr '\n' ' '))"

# ---- D: the memory guard (2026-11-16 ruling, D206 and D208): the app container must not have been stopped for memory ----------------------------------
echo "# memory guard"
co() { env_run "$work/lab/bin/check-oom" "$@" >"$work/co.out" 2>"$work/co.err"; }
base_oom="$work/oom-base.txt"
set_lab; co save "$base_oom"; expect_code "D1 save: the baseline is written" 0 $?
has "$base_oom" 'oom_killed=false restart_count=0 started_at=2026-11-16T00:00:00Z cgroup_oom=0 cgroup_oom_kill=0' && pass "D1b it holds OOMKilled, RestartCount, StartedAt and the cgroup's oom and oom_kill" || fail "D1b ($(cat "$base_oom"))"
co compare "$base_oom" --out="$work/oom-now.txt"; expect_code "D2 compare with nothing changed: 0" 0 $?
has "$work/co.out" 'OOM OK' && [ -s "$work/oom-now.txt" ] && pass "D2b it says OK and keeps the reading in --out" || fail "D2b"
for change in "cg_oom_kill=1|a worker killed by the kernel (the container neither exits nor restarts: only the cgroup counter shows it)" "cg_oom=1|an oom event of the cgroup" "restart=1|the container restarted" "oom_killed=true|State.OOMKilled" "started=2026-11-16T01:00:00Z|the container made again (another StartedAt)"; do
  kv="${change%%|*}"; why="${change#*|}"
  set_lab; printf '%s' "${kv#*=}" >"$work/s/${kv%%=*}"
  co compare "$base_oom" --where="rung 3 of the test"; expect_code "D3 $why: stopped (19)" 19 $?
  has "$work/co.err" 'STOPPED at rung 3 of the test' && pass "D3b ${kv%%=*}: the message says where it happened" || fail "D3b ${kv%%=*} ($(cat "$work/co.err"))"
done
for broken in inspect_fail events_fail events_bad; do
  set_lab; : >"$work/s/$broken"
  co compare "$base_oom"; expect_code "D4 $broken on compare: a reading that cannot be made stops it (19)" 19 $?
  co save "$work/oom-x.txt"; expect_code "D4b $broken on save: 19" 19 $?
done
set_lab; co compare "$work/no-such-baseline.txt"; expect_code "D5 compare with no baseline: 19" 19 $?
co; expect_code "D5b no argument: usage (2)" 2 $?
co save; expect_code "D5c save without a file: usage (2)" 2 $?
co compare "$base_oom" --nonsense; expect_code "D5d an unknown option: usage (2)" 2 $?
co save "$work/oom-y.txt" --out="$work/oom-z.txt"; expect_code "D5e --out with save: usage (2)" 2 $?

# the wiring in measure-ladder: one line for each marker, in the right order
for m in OOM-BASELINE OOM-BEFORE OOM-AFTER; do
  n="$(grep -c "# $m\$" "$lab/bin/measure-ladder")"
  [ "$n" = 1 ] && pass "D6 measure-ladder has one line marked $m" || fail "D6 measure-ladder has $n lines marked $m"
done
lob="$(line measure-ladder '# OOM-BASELINE$')"; lbf="$(line measure-ladder '# OOM-BEFORE$')"; laf="$(line measure-ladder '# OOM-AFTER$')"
lsd="$(line measure-ladder '^  read_settings "\$d"')"; lsp="$(line measure-ladder 'sample-cpu" --container=peak-lab-db')"
[ "$lob" -lt "$lsd" ] && [ "$lsd" -lt "$lbf" ] && [ "$lbf" -lt "$lsp" ] && [ "$lsp" -lt "$lk" ] && [ "$lk" -lt "$laf" ] && [ "$laf" -lt "$lt" ] && [ "$lt" -lt "$ls_" ] \
  && pass "D6b baseline < the limits of the step < OOM before < the samplers < the load tool < OOM after < the step's counters < the summary" || fail "D6b ($lob $lsd $lbf $lsp $lk $laf $lt $ls_)"
# a whole ladder, to its end (the same copy as C16: the tools that summarize and record a step are no-ops)
set_lab "${B[@]}" scale=50000 k6_ok=1
ml "$(through "$lab/bin/measure-ladder")" --scenario=r-1 --predictions="$pred" --scheme=B --request-timeout=600s --statement-record="$good"; rc=$?
steps="$(k6_calls)"
[ "$rc" = 0 ] && [ "$steps" -ge 2 ] && pass "D7 a whole B ladder of $steps steps, the app container untouched, runs to its end (exit 0)" || fail "D7 (rc $rc, $steps steps, $(head -3 "$work/ml.err" | tr '\n' ' '))"
[ -s "$out/ladder/r-1-50000/oom-baseline.txt" ] && [ -s "$(ls -d "$out"/ladder/r-1-50000/rung-01-* | head -1)/oom-before.txt" ] && [ -s "$(ls -d "$out"/ladder/r-1-50000/rung-01-* | head -1)/oom-after.txt" ] && pass "D7b the baseline and each step's readings before and after are kept" || fail "D7b"
[ -z "$(ls "$out"/ladder/r-1-50000/rung-*/oom-stop.txt 2>/dev/null)" ] && pass "D7c no oom-stop.txt when nothing happened" || fail "D7c"
for kill in k6_oom k6_restart k6_oomkilled; do
  set_lab "${B[@]}" scale=50000 k6_ok=1 "$kill=1"
  ml "$(through "$lab/bin/measure-ladder")" --scenario=r-1 --predictions="$pred" --scheme=B --request-timeout=600s --statement-record="$good"; rc=$?
  expect_code "D8 $kill during step 1: the ladder is stopped (19)" 19 $rc
  r1="$(ls -d "$out"/ladder/r-1-50000/rung-01-* | head -1)"
  [ "$(k6_calls)" = 1 ] && has "$work/ml.err" 'at rung 1' && has "$work/ml.err" 'not judged' && pass "D8b $kill: one load only, and the message says which step and that it is not judged" || fail "D8b $kill ($(k6_calls) loads, $(cat "$work/ml.err"))"
  [ -s "$r1/oom-stop.txt" ] && has "$r1/oom-stop.txt" 'rung 1' && pass "D8c $kill: oom-stop.txt in the step's directory says which step it was" || fail "D8c $kill"
  [ ! -e "$r1/record.json" ] && [ ! -e "$r1/writes-after.tsv" ] && pass "D8d $kill: no record, and the step went no further (no counters read after it)" || fail "D8d $kill"
done
# the guard in front of the step: something that happened between two steps (the container made again) stops the next one before its load
set_lab "${B[@]}" scale=50000 k6_ok=1 restart=1
# the stand-in's restart counter is already 1 when the tool takes its baseline, so the baseline holds 1 (what happened before the scenario began is not the scenario's)
ml "$(through "$lab/bin/measure-ladder")" --scenario=r-1 --predictions="$pred" --scheme=B --request-timeout=600s --statement-record="$good"; rc=$?
expect_code "D9 a container that was already restarted before the scenario began is the baseline, not a stop (exit 0)" 0 $rc
# WITHOUT both lines (after the step and in front of the next) the same kill goes unseen: the two guards are what stopped it
set_lab "${B[@]}" scale=50000 k6_ok=1 k6_oom=1
sed -e '/# OOM-AFTER$/d' -e '/# OOM-BEFORE$/d' "$lab/bin/measure-ladder" >"$work/lab/bin/ml-no-oom"; chmod +x "$work/lab/bin/ml-no-oom"
ml "$(through "$work/lab/bin/ml-no-oom")" --scenario=r-1 --predictions="$pred" --scheme=B --request-timeout=600s --statement-record="$good"; rc=$?
[ "$rc" = 0 ] && pass "D10 WITHOUT the OOM-BEFORE and OOM-AFTER lines the same worker kill goes through to the end (exit 0): the guards are what stopped it" || fail "D10 (rc $rc, $(head -3 "$work/ml.err" | tr '\n' ' '))"
# WITHOUT the line in front of the step, a change between two steps is not seen at the next one (step 1 killed, the after-check taken out: step 2 would start)
set_lab "${B[@]}" scale=50000 k6_ok=1 k6_oom=1
sed -e '/# OOM-AFTER$/d' "$lab/bin/measure-ladder" >"$work/lab/bin/ml-no-after"; chmod +x "$work/lab/bin/ml-no-after"
ml "$(through "$work/lab/bin/ml-no-after")" --scenario=r-1 --predictions="$pred" --scheme=B --request-timeout=600s --statement-record="$good"; rc=$?
expect_code "D11 with the after-check taken out, the check in front of the next step still stops the ladder (19)" 19 $rc
[ "$(k6_calls)" = 1 ] && has "$work/ml.err" 'before rung 2' && pass "D11b it stopped in front of rung 2, after one load: nothing was measured at rung 2" || fail "D11b ($(k6_calls) loads, $(cat "$work/ml.err"))"
set_lab "${B[@]}" scale=50000 k6_ok=1 k6_oom=1
ml "$(through "$(without measure-ladder OOM-BEFORE)")" --scenario=r-1 --predictions="$pred" --scheme=B --request-timeout=600s --statement-record="$good"; rc=$?
expect_code "D11c WITHOUT the OOM-BEFORE line the after-check still stops it at rung 1 (19)" 19 $rc
# a container that cannot be read when the scenario begins: nothing is measured
set_lab "${B[@]}" scale=50000 k6_ok=1 events_fail=1
ml "$(through "$lab/bin/measure-ladder")" --scenario=r-1 --predictions="$pred" --scheme=B --request-timeout=600s --statement-record="$good"; rc=$?
expect_code "D12 the app container's memory events cannot be read at the start: 19" 19 $rc
[ "$(k6_calls)" = 0 ] && pass "D12b no load was sent" || fail "D12b ($(k6_calls) loads)"
# t-* is read the same way (the guard is read-only; a kill during a t-4 step also stops it)
set_lab php=30 pool=0s nginx=120s scale=10000 k6_ok=1 k6_oom=1
ml "$(through "$lab/bin/measure-ladder")" --scenario=t-4 --predictions="$pred_first" --scheme=B --statement-record="$good"; rc=$?
expect_code "D13 a worker killed for memory during a t-4 step stops it too (19)" 19 $rc


# ---- E: the memory record (D210): a record only, it stops nothing --------------------------------------------------------------------------------
echo "# memory record"
printf 'MemTotal:       16318544 kB\nMemFree:         1000000 kB\nMemAvailable:    9000000 kB\nBuffers:           10000 kB\n' >"$work/meminfo"
rm=(env_run "$work/lab/bin/read-memory")
mrec="$work/mem-rec.txt"; rm -f "$mrec"
set_lab; "${rm[@]}" "$mrec" --label="trial one" >"$work/rm.out" 2>"$work/rm.err"; expect_code "E1 read-memory writes a record: 0" 0 $?
has "$mrec" 'label=trial_one mem_available_kib=9000000 app_memory_current=1073741824 app_memory_peak=2147483648 app_memory_max=6442450944 app_oom=0 app_oom_kill=0 db_oom=0 db_oom_kill=0' \
  && pass "E1b it holds the label, MemAvailable, the app's memory.current / peak / max and the oom counters of both containers" || fail "E1b ($(cat "$mrec"))"
has "$mrec" '^at=20[0-9][0-9]-[0-9][0-9]-[0-9][0-9]T' && pass "E1c it holds the time" || fail "E1c"
has "$work/rm.out" '^MEMORY ' && pass "E1d it prints the line too" || fail "E1d"
set_lab db_oom_kill=3 db_oom=3 mem_max=max; "${rm[@]}" "$mrec" --label=two >/dev/null 2>&1
[ "$(wc -l <"$mrec" | tr -d ' ')" = 2 ] && has "$mrec" 'app_memory_max=max app_oom=0 app_oom_kill=0 db_oom=3 db_oom_kill=3' && pass "E2 a second call appends, and the database container's oom_kill shows (3), the limit `max` as it is" || fail "E2 ($(cat "$mrec"))"
set_lab mem_fail=1 events_fail=1; "${rm[@]}" "$mrec" --label=three >/dev/null 2>&1; rc=$?
expect_code "E3 nothing about the containers can be read: still 0" 0 $rc
tail -n 1 "$mrec" | grep -q 'app_memory_current=unreadable app_memory_peak=unreadable app_memory_max=unreadable app_oom=unreadable app_oom_kill=unreadable db_oom=unreadable db_oom_kill=unreadable' && has "$mrec" 'label=three mem_available_kib=9000000' \
  && pass "E3b every reading of a container is written as unreadable (MemAvailable, read from the machine, is still there)" || fail "E3b ($(tail -n 1 "$mrec"))"
set_lab mem_nopeak=1; "${rm[@]}" "$mrec" --label=four >/dev/null 2>&1
tail -n 1 "$mrec" | grep -q 'app_memory_current=1073741824 app_memory_peak=unreadable' && pass "E3c a kernel with no memory.peak: only that reading is unreadable" || fail "E3c ($(tail -n 1 "$mrec"))"
set_lab; env_run env PEAK_LAB_MEMINFO="$work/no-such-meminfo" "$work/lab/bin/read-memory" "$mrec" --label=five >/dev/null 2>&1; rc=$?
[ "$rc" = 0 ] && tail -n 1 "$mrec" | grep -q 'mem_available_kib=unreadable' && pass "E3d no /proc/meminfo: unreadable, still 0" || fail "E3d (rc $rc, $(tail -n 1 "$mrec"))"
set_lab; "${rm[@]}" "$work/no-such-dir/rec.txt" >/dev/null 2>&1; expect_code "E3e a file that cannot be written: still 0 (it stops nothing)" 0 $?
"${rm[@]}"; expect_code "E4 no file: usage (2)" 2 $?
"${rm[@]}" "$mrec" --nonsense; expect_code "E4b an unknown option: usage (2)" 2 $?

# the wiring in measure-ladder: one line for each marker, in the right order
for m in MEM-BASELINE MEM-BEFORE MEM-AFTER; do
  n="$(grep -c "# $m\$" "$lab/bin/measure-ladder")"
  [ "$n" = 1 ] && pass "E5 measure-ladder has one line marked $m" || fail "E5 measure-ladder has $n lines marked $m"
done
mb="$(line measure-ladder '# MEM-BASELINE$')"; mbf="$(line measure-ladder '# MEM-BEFORE$')"; maf="$(line measure-ladder '# MEM-AFTER$')"
[ "$lob" -lt "$mb" ] && [ "$mb" -lt "$lsd" ] && [ "$lbf" -lt "$mbf" ] && [ "$mbf" -lt "$lsp" ] && [ "$lk" -lt "$maf" ] && [ "$maf" -lt "$laf" ] \
  && pass "E5b the baseline reading is after the OOM baseline, the one before a step is after the OOM check and before the samplers, the one after is after the load tool and before the OOM check" || fail "E5b ($lob $mb $lsd $lbf $mbf $lsp $lk $maf $laf)"
# a whole ladder with the stand-in: the records are kept
set_lab "${B[@]}" scale=50000 k6_ok=1
ml "$(through "$lab/bin/measure-ladder")" --scenario=r-1 --predictions="$pred" --scheme=B --request-timeout=600s --statement-record="$good"; rc=$?
sd="$out/ladder/r-1-50000"; r1="$(ls -d "$sd"/rung-01-* | head -1)"
[ "$rc" = 0 ] && [ -s "$sd/mem-baseline.txt" ] && [ -s "$r1/mem-before.txt" ] && [ -s "$r1/mem-after.txt" ] && pass "E6 a whole ladder runs to its end and keeps mem-baseline.txt and each step's mem-before.txt and mem-after.txt" || fail "E6 (rc $rc)"
n_steps="$(ls -d "$sd"/rung-* | wc -l | tr -d ' ')"; n_after="$(ls "$sd"/rung-*/mem-after.txt | wc -l | tr -d ' ')"; n_before="$(ls "$sd"/rung-*/mem-before.txt | wc -l | tr -d ' ')"
[ "$n_steps" = "$n_after" ] && [ "$n_steps" = "$n_before" ] && [ "$(wc -l <"$r1/mem-before.txt" | tr -d ' ')" = 1 ] && has "$r1/mem-before.txt" 'label=r-1_rung_1_before_the_step' && has "$r1/mem-after.txt" 'label=r-1_rung_1_just_after_k6' \
  && pass "E6b every one of the $n_steps steps has both, one line each, named by the step" || fail "E6b ($n_steps $n_before $n_after)"
# a record only: unreadable readings, and a database container that was killed, do not stop it
set_lab "${B[@]}" scale=50000 k6_ok=1 mem_fail=1
ml "$(through "$lab/bin/measure-ladder")" --scenario=r-1 --predictions="$pred" --scheme=B --request-timeout=600s --statement-record="$good"; rc=$?
[ "$rc" = 0 ] && has "$sd/mem-baseline.txt" 'db_oom_kill=unreadable' && pass "E7 with no reading of a container possible the ladder still runs to its end (0), the record says unreadable" || fail "E7 (rc $rc)"
set_lab "${B[@]}" scale=50000 k6_ok=1 k6_dbkill=1
ml "$(through "$lab/bin/measure-ladder")" --scenario=r-1 --predictions="$pred" --scheme=B --request-timeout=600s --statement-record="$good"; rc=$?
r1="$(ls -d "$sd"/rung-01-* | head -1)"
[ "$rc" = 0 ] && has "$r1/mem-before.txt" 'db_oom_kill=0' && has "$r1/mem-after.txt" 'db_oom_kill=1' && pass "E7b mysqld killed for memory during step 1: the ladder is not stopped by it (0), the step's records show db_oom_kill 0 then 1" || fail "E7b (rc $rc, $(cat "$r1/mem-after.txt"))"
# WITHOUT the lines nothing is written
for m in MEM-BASELINE MEM-BEFORE MEM-AFTER; do
  set_lab "${B[@]}" scale=50000 k6_ok=1
  ml "$(through "$(without measure-ladder $m)")" --scenario=r-1 --predictions="$pred" --scheme=B --request-timeout=600s --statement-record="$good"; rc=$?
  case $m in MEM-BASELINE) f="$sd/mem-baseline.txt" ;; MEM-BEFORE) f="$sd/rung-*/mem-before.txt" ;; MEM-AFTER) f="$sd/rung-*/mem-after.txt" ;; esac
  [ "$rc" = 0 ] && [ -z "$(ls $f 2>/dev/null)" ] && pass "E8 WITHOUT the $m line its file is not written: the line is what writes it" || fail "E8 $m (rc $rc)"
done
# the names can be committed
leaked="$(for nm in mem-baseline.txt mem-before.txt mem-after.txt; do git -C "$repo_real" check-ignore -q --no-index "docs/research/lab-data/ladder/t-4-10000/rung-01-1/$nm" && printf '%s ' "$nm"; done)"
[ -z "$leaked" ] && pass "E9 none of the names the memory record is written under is ignored by git" || fail "E9 names ignored by git: $leaked"
# the compose file: the limit is on the app container alone, the same for memory and swap, and the environment can change it
compose_cfg() { env -u PEAK_LAB_APP_MEM_LIMIT "$@" PEAK_REPO_ROOT="$repo_real" PEAK_APP_ROOT="$work" PEAK_LAB_OUT_DIR="$out" PEAK_LAB_LOAD_DIR="$lab/load" PEAK_MEASURE_DB_ROOT_PASSWORD=x PEAK_MEASURE_DB_PASSWORD=x PEAK_MEASURE_RUN_ID=x PEAK_MEASURE_APP_KEY=x \
  "$real_docker" compose -f "$lab/compose/lab.compose.yaml" config --format json 2>"$work/cfg.err"; }
limits_of() { python3 -c '
import json, sys
c = json.load(sys.stdin)["services"]
print(" ".join("%s=%s/%s" % (k, v.get("mem_limit", "-"), v.get("memswap_limit", "-")) for k, v in sorted(c.items())))'; }
got="$(compose_cfg env | limits_of)"
echo "$got" | grep -q 'lab-app=6442450944/6442450944' && ! echo "$got" | grep -E 'lab-(web|k6|tools)=[0-9]|measure-db=[0-9]' >/dev/null && pass "E10 the compose file gives lab-app 6 GiB for memory and for memory plus swap, and no other container a limit ($got)" || fail "E10 ($got $(head -2 "$work/cfg.err"))"
got="$(compose_cfg env PEAK_LAB_APP_MEM_LIMIT=3g | limits_of)"
echo "$got" | grep -q 'lab-app=3221225472/3221225472' && pass "E10b PEAK_LAB_APP_MEM_LIMIT=3g changes both" || fail "E10b ($got)"

# a dry run needs no record and touches nothing
set_lab
ml "$mlr" --scenario=t-4 --dry-run; rc=$?
[ "$rc" -ne 2 ] && [ "$rc" -ne 15 ] && pass "C14 a dry run needs no --statement-record (exit $rc)" || fail "C14 (rc $rc, $(head -3 "$work/ml.err" | tr '\n' ' '))"

# the names the guards write can all be committed (none is matched by .gitignore)
ign_dir="docs/research/lab-data/ladder/t-4-10000/rung-01-1"
names="writes-after.tsv writes-after.tsv.err writes-after.tsv.diff writes-after.tsv.expected writes-after.tsv.actual tables-before.tsv tables-before.tsv.restart tables-before.tsv.err tables-before.tsv.restart.err tables-before.tsv.restart.diff tables-before.tsv.restart.expected tables-before.tsv.restart.actual tables-after.tsv tables-after.tsv.err tables-after.tsv.diff tables-after.tsv.expected tables-after.tsv.actual"
leaked="$(for nm in $names; do git -C "$repo_real" check-ignore -q --no-index "$ign_dir/$nm" && printf '%s ' "$nm"; done)"
[ -z "$leaked" ] && pass "C15 none of the names the table guard writes is ignored by git" || fail "C15 names ignored by git: $leaked"

echo
echo "write guards tests: $passed passed, $failed failed, $skipped skipped"
[ "$failed" -eq 0 ] && [ "$skipped" -eq 0 ]
