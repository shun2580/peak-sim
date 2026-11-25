#!/usr/bin/env bash
# Tests of the lab's four write guards for an app whose lab may write other tables than BookStack's `views` (the Bagisto lab; docs/simulator-spec.md
# 7.4.7). The tables the lab may write are a list in a file (poc/runtime/src/LabEntry/AllowedWrites.php says its form); every test here uses a made-up
# list (`sessions`, `visits`) and made-up records, never a real route list and never a real database. They never touch a lab: the `docker` they put on
# PATH is a stand-in that answers the reads from files the test writes, and records every call; the PHP they run is a throw-away container (no network,
# no database). The stand-in database container is called peak-lab-bagisto-db, as in the Bagisto lab, and a call to any other name fails the test.
#
#   poc/lab/tests/allowed-writes.sh
#
# A  the three readers of the list (the entry's PHP class, bin/check-writes, bin/check-tables) read one file the same way: the same names from a good
#    file, and a refusal of every file in which a line is not a table name, a list that is named and cannot be read, and the default (`views` alone)
# B  guard (i), bin/check-writes: a record whose every write is to a table of the list passes; a write to any other table (an N table, `views` when it
#    is not in the list, another schema) is refused with exit 15 and the message names the route and the statement; a list that cannot be used is a
#    refusal, never a fall back to `views`; the list and the route list can be given by option or by the environment; the default is unchanged
# C  guards (iii) and (iv's counters), bin/check-tables, against the stand-in: the N tables come from a file; a write to a table of the list passes;
#    a write to an N table, to `views` (not in the list), or in another schema stops it (16); a table that is in both lists, or a list that cannot be
#    read, is a usage error (2); the counts seen so far are the environment's (above 30,000 none by default for this lab: 17 and no query); every query
#    goes to the container the environment names
# D  the stack's settings: with-bagisto gives names, a volume, a network, an image and a state directory of its own, none of them the BookStack lab's,
#    and refuses to be pointed at the BookStack lab's; the compose file rendered for the default environment is what it always was (the same container
#    names, volume and network), and for the Bagisto one it is sealed (an internal network, no published port) with its own names throughout
# G  the route list of the Bagisto lab (poc/lab/bagisto/make-route-list): made from the selection and the capture's tasks, the login of each route is
#    the guard the capture used, anything it cannot read is a stop, and the committed list is the committed selection
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

[ -n "$real_docker" ] && "$real_docker" image inspect "$php_image" >/dev/null 2>&1 || { echo "allowed-writes tests: docker and the image $php_image are needed (to run the PHP class)" >&2; exit 2; }
command -v python3 >/dev/null || { echo "allowed-writes tests: python3 is needed" >&2; exit 2; }

work="$(mktemp -d)"; trap 'rm -rf "$work"' EXIT
mkdir -p "$work/state" "$work/out" "$work/fakebin" "$work/s" "$work/repo/poc/lab/bagisto" "$work/lists"
# The lists are made inside a throw-away "repository" so that the relative path the environment gives (PEAK_LAB_ALLOWED_WRITES_FILE) is exercised.
repo="$work/repo"
good="poc/lab/bagisto/allowed.txt"
printf '# the tables the lab may write\n\nsessions\n  visits  # the visit record\r\nsessions\n' >"$repo/$good"
empty="poc/lab/bagisto/empty.txt";  printf '# nothing yet\n' >"$repo/$empty"
withviews="poc/lab/bagisto/withviews.txt"; printf 'views\nsessions\n' >"$repo/$withviews"
declare -A bad=( [schema]=$'sessions\nother_db.visits\n' [quoted]='`sessions`' [two]='sessions visits' [comma]='sessions,visits' [star]='*' [dash]='my-table' [inner-space]='my table' )
for k in "${!bad[@]}"; do printf '%s\n' "${bad[$k]}" >"$repo/poc/lab/bagisto/bad-$k.txt"; done
ntables="$work/lists/n-tables.txt"; printf '# the tables that grow with the count\nproducts\norders\ncustomers\n' >"$ntables"

# ---- A: the three readers ----------------------------------------------------------------------------------------------------
php_names() {  # php_names <file>: the names the PHP class reads, one a line; exit 1 when it refuses the file
  "$real_docker" run --rm --pull=never --network none -u "$(id -u):$(id -g)" -v "$repo_real:/peak-sim:ro" -v "$repo:/lists:ro" "$php_image" php -r '
    require "/peak-sim/poc/runtime/src/LabEntry/AllowedWrites.php";
    try { echo implode("\n", PeakSim\Runtime\LabEntry\AllowedWrites::fromFile($argv[1])), "\n"; } catch (InvalidArgumentException $e) { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); }' "/lists/$1" 2>/dev/null
}
py_names() {   # the names bin/check-writes reads
  python3 - "$lab/bin/check-writes" "$repo/$1" <<'PY' 2>/dev/null
import importlib.machinery, importlib.util, sys
loader = importlib.machinery.SourceFileLoader("check_writes", sys.argv[1])
spec = importlib.util.spec_from_loader("check_writes", loader)
mod = importlib.util.module_from_spec(spec); loader.exec_module(mod)
try:
    names = mod.allowed_tables(sys.argv[2])
except (OSError, ValueError):
    sys.exit(1)
print("\n".join(names))
PY
}
a="$(php_names "$good")"; b="$(py_names "$good")"
[ "$a" = $'sessions\nvisits' ] && pass "A1 the PHP class reads the good file: names once each, comments and blank lines and the CR dropped" || fail "A1 ($a)"
[ "$a" = "$b" ] && pass "A2 bin/check-writes reads the same names" || fail "A2 ($b)"
[ -z "$(php_names "$empty")" ] && [ -z "$(py_names "$empty")" ] && pass "A3 a file that names nothing gives no name in both" || fail "A3"
for k in "${!bad[@]}"; do
  php_names "poc/lab/bagisto/bad-$k.txt" >/dev/null; r1=$?; py_names "poc/lab/bagisto/bad-$k.txt" >/dev/null; r2=$?
  [ "$r1" = 1 ] && [ "$r2" = 1 ] && pass "A4 a file with a line that is not a table name ($k): both refuse it" || fail "A4 $k (php $r1, python $r2)"
done
php_names "poc/lab/bagisto/none.txt" >/dev/null; r1=$?; py_names "poc/lab/bagisto/none.txt" >/dev/null; r2=$?
[ "$r1" = 1 ] && [ "$r2" = 1 ] && pass "A5 a file that does not exist: both refuse it" || fail "A5 (php $r1, python $r2)"

# ---- the stand-in docker -------------------------------------------------------------------------------------------------------
# State of the pretend lab: files in $work/s. The one database container is peak-lab-bagisto-db; the call of any other name is recorded as `other`.
#   rows_<table>, sum_<table>   the count and the checksum of an N table (default 100 and 111)      mysql_fail   every mysql call fails
#   io   the lines `<table> <inserts> <updates> <deletes>` the server's counters hold for the measured schema (default: products 0 0 0, sessions 1 0 0, visits 1 0 0, views 0 0 0)
#   io_other   a write in another schema (the number of inserts)
cat >"$work/fakebin/docker" <<FAKE
#!/usr/bin/env bash
w="$work"; s="\$w/s"
if [ "\$1" = run ] || [ "\$1" = image ] || [ "\$1" = compose ]; then exec "$real_docker" "\$@"; fi
echo "\$*" >>"\$w/docker.calls"
val() { if [ -f "\$s/\$1" ]; then cat "\$s/\$1"; else echo "\$2"; fi; }
if [ "\$1" = exec ]; then
  shift
  while [ "\${1:-}" = "-i" ] || [ "\${1:-}" = "-e" ]; do if [ "\$1" = "-e" ]; then shift 2; else shift; fi; done
  c="\$1"; shift
  if [ "\$c" != peak-lab-bagisto-db ]; then echo "\$c" >>"\$w/other.calls"; exit 99; fi
  if [ "\$1" = mysql ]; then
    [ -f "\$s/mysql_fail" ] && { echo "ERROR 1049 (42000): Unknown database" >&2; exit 1; }
    case "\$*" in
      *CHECKSUM*)
        t="\$(printf '%s' "\$*" | sed -n 's/.*CHECKSUM TABLE \`[^\`]*\`\.\`\([^\`]*\)\`.*/\1/p')"
        printf '%s\n%s\t%s\n' "\$(val rows_\$t 100)" "db.\$t" "\$(val sum_\$t 111)"; exit 0 ;;
      *information_schema.tables*)
        [ -f "\$s/no_tables" ] && exit 0
        printf '%s\n' "\$(val tables_list 'products orders sessions')" | tr ' ' '\n'; exit 0 ;;
      *"ANALYZE TABLE"*)
        d="\$(printf '%s' "\$*" | sed -n 's/.*ANALYZE TABLE \`\([^\`]*\)\`\..*/\1/p')"
        for t in \$(printf '%s' "\$*" | grep -o '\`[a-z0-9_]*\`\.\`[a-z0-9_]*\`' | sed 's/.*\.\`\([a-z0-9_]*\)\`/\1/'); do
          if [ -f "\$s/analyze_bad" ] && [ "\$t" = orders ]; then printf '%s.%s\tanalyze\tNote\tTable does not support optimize\n' "\$d" "\$t"; else printf '%s.%s\tanalyze\tstatus\tOK\n' "\$d" "\$t"; fi
        done; exit 0 ;;
      *innodb_table_stats*) printf '100\tproducts\t2026-11-25 03:00:00\n'; exit 0 ;;
      *UTC_TIMESTAMP*) echo '2026-11-25 03:00:00'; exit 0 ;;
      *performance_schema*)
        d="\$(printf '%s' "\$*" | sed -n "s/.*object_schema = '\([^']*\)'.*/\1/p")"
        if [ -f "\$s/io" ]; then while read -r t i u x; do printf '%s\t%s\t%s\t%s\t%s\n' "\$d" "\$t" "\$i" "\$u" "\$x"; done <"\$s/io"
        else printf '%s\tproducts\t0\t0\t0\n%s\tsessions\t1\t0\t0\n%s\tvisits\t1\t0\t0\n%s\tviews\t0\t0\t0\n' "\$d" "\$d" "\$d" "\$d"; fi
        [ -f "\$s/io_other" ] && printf 'other_db\tt\t%s\t0\t0\n' "\$(cat "\$s/io_other")"
        exit 0 ;;
    esac
  fi
fi
exit 99
FAKE
chmod +x "$work/fakebin/docker"
reset_lab() { rm -f "$work"/s/*; : >"$work/docker.calls"; : >"$work/other.calls"; }
set_lab() { reset_lab; local kv; for kv in "$@"; do printf '%s' "${kv#*=}" >"$work/s/${kv%%=*}"; done; }
queries() { grep -c 'CHECKSUM\|COUNT(' "$work/docker.calls" | tr -d ' '; }

# ---- B: guard (i), check-writes --------------------------------------------------------------------------------------------------
python3 - "$work" <<'PY'
import json, os, sys
w = sys.argv[1]
ROUTES = ["GET /a/{id}", "GET /b/{id}", "GET /c"]
json.dump({"selected": [{"route": r} for r in ROUTES]}, open(os.path.join(w, "lists", "routes.json"), "w"))
SCALES = [100, 1000]
def stype(i, kind, pattern, count=1):
    return {"id": "s%09d" % i, "kind": kind, "pattern": pattern,
            "per_scale": {str(s): {"count": count, "texts": 1, "rows_sum": count, "rows_min": 1, "rows_max": 1, "unread": 0} for s in SCALES}}
def record(name, writes_b):
    routes = []
    for n, route in enumerate(ROUTES):
        types = [stype(n * 10 + 1, "select", "select * from `products` where `id` = ?", 3), stype(n * 10 + 2, "select", "select * from `sessions` where `id` = ?")]
        if route == "GET /b/{id}":
            for k, (kind, pattern) in enumerate(writes_b):
                types.append(stype(n * 10 + 3 + k, kind, pattern))
        routes.append({"route": route, "status": "captured", "captured_scales": SCALES, "statement_types": types,
                       "totals": {str(s): {"statements": sum(t["per_scale"][str(s)]["count"] for t in types), "rows_sum": 0, "unaccounted": 0} for s in SCALES}})
    json.dump({"routes": routes}, open(os.path.join(w, "lists", name + ".json"), "w"))
record("ok", [("insert", "insert into `sessions` (`id`, `payload`) values (?, ?)"), ("update", "update `visits` set `n` = `n` + ? where `id` = ?")])
record("none", [])
record("n-table", [("update", "update `products` set `name` = ? where `id` = ?")])
record("views", [("update", "update `views` set `views` = ? where `id` = ?")])
record("prefix", [("insert", "insert into `sessions_archive` (`a`) values (?)")])
record("schema", [("update", "update `other_db`.`sessions` set `a` = ?")])
record("join", [("update", "update `sessions` left join `products` on `products`.`id` = `sessions`.`id` set `products`.`name` = ?")])
record("two", [("update", "update `sessions` set `a` = ?; update `products` set `name` = ?")])
record("ddl", [("alter", "alter table `sessions` add `x` int")])
record("delete", [("delete", "delete from `sessions` where `id` = ?")])
PY
rec() { echo "$work/lists/$1.json"; }
cwe() { env "$@" >"$work/cw.out" 2>"$work/cw.err"; }   # cwe VAR=... command...
CW=("$lab/bin/check-writes" "--routes=$work/lists/routes.json")
PEAK_REPO_ROOT="$repo" PEAK_LAB_ALLOWED_WRITES_FILE="$good" "${CW[@]}" --statements="$(rec ok)" >"$work/cw.out" 2>"$work/cw.err"; expect_code "B1 writes to sessions and visits, both in the list: passes" 0 $?
has "$work/cw.out" 'WRITE CHECK OK' && has "$work/cw.out" 'insert sessions in GET /b/{id}' && has "$work/cw.out" 'update visits in GET /b/{id}' && has "$work/cw.out" 'sessions and visits' && pass "B1b it names the tables and the writes it found" || fail "B1b ($(cat "$work/cw.out"))"
for v in n-table views prefix schema join two ddl; do
  PEAK_REPO_ROOT="$repo" PEAK_LAB_ALLOWED_WRITES_FILE="$good" "${CW[@]}" --statements="$(rec $v)" >"$work/cw.out" 2>"$work/cw.err"; expect_code "B2 $v: refused" 15 $?
  has "$work/cw.err" 'WRITE CHECK REFUSED' && has "$work/cw.err" 'GET /b/{id}' && pass "B2b $v: the message names the route" || fail "B2b $v ($(cat "$work/cw.err"))"
done
has "$work/cw.err" 'cannot be judged\|neither a select nor a write' && pass "B2c a DDL statement is neither a select nor a write to the list" || fail "B2c ($(cat "$work/cw.err" | head -1))"
PEAK_REPO_ROOT="$repo" PEAK_LAB_ALLOWED_WRITES_FILE="$good" "${CW[@]}" --statements="$(rec n-table)" >"$work/cw.out" 2>"$work/cw.err"
has "$work/cw.err" 'somewhere other than `sessions`, `visits` alone' && has "$work/cw.err" 'products' && pass "B2d the message for an N table names the list and the statement" || fail "B2d ($(cat "$work/cw.err"))"
PEAK_REPO_ROOT="$repo" PEAK_LAB_ALLOWED_WRITES_FILE="$good" "${CW[@]}" --statements="$(rec delete)" >"$work/cw.out" 2>"$work/cw.err"; expect_code "B3 a delete from a table of the list: passes here (as a delete from views does in BookStack's), and the entry refuses it" 0 $?
PEAK_REPO_ROOT="$repo" PEAK_LAB_ALLOWED_WRITES_FILE="$good" "${CW[@]}" --statements="$(rec none)" >"$work/cw.out" 2>"$work/cw.err"; expect_code "B4 a record with no write passes" 0 $?
PEAK_REPO_ROOT="$repo" PEAK_LAB_ALLOWED_WRITES_FILE="$empty" "${CW[@]}" --statements="$(rec ok)" >"$work/cw.out" 2>"$work/cw.err"; expect_code "B5 an empty list: a write is refused" 15 $?
PEAK_REPO_ROOT="$repo" PEAK_LAB_ALLOWED_WRITES_FILE="$empty" "${CW[@]}" --statements="$(rec none)" >"$work/cw.out" 2>"$work/cw.err"; expect_code "B5b an empty list: a record with no write passes" 0 $?
PEAK_REPO_ROOT="$repo" PEAK_LAB_ALLOWED_WRITES_FILE="poc/lab/bagisto/none.txt" "${CW[@]}" --statements="$(rec none)" >"$work/cw.out" 2>"$work/cw.err"; expect_code "B6 a list that is named and does not exist: refused (not a fall back to views)" 15 $?
has "$work/cw.err" 'list of tables the lab may write cannot be used' && pass "B6b the message says the list cannot be used" || fail "B6b ($(cat "$work/cw.err"))"
for k in "${!bad[@]}"; do
  PEAK_REPO_ROOT="$repo" PEAK_LAB_ALLOWED_WRITES_FILE="poc/lab/bagisto/bad-$k.txt" "${CW[@]}" --statements="$(rec none)" >"$work/cw.out" 2>"$work/cw.err"; expect_code "B6c a list with a line that is not a table name ($k): refused" 15 $?
done
PEAK_REPO_ROOT="$repo" PEAK_LAB_ALLOWED_WRITES_FILE="$withviews" "${CW[@]}" --statements="$(rec views)" >"$work/cw.out" 2>"$work/cw.err"; expect_code "B7 views in the list: a write to it passes" 0 $?
"${CW[@]}" --allow-tables="$repo/$good" --statements="$(rec ok)" >"$work/cw.out" 2>"$work/cw.err"; expect_code "B8 the list by option (a host path): passes" 0 $?
"${CW[@]}" --statements="$(rec views)" >"$work/cw.out" 2>"$work/cw.err"; expect_code "B9 no list: the default is views alone, as it always was (a write to views passes)" 0 $?
"${CW[@]}" --statements="$(rec ok)" >"$work/cw.out" 2>"$work/cw.err"; expect_code "B9b no list: a write to sessions is refused" 15 $?
cp "$work/lists/routes.json" "$repo/poc/lab/bagisto/route-list.json"
PEAK_REPO_ROOT="$repo" PEAK_LAB_ROUTE_LIST="poc/lab/bagisto/route-list.json" PEAK_LAB_ALLOWED_WRITES_FILE="$good" "$lab/bin/check-writes" --statements="$(rec ok)" >"$work/cw.out" 2>"$work/cw.err"; expect_code "B10 the route list by the environment (a path inside the repository)" 0 $?
PEAK_REPO_ROOT="$repo" PEAK_LAB_ROUTE_LIST="poc/lab/bagisto/none.json" PEAK_LAB_ALLOWED_WRITES_FILE="$good" "$lab/bin/check-writes" --statements="$(rec ok)" >"$work/cw.out" 2>"$work/cw.err"; expect_code "B10b a route list that does not exist: refused" 15 $?

# ---- C: guards (iii), check-tables -------------------------------------------------------------------------------------------------
# $repo is a git repository only for the prediction gate; none of these cases needs a committed prediction (a refusal comes first).
ENVC=(PATH="$work/fakebin:$PATH" PEAK_REPO_ROOT="$repo" PEAK_LAB_STATE_DIR="$work/state" PEAK_LAB_OUT_DIR="$work/out" PEAK_LAB_PREFIX=peak-lab-bagisto
      PEAK_LAB_ALLOWED_WRITES_FILE="$good" PEAK_LAB_TABLES_FILE="$ntables" PEAK_LAB_SEEN_SCALES="")
ct() { env "${ENVC[@]}" "$lab/bin/check-tables" "$@" >"$work/ct.out" 2>"$work/ct.err"; }
base="$work/c/tables-before.tsv"; aft="$work/c/tables-after.tsv"
set_lab; rm -rf "$work/c"
ct before --scale=3000 --baseline="$base"; expect_code "C1 before: the baseline is written, the N tables read from the file" 0 $?
[ "$(grep -vc '^write' "$base" | tr -d ' ')" = 3 ] && has "$base" '^products	100	111$' && has "$base" '^orders	' && has "$base" '^customers	' && pass "C1b it holds a count and a checksum for each of the three N tables of the file" || fail "C1b ($(cat "$base" 2>/dev/null))"
! grep -v '^write' "$base" | grep -q 'sessions\|visits\|views' && pass "C1c the tables of the list are not among the N tables" || fail "C1c"
has "$base" '^write	[^	]*	sessions	1	0	0$' && pass "C1d the server's counters of every table are in it, the list's tables too" || fail "C1d"
[ "$(cat "$work/other.calls" | wc -l | tr -d ' ')" = 0 ] && grep -q 'peak-lab-bagisto-db' "$work/docker.calls" && pass "C1e every query went to peak-lab-bagisto-db and to no other container" || fail "C1e ($(cat "$work/other.calls"))"
ct after --scale=3000 --baseline="$base" --out="$aft"; expect_code "C2 after, nothing changed: 0" 0 $?
printf 'products 0 0 0\nsessions 40 7 0\nvisits 12 30 0\nviews 0 0 0\n' >"$work/s/io"
ct after --scale=3000 --baseline="$base" --out="$aft"; expect_code "C3 sessions and visits written (in the list): allowed" 0 $?
printf 'products 0 1 0\nsessions 1 0 0\nvisits 1 0 0\nviews 0 0 0\n' >"$work/s/io"
ct after --scale=3000 --baseline="$base" --out="$aft"; expect_code "C4 an UPDATE of an N table that leaves count and checksum as they were (only the counter moves): 16" 16 $?
has "$work/ct.err" 'products' && pass "C4b it names the table" || fail "C4b ($(cat "$work/ct.err"))"
ct step --scale=3000 --baseline="$base" --out="$work/c/step.tsv"; expect_code "C4c the same between two steps (counters only): 16" 16 $?
[ "$(queries)" = "$(grep -c 'CHECKSUM\|COUNT(' "$work/docker.calls" | tr -d ' ')" ] && pass "C4d (the count of queries is read from the one call log)" || fail "C4d"
printf 'products 0 0 0\nsessions 1 0 0\nvisits 1 0 0\nviews 0 3 0\n' >"$work/s/io"
ct after --scale=3000 --baseline="$base" --out="$aft"; expect_code "C5 views written, and views is not in this list: 16" 16 $?
has "$work/ct.err" 'views' && pass "C5b it names views" || fail "C5b ($(cat "$work/ct.err"))"
rm -f "$work/s/io"; printf '3' >"$work/s/io_other"
ct after --scale=3000 --baseline="$base" --out="$aft"; expect_code "C6 a write in another schema: 16" 16 $?
rm -f "$work/s/io_other"; printf '222' >"$work/s/sum_orders"
ct after --scale=3000 --baseline="$base" --out="$aft"; expect_code "C7 an N table's checksum changed, the count the same: 16" 16 $?
rm -f "$work/s/sum_orders"; printf '101' >"$work/s/rows_customers"
ct after --scale=3000 --baseline="$base" --out="$aft"; expect_code "C7b an N table's count changed: 16" 16 $?
rm -f "$work/s/rows_customers"
printf 'products 0 0 0\nsessions 1 0 0\nvisits 1 0 0\nviews 0 3 0\n' >"$work/s/io"
env "${ENVC[@]}" PEAK_LAB_ALLOWED_WRITES_FILE="$withviews" "$lab/bin/check-tables" after --scale=3000 --baseline="$base" --out="$aft" >"$work/ct.out" 2>"$work/ct.err"; expect_code "C8 views in the list: a write to it is allowed" 0 $?
rm -f "$work/s/io"

echo "# a table that is both allowed and checked, and a list that cannot be read, are usage errors"
ct before --scale=3000 --baseline="$work/c2/x.tsv" --tables=products,sessions; expect_code "C10 --tables names a table of the list: usage" 2 $?
has "$work/ct.err" 'sessions is a table the lab may write' && pass "C10b the message says which" || fail "C10b ($(cat "$work/ct.err"))"
printf 'products\nvisits\n' >"$work/lists/n-overlap.txt"
ct before --scale=3000 --baseline="$work/c2/x.tsv" --tables-file="$work/lists/n-overlap.txt"; expect_code "C10c the N tables file names a table of the list: usage" 2 $?
ct before --scale=3000 --baseline="$work/c2/x.tsv" --tables-file="$work/lists/none.txt"; expect_code "C11 an N tables file that does not exist: usage" 2 $?
: >"$work/lists/n-empty.txt"
ct before --scale=3000 --baseline="$work/c2/x.tsv" --tables-file="$work/lists/n-empty.txt"; expect_code "C11b an N tables file that names none: usage" 2 $?
printf 'products\nBad-Name\n' >"$work/lists/n-bad.txt"
ct before --scale=3000 --baseline="$work/c2/x.tsv" --tables-file="$work/lists/n-bad.txt"; expect_code "C11c an N tables file with a line that is not a name: usage" 2 $?
env "${ENVC[@]}" PEAK_LAB_ALLOWED_WRITES_FILE="poc/lab/bagisto/none.txt" "$lab/bin/check-tables" before --scale=3000 --baseline="$work/c2/x.tsv" >"$work/ct.out" 2>"$work/ct.err"; expect_code "C12 a list of allowed tables that is named and cannot be read: usage (never views)" 2 $?
for k in schema quoted two; do
  env "${ENVC[@]}" PEAK_LAB_ALLOWED_WRITES_FILE="poc/lab/bagisto/bad-$k.txt" "$lab/bin/check-tables" before --scale=3000 --baseline="$work/c2/x.tsv" >"$work/ct.out" 2>"$work/ct.err"; expect_code "C12b a list with a line that is not a name ($k): usage" 2 $?
done
[ "$(queries)" = 0 ] || true
[ ! -e "$work/c2/x.tsv" ] && pass "C12c none of these left a baseline" || fail "C12c"
ct before --scale=3000 --baseline="$work/c2/y.tsv" --tables=products,orders; expect_code "C13 --tables still works by name (the N tables not in the list)" 0 $?
has "$work/c2/y.tsv" '^orders	' && hasnt "$work/c2/y.tsv" '^customers	' && pass "C13b it checks those two and not the file's third" || fail "C13b"

echo "# the counts seen so far (above 30,000 none, for this lab) and the refusal for the rest"
set_lab
for sc in 70000 100000 50000 200000; do
  ct allowed --scale=$sc; expect_code "C20 scale $sc: refused with no prediction (seen scales are empty for this lab): 17" 17 $?
done
ct before --scale=70000 --baseline="$work/c3/b.tsv"; expect_code "C20b a baseline at 70,000: 17" 17 $?
[ "$(queries)" = 0 ] && [ ! -s "$work/docker.calls" ] && pass "C20c every refusal sent no query and touched no container" || fail "C20c ($(cat "$work/docker.calls"))"
for sc in 3000 10000 30000; do ct allowed --scale=$sc; expect_code "C21 scale $sc (seen): allowed" 0 $?; done
env "${ENVC[@]}" PEAK_LAB_SEEN_SCALES="70000 100000" "$lab/bin/check-tables" allowed --scale=70000 >"$work/ct.out" 2>"$work/ct.err"; expect_code "C22 the seen scales from the environment: 70,000 allowed when it says so" 0 $?
env "${ENVC[@]}" PEAK_LAB_SEEN_SCALES="70000 100000" "$lab/bin/check-tables" allowed --scale=50000 >"$work/ct.out" 2>"$work/ct.err"; expect_code "C22b ... and 50,000 still refused" 17 $?
env -u PEAK_LAB_SEEN_SCALES "${ENVC[@]:0:6}" PEAK_LAB_ALLOWED_WRITES_FILE="$good" "$lab/bin/check-tables" allowed --scale=70000 >"$work/ct.out" 2>"$work/ct.err"; expect_code "C23 unset: BookStack's seen scales, as it always was (70,000 allowed)" 0 $?
env -u PEAK_LAB_SEEN_SCALES "${ENVC[@]:0:6}" PEAK_LAB_ALLOWED_WRITES_FILE="$good" "$lab/bin/check-tables" allowed --scale=200000 >"$work/ct.out" 2>"$work/ct.err"; expect_code "C23b unset: 200,000 refused" 17 $?

# ---- D: the stack's settings ----------------------------------------------------------------------------------------------------
echo "# the stack's settings"
wb() { env PEAK_LAB_BAGISTO_STATE_DIR="$work/bstate" "$lab/bin/with-bagisto" "$@"; }
wb env >"$work/wb.env" 2>"$work/wb.err"; expect_code "D1 with-bagisto runs a command" 0 $?
for kv in PEAK_LAB_PROJECT=peak-lab-bagisto PEAK_LAB_PREFIX=peak-lab-bagisto PEAK_LAB_IMAGE=peak-lab-bagisto-php PHP_VERSION=8.4 PHP_EXTENSIONS=calendar PEAK_LAB_PROFILE=bagisto "PEAK_LAB_STATE_DIR=$work/bstate" "PEAK_LAB_OUT_DIR=$work/bstate/out"; do
  has "$work/wb.env" "^$kv\$" && pass "D2 the Bagisto stack sets $kv" || fail "D2 $kv"
done
has "$work/wb.env" '^PEAK_LAB_SEEN_SCALES=$' && has "$work/wb.env" '^PEAK_LAB_ALLOWED_WRITES_FILE=poc/lab/bagisto/allowed-write-tables.txt$' && has "$work/wb.env" '^PEAK_LAB_ROUTE_LIST=poc/lab/bagisto/route-list.json$' && has "$work/wb.env" 'n-tables.txt$' && pass "D3 no seen scale above 30,000, and the paths of the list, the route list and the N tables are the Bagisto ones" || fail "D3"
has "$work/wb.env" '^PEAK_LAB_ACT_AS=Webkul\\Customer\\Models\\Customer:1:customer$' && pass "D3b the login of the entry is the first customer on the customer guard" || fail "D3b ($(grep ACT_AS "$work/wb.env"))"
has "$work/wb.env" '^PEAK_LAB_LOGINS=customer=Webkul\\Customer\\Models\\Customer:1:customer;admin=Webkul\\User\\Models\\Admin:1:admin$' && pass "D3c the Bagisto entry offers the customer and the administrator, each by name, on their own guard" || fail "D3c ($(grep LOGINS "$work/wb.env"))"
has "$work/wb.env" '^PEAK_LAB_REQUEST_HOST=measure.invalid$' && pass "D3d the Bagisto stack sends every request with the host of APP_URL (the capture's own)" || fail "D3d ($(grep REQUEST_HOST "$work/wb.env"))"
env PEAK_LAB_BAGISTO_STATE_DIR="$work/bstate" PEAK_LAB_REQUEST_HOST= "$lab/bin/with-bagisto" env >"$work/wb2.env" 2>/dev/null; has "$work/wb2.env" '^PEAK_LAB_REQUEST_HOST=$' && pass "D3e an own (empty) value wins over the default, as for every value of the wrapper" || fail "D3e"
env -u PEAK_LAB_REQUEST_HOST bash -c ". $lab/bin/_lab.sh >/dev/null 2>&1; env" >"$work/bs.env" 2>/dev/null; hasnt "$work/bs.env" '^PEAK_LAB_REQUEST_HOST=' && pass "D3f the BookStack lab's shared setup names no Host" || fail "D3f"
env PEAK_LAB_BAGISTO_STATE_DIR="$HOME/.cache/peak-lab" "$lab/bin/with-bagisto" true >/dev/null 2>&1; expect_code "D4 pointed at the BookStack lab's state directory: refused" 2 $?
env PEAK_LAB_BAGISTO_STATE_DIR="$work/bstate" "$lab/bin/with-bagisto" >/dev/null 2>&1; expect_code "D4b no command: usage" 2 $?
env PEAK_LAB_BAGISTO_STATE_DIR="$work/bstate" PEAK_LAB_ALLOWED_WRITES_FILE=/etc/passwd "$lab/bin/with-bagisto" bash -c ". $lab/bin/_lab.sh" >/dev/null 2>"$work/wb.err"; expect_code "D5 a list outside the repository (an absolute path): refused by the scripts' shared setup" 2 $?
env PEAK_LAB_BAGISTO_STATE_DIR="$work/bstate" PEAK_LAB_ROUTE_LIST=../x.json "$lab/bin/with-bagisto" bash -c ". $lab/bin/_lab.sh" >/dev/null 2>"$work/wb.err"; expect_code "D5b a route list with .. in it: refused" 2 $?

# the compose file rendered by docker compose (offline), for the BookStack lab's default and for the Bagisto stack
render() {  # render <dir for state> [wrapper...]: the compose file as docker compose resolves it
  local st="$1"; shift
  PEAK_LAB_STATE_DIR="$st" PEAK_LAB_OUT_DIR="$st/out" PEAK_APP_ROOT="$repo_real" "$@" bash -c "source '$lab/bin/_lab.sh' && compose config"
}
render "$work/dstate" env -u PEAK_LAB_PREFIX -u PEAK_LAB_PROJECT -u PEAK_LAB_IMAGE -u PHP_VERSION -u PHP_EXTENSIONS -u PEAK_LAB_ALLOWED_WRITES_FILE -u PEAK_LAB_ROUTE_LIST -u PEAK_LAB_LOGINS -u PEAK_LAB_ACT_AS >"$work/default.yaml" 2>"$work/default.err"; expect_code "D10 the default environment renders" 0 $?
for c in peak-lab-db peak-lab-app peak-lab-web peak-lab-k6 peak-lab-tools; do has "$work/default.yaml" "container_name: $c\$" && pass "D11 default: container $c" || fail "D11 $c"; done
has "$work/default.yaml" 'name: peak-lab-dbdata$' && has "$work/default.yaml" '^name: peak-lab$' && has "$work/default.yaml" 'image: peak-lab-php:8.3$' && pass "D11b default: the volume peak-lab-dbdata, the project peak-lab and the image peak-lab-php:8.3, as they were" || fail "D11b"
has "$work/default.yaml" 'PEAK_LAB_ALLOWED_WRITES_FILE: ""$' && pass "D11c default: no list of tables is handed to the app (views alone)" || fail "D11c"
has "$work/default.yaml" 'PEAK_LAB_LOGINS: ""$' && has "$work/default.yaml" 'PEAK_LAB_ACT_AS: BookStack.Users.Models.User:1:web$' && pass "D11d default: no list of logins is handed to the app, and its default login is the first BookStack user, as before" || fail "D11d ($(grep -E 'LOGINS|ACT_AS' "$work/default.yaml"))"
render "$work/bstate" env PEAK_LAB_BAGISTO_STATE_DIR="$work/bstate" "$lab/bin/with-bagisto" >"$work/bag.yaml" 2>"$work/bag.err"; expect_code "D12 the Bagisto stack renders" 0 $?
for c in db app web k6 tools; do has "$work/bag.yaml" "container_name: peak-lab-bagisto-$c\$" && pass "D13 Bagisto: container peak-lab-bagisto-$c" || fail "D13 $c"; done
has "$work/bag.yaml" 'name: peak-lab-bagisto-dbdata$' && has "$work/bag.yaml" 'image: peak-lab-bagisto-php:8.4$' && has "$work/bag.yaml" 'PHP_EXTENSIONS: calendar$' && pass "D13b Bagisto: its own volume and image, with the calendar extension" || fail "D13b"
has "$work/bag.yaml" 'PEAK_LAB_ALLOWED_WRITES_FILE: /peak-sim/poc/lab/bagisto/allowed-write-tables.txt$' && pass "D13c Bagisto: the list is handed to the app as a path inside the container" || fail "D13c ($(grep ALLOWED "$work/bag.yaml"))"
has "$work/bag.yaml" 'PEAK_LAB_LOGINS: customer=Webkul.Customer.Models.Customer:1:customer;admin=Webkul.User.Models.Admin:1:admin$' && pass "D13d Bagisto: the two logins are handed to the app" || fail "D13d ($(grep LOGINS "$work/bag.yaml"))"
{ ! grep -E 'container_name: peak-lab-(db|app|web|k6|tools)$|name: peak-lab-dbdata$' "$work/bag.yaml"; } && pass "D14 Bagisto: no container and no volume of the BookStack lab's is named" || fail "D14"
python3 - "$work/bag.yaml" <<'PY' && pass "D15 Bagisto: the one network is internal, every service is on it only, and no service publishes a port" || fail "D15"
import re, sys
text = open(sys.argv[1]).read()
nets = re.search(r"^networks:\n((?:  .*\n|\n)+)", text, re.M).group(1)
assert re.search(r"lab-internal:\n\s+name: peak-lab-bagisto_lab-internal\n\s+internal: true", nets), nets
assert nets.count("internal: true") == 1 and len(re.findall(r"^  [a-z-]+:$", nets, re.M)) == 1, nets
services = re.search(r"^services:\n((?:  .*\n|\n)+)", text, re.M).group(1)
blocks = re.split(r"^  (?=[a-z0-9-]+:$)", services, flags=re.M)
blocks = [b for b in blocks if b.strip()]
assert len(blocks) == 5, len(blocks)
for b in blocks:
    assert "ports:" not in b, b[:80]
    assert "network_mode" not in b, b[:80]
    m = re.search(r"networks:\n((?:      .*\n)+)", b)
    assert m and re.findall(r"^      ([a-z0-9-]+):", m.group(1), re.M) == ["lab-internal"], b[:80]
PY

# ---- F: build-db's profile, the statistics step after a build, and the list the Bagisto lab commits --------------------------------
echo "# the builder's profile (BookStack's by default), the one ANALYZE after a build, and the committed list"
mkdir -p "$work/f/bin"; for f in build-db analyze-db _lab.sh; do cp "$lab/bin/$f" "$work/f/bin/$f"; done
printf '#!/usr/bin/env bash\nexit 0\n' >"$work/f/bin/preflight"; chmod +x "$work/f/bin/preflight"
bd() { env PATH="$work/fakebin:$PATH" PEAK_REPO_ROOT="$repo" PEAK_LAB_STATE_DIR="$work/state" PEAK_LAB_OUT_DIR="$work/out" "$@" >"$work/bd.out" 2>"$work/bd.err"; }
set_lab; bd "$work/f/bin/build-db" --scale=3000 --n-tables=products,orders
has "$work/docker.calls" 'build-db.php --scale=3000 .*--profile=bookstack$' && pass "F1 build-db with nothing set builds with the bookstack profile, as it always did" || fail "F1 ($(cat "$work/docker.calls"))"
set_lab; bd env PEAK_LAB_PROFILE=bagisto "$work/f/bin/build-db" --scale=3000 --n-tables=products,orders
has "$work/docker.calls" 'build-db.php --scale=3000 .*--profile=bagisto$' && hasnt "$work/docker.calls" 'profile=bookstack' && pass "F2 PEAK_LAB_PROFILE=bagisto builds with the bagisto profile and no other" || fail "F2 ($(cat "$work/docker.calls"))"
set_lab; bd env PEAK_LAB_BAGISTO_STATE_DIR="$work/bstate" "$lab/bin/with-bagisto" "$work/f/bin/build-db" --scale=3000 --n-tables=products,orders
has "$work/docker.calls" 'exec peak-lab-bagisto-tools php /peak-sim/poc/runtime/bin/inner/build-db.php .*--profile=bagisto$' && pass "F3 through with-bagisto: the Bagisto tools container and the bagisto profile" || fail "F3 ($(cat "$work/docker.calls"))"
grep -q -- '--profile=' "$lab/bin/build-db" && [ "$(grep -v '^ *#' "$lab/bin/build-db" | grep -c 'bookstack')" = 1 ] && grep -q 'PEAK_LAB_PROFILE:-bookstack' "$lab/bin/build-db" && pass "F3b the one place that says bookstack is the default of the variable" || fail "F3b"
AE=(env PATH="$work/fakebin:$PATH" PEAK_REPO_ROOT="$repo" PEAK_LAB_STATE_DIR="$work/state" PEAK_LAB_OUT_DIR="$work/out" PEAK_LAB_PREFIX=peak-lab-bagisto)
set_lab; rm -rf "$work/an"
"${AE[@]}" "$lab/bin/analyze-db" --scale=3000 --out="$work/an/one" >"$work/an.out" 2>"$work/an.err"; expect_code "F4 analyze-db on a built database: 0" 0 $?
has "$work/an.out" 'ANALYZE .*_lab_3000: 3 of 3 tables status OK' && pass "F4b it says every table answered OK" || fail "F4b ($(cat "$work/an.out"))"
[ "$(wc -l <"$work/an/one/analyze-output.txt" | tr -d ' ')" = 3 ] && has "$work/an/one/analyze-timing.tsv" '^3000.3.' && [ -s "$work/an/one/analyze-table-stats-before.tsv" ] && [ -s "$work/an/one/analyze-table-stats-after.tsv" ] && pass "F4c it wrote the output, the timing and the statistics before and after" || fail "F4c"
[ "$(grep -c 'ANALYZE TABLE' "$work/docker.calls" | tr -d ' ')" = 1 ] && pass "F4d ANALYZE TABLE was sent once, for all the tables in one statement" || fail "F4d"
! grep -qi 'STATS_AUTO_RECALC\|ALTER TABLE' "$work/docker.calls" && pass "F4e it never touched the automatic recalculation or any table definition" || fail "F4e"
[ ! -s "$work/other.calls" ] && pass "F4f every call went to the Bagisto database container" || fail "F4f ($(cat "$work/other.calls"))"
: >"$work/docker.calls"
"${AE[@]}" "$lab/bin/analyze-db" --scale=3000 --out="$work/an/one" >"$work/an.out" 2>"$work/an.err"; expect_code "F5 a second run for the same directory: refused (3), once means once" 3 $?
[ "$(grep -c 'ANALYZE TABLE' "$work/docker.calls" | tr -d ' ')" = 0 ] && pass "F5b ... and nothing was sent" || fail "F5b"
set_lab "analyze_bad=1"
"${AE[@]}" "$lab/bin/analyze-db" --scale=3000 --out="$work/an/two" >"$work/an.out" 2>"$work/an.err"; expect_code "F6 a table that does not answer status OK: 1" 1 $?
set_lab "no_tables=1"
"${AE[@]}" "$lab/bin/analyze-db" --scale=3000 --out="$work/an/three" >"$work/an.out" 2>"$work/an.err"; expect_code "F7 a database with no table: 18" 18 $?
set_lab "mysql_fail=1"
"${AE[@]}" "$lab/bin/analyze-db" --scale=3000 --out="$work/an/four" >"$work/an.out" 2>"$work/an.err"; expect_code "F8 a server that cannot be read: 18" 18 $?
"${AE[@]}" "$lab/bin/analyze-db" --scale=abc --out="$work/an/five" >/dev/null 2>&1; expect_code "F9 a scale that is not a number: usage" 2 $?
"${AE[@]}" "$lab/bin/analyze-db" --scale=3000 >/dev/null 2>&1; expect_code "F9b no output directory: usage" 2 $?
# the list the Bagisto lab commits: empty (the route selection found no write), which means no write at all and never `views`
committed="poc/lab/bagisto/allowed-write-tables.txt"
[ -f "$repo_real/$committed" ] && [ -z "$(python3 - "$lab/bin/check-writes" "$repo_real/$committed" <<'PY'
import importlib.machinery, importlib.util, sys
loader = importlib.machinery.SourceFileLoader("check_writes", sys.argv[1]); spec = importlib.util.spec_from_loader("check_writes", loader)
mod = importlib.util.module_from_spec(spec); loader.exec_module(mod); print("\n".join(mod.allowed_tables(sys.argv[2])))
PY
)" ] && pass "F10 the committed list of the Bagisto lab is empty" || fail "F10"
rows="$(grep -v '^table' "$repo_real/docs/research/lab-data/bagisto-route-list/list/allowed-tables.tsv" 2>/dev/null | wc -l | tr -d ' ')"
[ "$rows" = 0 ] && pass "F10b ... as the route selection's own record says (no table written by the selected routes)" || fail "F10b ($rows)"
PEAK_REPO_ROOT="$repo_real" PEAK_LAB_ALLOWED_WRITES_FILE="$committed" "$lab/bin/check-writes" --routes="$work/lists/routes.json" --statements="$(rec none)" >"$work/cw.out" 2>"$work/cw.err"; expect_code "F11 with the committed (empty) list a record of reads passes" 0 $?
PEAK_REPO_ROOT="$repo_real" PEAK_LAB_ALLOWED_WRITES_FILE="$committed" "$lab/bin/check-writes" --routes="$work/lists/routes.json" --statements="$(rec views)" >"$work/cw.out" 2>"$work/cw.err"; expect_code "F11b ... and a write to views is refused: an empty list never falls to views" 15 $?
PEAK_REPO_ROOT="$repo_real" PEAK_LAB_ALLOWED_WRITES_FILE="$committed" "$lab/bin/check-writes" --routes="$work/lists/routes.json" --statements="$(rec ok)" >"$work/cw.out" 2>"$work/cw.err"; expect_code "F11c ... as is a write to any other table" 15 $?
set_lab; rm -rf "$work/fc"
env "${ENVC[@]}" PEAK_REPO_ROOT="$repo_real" PEAK_LAB_ALLOWED_WRITES_FILE="$committed" "$lab/bin/check-tables" before --scale=3000 --baseline="$work/fc/b.tsv" >"$work/ct.out" 2>"$work/ct.err"; expect_code "F12 check-tables with the committed list reads the baseline" 0 $?
printf 'products 0 0 0\nviews 0 1 0\n' >"$work/s/io"
env "${ENVC[@]}" PEAK_REPO_ROOT="$repo_real" PEAK_LAB_ALLOWED_WRITES_FILE="$committed" "$lab/bin/check-tables" after --scale=3000 --baseline="$work/fc/b.tsv" --out="$work/fc/a.tsv" >"$work/ct.out" 2>"$work/ct.err"; expect_code "F12b ... and a write to views then stops it (16): views is not allowed when the list is empty" 16 $?
printf 'products 0 0 0\nsessions 1 0 0\n' >"$work/s/io"
env "${ENVC[@]}" PEAK_REPO_ROOT="$repo_real" PEAK_LAB_ALLOWED_WRITES_FILE="$committed" "$lab/bin/check-tables" after --scale=3000 --baseline="$work/fc/b.tsv" --out="$work/fc/a.tsv" >"$work/ct.out" 2>"$work/ct.err"; expect_code "F12c ... and so does a new counter row with a write in it (16)" 16 $?

# ---- G: the Bagisto route list, and the logins of the entry ---------------------------------------------------------------------
echo "# the route list and the logins"
mrl="$lab/bagisto/make-route-list"
mkdir -p "$work/g"
printf 'number\troute\trows_sum_30000\n1\tGET /api/things\t900\n2\tGET /shop/mine\t800\n3\tGET /admin/things/view/{id}\t700\n' >"$work/g/selected.tsv"
cat >"$work/g/tasks.json" <<'JSON'
[
 {"spec": {"method": "GET", "uri": "/admin/things/view/{id}"}, "uri": "/admin/things/view/1", "fail_class": null, "act_as": "Shop\\User\\Models\\Admin:1:admin"},
 {"spec": {"method": "GET", "uri": "/api/things"}, "uri": "/api/things", "fail_class": null, "act_as": null},
 {"spec": {"method": "GET", "uri": "/shop/mine"}, "uri": "/shop/mine", "fail_class": null, "act_as": "Shop\\Customer\\Models\\Customer:1:customer"},
 {"spec": {"method": "GET", "uri": "/not/selected"}, "uri": "/not/selected", "fail_class": null, "act_as": "Shop\\Staff:1:staff"}
]
JSON
python3 -I "$mrl" --selected="$work/g/selected.tsv" --tasks="$work/g/tasks.json" --out="$work/g/list.json" 2>"$work/g/err"; expect_code "G1 the route list is made from a selection and the capture's tasks" 0 $?
python3 - "$work/g/list.json" <<'PY' && pass "G2 the routes keep the selection's order and ranks, each with the login its task had (guest, customer, admin), and the halves are 1-4 and 5-8" || fail "G2"
import json, sys
d = json.load(open(sys.argv[1]))
rows = d["selected"]
assert [r["route"] for r in rows] == ["GET /api/things", "GET /shop/mine", "GET /admin/things/view/{id}"], rows
assert [r["rank"] for r in rows] == [1, 2, 3]
assert [r["login"] for r in rows] == ["guest", "customer", "admin"], rows
assert [r["half"] for r in rows] == ["first"] * 3
assert d["counts"] == {"selected": 3, "customer": 1, "admin": 1, "guest": 1}, d["counts"]
assert rows[0]["capture_act_as"] is None and rows[2]["capture_act_as"].endswith(":1:admin")
assert len(d["inputs"]["selection"]["sha256"]) == 64 and len(d["inputs"]["capture_tasks"]["sha256"]) == 64
PY
python3 -I "$mrl" --selected="$work/g/selected.tsv" --tasks="$work/g/tasks.json" >"$work/g/again.json" 2>/dev/null && cmp -s "$work/g/list.json" "$work/g/again.json" && pass "G3 made again, the list is the same bytes" || fail "G3"
python3 - "$work/g/tasks.json" "$work/g/t-staff.json" "$work/g/t-missing.json" "$work/g/t-unfilled.json" "$work/g/t-twice.json" <<'PY'
import json, sys
t = json.load(open(sys.argv[1]))
def save(path, tasks): json.dump(tasks, open(path, "w"))
staff = json.loads(json.dumps(t)); staff[0]["act_as"] = "Shop\\Staff:1:staff"; save(sys.argv[2], staff)
save(sys.argv[3], [x for x in t if x["spec"]["uri"] != "/shop/mine"])
unfilled = json.loads(json.dumps(t)); unfilled[1]["fail_class"] = "unfilled_param"; unfilled[1]["uri"] = None; save(sys.argv[4], unfilled)
save(sys.argv[5], t + [t[0]])
PY
for k in staff missing unfilled twice; do
  rm -f "$work/g/stop.json"; python3 -I "$mrl" --selected="$work/g/selected.tsv" --tasks="$work/g/t-$k.json" --out="$work/g/stop.json" 2>"$work/g/err"; rc=$?
  { [ "$rc" -eq 4 ] && [ ! -e "$work/g/stop.json" ]; } && pass "G4 $k: a stop (exit 4), nothing written" || fail "G4 $k (exit $rc)"
done
python3 -I "$mrl" --selected="$work/g/selected.tsv" >/dev/null 2>&1; expect_code "G5 no task list: usage (2)" 2 $?
printf 'number\troute\trows_sum_30000\n2\tGET /a\t5\n' >"$work/g/bad-sel.tsv"
python3 -I "$mrl" --selected="$work/g/bad-sel.tsv" --tasks="$work/g/tasks.json" >/dev/null 2>&1; expect_code "G6 a selection not numbered from 1: a stop" 4 $?
python3 - "$lab/bagisto/route-list.json" "$lab/../../docs/research/lab-data/bagisto-route-list/list/selected.tsv" <<'PY' && pass "G7 the committed Bagisto route list is the committed selection, in order, each route with a login of customer, admin or guest" || fail "G7"
import json, sys
d = json.load(open(sys.argv[1]))
sel = [l.split("\t") for l in open(sys.argv[2]).read().splitlines()[1:]]
assert [r["route"] for r in d["selected"]] == [c[1] for c in sel]
assert [r["rank"] for r in d["selected"]] == [int(c[0]) for c in sel]
assert [r["rows_sum_30000"] for r in d["selected"]] == [int(c[2]) for c in sel]
assert all(r["login"] in ("customer", "admin", "guest") for r in d["selected"])
assert all((r["login"] == "guest") == (r["capture_act_as"] is None) for r in d["selected"])
assert d["counts"]["selected"] == len(sel) == 8
PY

# ---- E: each guard, taken out of a copy, no longer holds ---------------------------------------------------------------------------
echo "# each part of the list taken out of a copy of the tool is caught by the cases above"
mkdir -p "$work/mut/bin"; for f in check-tables check-writes _lab.sh prediction-gate; do cp "$lab/bin/$f" "$work/mut/bin/$f"; done
mutate() {  # mutate <tool> <name> <sed expression>: a copy of the tool with the expression applied, which must change it
  cp "$lab/bin/$1" "$work/mut/bin/$2"; sed -i -E "$3" "$work/mut/bin/$2"; chmod +x "$work/mut/bin/$2"
  if cmp -s "$lab/bin/$1" "$work/mut/bin/$2"; then fail "E0 the mutation $2 does not apply to $1"; return 1; fi
}
ctm() { env "${ENVC[@]}" "$work/mut/bin/$1" "${@:2}" >"$work/ct.out" 2>"$work/ct.err"; }
set_lab; rm -rf "$work/e"; ct before --scale=3000 --baseline="$work/e/b.tsv"
printf 'products 0 0 0\nsessions 40 7 0\nvisits 12 30 0\nviews 0 0 0\n' >"$work/s/io"
mutate check-tables ct-no-allowed '/index\(allowed/d'
ctm ct-no-allowed after --scale=3000 --baseline="$work/e/b.tsv" --out="$work/e/a.tsv"; expect_code "E1 without the skip of the list's tables in the comparison, a write to sessions stops the scenario (so the skip is what lets it pass)" 16 $?
mutate check-tables ct-no-overlap '/\*",\$t,"\*\) echo/d'
ctm ct-no-overlap before --scale=3000 --baseline="$work/e/x.tsv" --tables=products,sessions; rc=$?
[ "$rc" != 2 ] && pass "E2 without the overlap check, a table in both lists is no longer a usage error (exit $rc), so the check is what stops it" || fail "E2"
mutate check-tables ct-seen-fixed 's/\$\{PEAK_LAB_SEEN_SCALES-70000 100000\}/70000 100000/'
ctm ct-seen-fixed allowed --scale=70000; expect_code "E3 with the seen scales fixed to BookStack's, 70,000 is allowed for the Bagisto lab (so the environment's list is what refuses it)" 0 $?
mutate check-tables ct-default-n 's/^if \[ -n "\$tables_file" \]; then$/if false; then/'
ctm ct-default-n before --scale=3000 --baseline="$work/e/y.tsv"; has "$work/e/y.tsv" '^entities	' && pass "E4 with the N tables file ignored, the BookStack tables are read (so the file is what names the Bagisto ones)" || fail "E4"
mutate check-writes cw-default-views 's/^    with open\(path, "r", encoding="utf-8"\) as fh:$/    return list(DEFAULT_TABLES)\n    with open(path, "r", encoding="utf-8") as fh:/'
"$work/mut/bin/cw-default-views" "--routes=$work/lists/routes.json" --allow-tables="$repo/$good" --statements="$(rec ok)" >"$work/cw.out" 2>"$work/cw.err"; expect_code "E5 with the list ignored (views alone), a write to sessions is refused (so the list is what lets it pass)" 15 $?
mutate check-writes cw-no-rule 's/^            rule = rules.get\(kind\)$/            rule = write_rules(["sessions", "visits", "products"]).get(kind)/'
PEAK_REPO_ROOT="$repo" "$work/mut/bin/cw-no-rule" "--routes=$work/lists/routes.json" --allow-tables="$repo/$good" --statements="$(rec n-table)" >"$work/cw.out" 2>"$work/cw.err"; expect_code "E6 with a table of the N tables slipped into the rules, the write to it passes (so the rules are built from the list alone)" 0 $?

echo
echo "allowed-writes tests: $passed passed, $failed failed, $skipped skipped"
[ "$failed" -eq 0 ] && [ "$skipped" -eq 0 ]
