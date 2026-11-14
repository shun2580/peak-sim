#!/usr/bin/env bash
# Tests of the lab's write guards (decisions.md D187, D189): the lab may write the view-count table `views` and nothing else, and (i) the record of the
# statements is checked before a measurement, (iii) the N tables are counted and checksummed before and after, and a count of rows that has not been seen
# is refused. They never touch the lab: the `docker` they put on PATH is a stand-in that answers the lab's reads from files the test writes, hands the
# throw-away PHP containers (no network, no database) to the real docker, and records every other call. "Nothing was measured" is shown by the load
# tool (`k6`) never being called, and "no query was sent" by no COUNT or CHECKSUM reaching the stand-in. The records and tables are made up here.
#
#   poc/lab/tests/write-guards.sh
#
# A  bin/check-writes on made-up records: a record with writes to `views` only passes; a write to another table, a DDL statement, a multi-table
#    update, two statements in one text, a schema-qualified name, an empty text, an incomplete record, a route that was not captured and a route that is
#    missing are each refused with exit 15 and the message names the route and the statement type
# B  bin/check-tables: the baseline is written; a restart compares with it; a changed count, a changed checksum (the count the same) and an unreadable
#    table are found (16, 16, 18); `views` cannot be named; the refusal for counts not seen (17): 50,000 and 200,000 are refused with no prediction, with an
#    uncommitted one, with a committed one that holds no scenario of that scale, and with a committed one that does the scale is allowed; counts seen already are
#    allowed; every refusal sends no query
# C  the wiring in bin/measure-calibration and bin/measure-ladder, on the real scripts in a copy of the lab's bin directory:
#    each of the three guards stops a measurement before the load tool; each, taken out of a copy, no longer does; a lab whose N tables change under the
#    (stand-in) load is stopped before the run is summarized; a restart of a ladder scenario whose tables moved is stopped; each marker is on one line,
#    in the right order; the files the guards write are outside .gitignore
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
for v in ok other-table ddl join-update two-statements schema-qualified empty incomplete not-captured missing-route; do mkrec "$v"; done
good="$work/rec/ok.json"; bad="$work/rec/other-table.json"

# ---- A: check-writes ----------------------------------------------------------------------------------------------------------
cw() { "$lab/bin/check-writes" --statements="$1" >"$work/cw.out" 2>"$work/cw.err"; }
cw "$good"; expect_code "A1 writes to views only: passes" 0 $?
has "$work/cw.out" 'WRITE CHECK OK' && has "$work/cw.out" 'insert views in GET /shelves/{slug}' && has "$work/cw.out" 'update views in GET /books/{slug}' && pass "A1b it lists the writes it found" || fail "A1b ($(cat "$work/cw.out"))"
for v in other-table ddl join-update two-statements schema-qualified empty incomplete not-captured missing-route; do
  cw "$work/rec/$v.json"; expect_code "A2 $v: refused" 15 $?
  has "$work/cw.err" 'WRITE CHECK REFUSED' && has "$work/cw.err" 'GET /books/{slug}' && pass "A2b $v: the message names the route" || fail "A2b $v ($(cat "$work/cw.err"))"
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
cat >"$work/fakebin/docker" <<FAKE
#!/usr/bin/env bash
w="$work"; s="\$w/s"
if [ "\$1" = run ] || [ "\$1" = image ]; then exec "$real_docker" "\$@"; fi
echo "\$*" >>"\$w/docker.calls"
val() { if [ -f "\$s/\$1" ]; then cat "\$s/\$1"; else echo "\$2"; fi; }
if [ "\$1" = inspect ]; then echo "PEAK_LAB_SCALE=\$(val scale 10000)"; exit 0; fi
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
    esac
  fi
  case "\$c \$1 \$2" in
    "peak-lab-web nginx -T")
      printf 'http {\n    server {\n        location / {\n            fastcgi_pass lab_app;\n            fastcgi_read_timeout %s;\n        }\n    }\n}\n' "\$(val nginx 120s)"; exit 0 ;;
    "peak-lab-app php-fpm -tt")
      printf '[11-Nov-2026 10:00:00] NOTICE: \trequest_terminate_timeout = %s\n' "\$(val pool 0s)"; exit 0 ;;
    "peak-lab-db mysql -uroot")
      printf 'entities\t2026-11-11 05:17:04\t10000\t100\t50\n'; exit 0 ;;
  esac
  if [ "\$c" = peak-lab-tools ] && [ "\$1" = php ] && [ "\$2" = /peak-sim/poc/lab/diagnosis/fcgi_get.php ]; then
    printf '{"sapi":"fpm-fcgi","php":"8.3.36","max_execution_time":"%s"}\n' "\$(val php 30)"; exit 0
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
      exit 0
    fi
    exit 99
  fi
fi
exit 99
FAKE
chmod +x "$work/fakebin/docker"

for f in measure-calibration measure-ladder check-limits check-writes check-tables prediction-gate _lab.sh; do cp "$lab/bin/$f" "$work/lab/bin/$f"; done
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
env_run() { PATH="$work/fakebin:$PATH" PEAK_REPO_ROOT="$grepo" PEAK_LAB_STATE_DIR="$work/state" PEAK_LAB_OUT_DIR="$out" "$@"; }
k6_calls() { wc -l <"$work/k6.calls" | tr -d ' '; }
queries() { grep -c 'CHECKSUM\|COUNT(' "$work/docker.calls" | tr -d ' '; }
B=(php=0 pool=0s nginx=600s)

# ---- B: check-tables ----------------------------------------------------------------------------------------------------------
ct() { env_run "$work/lab/bin/check-tables" "$@" >"$work/ct.out" 2>"$work/ct.err"; }
base="$work/b/tables-before.tsv"; aft="$work/b/tables-after.tsv"
set_lab; rm -rf "$work/b"
ct before --scale=10000 --baseline="$base"; expect_code "B1 before: the baseline is written" 0 $?
[ "$(wc -l <"$base" | tr -d ' ')" = 3 ] && has "$base" '^entities	10000	111$' && has "$base" '^images	' && has "$base" '^references	' && pass "B1b it holds a count and a checksum for entities, images and references" || fail "B1b ($(cat "$base" 2>/dev/null))"
hasnt "$base" 'views' && pass "B1c views (the one table the lab may write) is not in it" || fail "B1c"
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
line() { grep -n "$2" "$lab/bin/$1" | head -1 | cut -d: -f1; }
lw="$(line measure-ladder '# WRITE-CHECK$')"; lp="$(line measure-ladder 'bindir/preflight')"
lb="$(line measure-ladder '# TABLES-BEFORE$')"; lk="$(line measure-ladder 'peak-lab-k6 k6 run')"; la="$(line measure-ladder '# TABLES-AFTER$')"; ls_="$(line measure-ladder '^  php_tool poc/lab/load/summarize-run.php')"
[ "$lw" -lt "$lp" ] && pass "C1b ladder: the write check is before the closed-network check" || fail "C1b ladder ($lw, $lp)"
[ "$lb" -lt "$lk" ] && [ "$lk" -lt "$la" ] && [ "$la" -lt "$ls_" ] && pass "C1c ladder: tables before < the load tool < tables after < the summary" || fail "C1c ladder ($lb $lk $la $ls_)"
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
# after a step
set_lab php=30 pool=0s nginx=120s scale=10000 k6_ok=1 k6_flip=1
ml "$mlr" --scenario=t-4 --predictions="$pred_first" --scheme=B --statement-record="$good"; rc=$?
expect_code "C13 the N tables change under the load of a step: 16" 16 $rc
has "$work/ml.err" 'after rung 1' && pass "C13b it says after which step" || fail "C13b ($(cat "$work/ml.err"))"
[ -z "$(ls "$out"/ladder/t-4-10000/rung-01-*/record.json 2>/dev/null)" ] && [ -z "$(ls "$out"/ladder/t-4-10000/rung-01-*/run.json 2>/dev/null)" ] && pass "C13c the step was not summarized and has no record" || fail "C13c"
[ -s "$(ls "$out"/ladder/t-4-10000/rung-01-*/tables-after.tsv | head -1)" ] && pass "C13d tables-after.tsv is kept in the step's directory" || fail "C13d"
set_lab php=30 pool=0s nginx=120s scale=10000 k6_ok=1 k6_flip=1
ml "$(without measure-ladder TABLES-AFTER)" --scenario=t-4 --predictions="$pred_first" --scheme=B --statement-record="$good"; rc=$?
[ "$rc" -ne 16 ] && has "$work/ml.err" 'summarize-run failed' && pass "C13e WITHOUT the tables-after line the same step goes on to the summary (exit $rc)" || fail "C13e (rc $rc, $(head -3 "$work/ml.err" | tr '\n' ' '))"
set_lab php=30 pool=0s nginx=120s scale=10000 k6_ok=1
ml "$mlr" --scenario=t-4 --predictions="$pred_first" --scheme=B --statement-record="$good"; rc=$?
has "$work/ml.err" 'summarize-run failed' && has "$work/ml.out" 'TABLES SAME after the load' && pass "C13f unchanged tables: the step goes on past the check" || fail "C13f (rc $rc, $(head -3 "$work/ml.err" | tr '\n' ' '))"
# a dry run needs no record and touches nothing
set_lab
ml "$mlr" --scenario=t-4 --dry-run; rc=$?
[ "$rc" -ne 2 ] && [ "$rc" -ne 15 ] && pass "C14 a dry run needs no --statement-record (exit $rc)" || fail "C14 (rc $rc, $(head -3 "$work/ml.err" | tr '\n' ' '))"

# the names the guards write can all be committed (none is matched by .gitignore)
ign_dir="docs/research/lab-data/ladder/t-4-10000/rung-01-1"
names="tables-before.tsv tables-before.tsv.restart tables-before.tsv.err tables-before.tsv.restart.err tables-before.tsv.restart.diff tables-after.tsv tables-after.tsv.err tables-after.tsv.diff"
leaked="$(for nm in $names; do git -C "$repo_real" check-ignore -q --no-index "$ign_dir/$nm" && printf '%s ' "$nm"; done)"
[ -z "$leaked" ] && pass "C15 none of the names the table guard writes is ignored by git" || fail "C15 names ignored by git: $leaked"

echo
echo "write guards tests: $passed passed, $failed failed, $skipped skipped"
[ "$failed" -eq 0 ] && [ "$skipped" -eq 0 ]
