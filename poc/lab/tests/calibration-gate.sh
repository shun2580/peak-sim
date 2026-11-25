#!/usr/bin/env bash
# Tests of the calibration gate in front of the one entry that measures calibration runs: `bin/measure-calibration` (docs/simulator-spec.md 7.4.1).
# They never touch the lab: the `docker` they put on PATH is a stand-in that answers the lab's reads from files the test writes, hands the throw-away
# PHP containers (no network, no database) to the real docker, and records every other call; "nothing was measured" is shown by the load tool (`k6`)
# never being called.
#
#   poc/lab/tests/calibration-gate.sh
#
#  1  the five runs of the remeasured calibration (a set that cannot tell the coefficients apart): refused, exit 14, no lab container touched, k6 never
#     called, the degrees printed and kept in calibration-gate.json
#  2  the same with the lab fully ready (limits set, scale right): still refused before anything of the lab is read
#  3  a set that can (the five plus two runs of a different mix): the gate opens and the entry goes on to the limits guard and to k6
#  4  the gate record is kept in the run's directory
#  4b every file the entry writes is outside .gitignore (none is named *.log), so a record is not lost from a commit
#  5  THE GATE IS IN THE ENTRY: the same script with its gate line taken out does reach k6 on the refused set (so 1 means something), and the
#     real script has exactly one such line, before the closed-network check
#  6  --gate-only touches no container and answers by the gate
#  7  the limits guard is in the entry too: a lab left at the default is refused (13) after the gate opens, k6 never called
#  8  usage errors (no --measure, no --request-timeout, a --measure that is not in --run) stop at 2 before the gate
#  9  --gate-tables: without it the gate is given no --tables and the record is what it was; with it the list reaches the gate alone (the shared-table
#     estimate of the Bagisto lab opens the gate only with it) and never the guard of the N tables; a bad value is a usage error before the gate
# 10  --search-gate-advisory (decisions.md D270 decision 1, the search of a rate): without it a refused gate stops at 14 as ever; with it a refusal (14) is printed as
#     GATE ADVISORY, recorded (calibration-gate.json says passed false, search-gate-advisory.txt in the out directory and in the run's directory) and the entry goes on
#     to the write check, the tables guard, the limits guard and k6 (the stand-in k6 stops it at 13); any other failure of the gate still stops it; together with
#     --gate-only it is a usage error before the gate; without the flag no search-gate-advisory.txt is written or copied
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

[ -n "$real_docker" ] && "$real_docker" image inspect "$php_image" >/dev/null 2>&1 || { echo "calibration-gate tests: docker and the image $php_image are needed (to run the PHP tools)" >&2; exit 2; }

work="$(mktemp -d)"; trap 'rm -rf "$work"' EXIT
mkdir -p "$work/state" "$work/out" "$work/fakebin" "$work/s" "$work/lab/bin" "$work/lab/observe" "$work/plans"
out="$(cd "$work/out" && pwd)"
data="$repo_real/docs/research/lab-data"
est="$data/estimate-r3/rule/100k"

# ---- the stand-in docker ------------------------------------------------------------------------------------------------------
# The state of the pretend lab is a few files in $work/s; a missing file means the default (PHP 30, pool 0s, nginx 120s, scale 10000).
# `run` and `image` (the throw-away PHP containers of the tools) go to the real docker; every other call is recorded and answered here.
cat >"$work/fakebin/docker" <<FAKE
#!/usr/bin/env bash
w="$work"; s="\$w/s"
if [ "\$1" = run ] || [ "\$1" = image ]; then exec "$real_docker" "\$@"; fi
echo "\$*" >>"\$w/docker.calls"
val() { if [ -f "\$s/\$1" ]; then cat "\$s/\$1"; else echo "\$2"; fi; }
if [ "\$1" = inspect ]; then echo "PEAK_LAB_SCALE=\$(val scale 10000)"; exit 0; fi
if [ "\$1" = exec ]; then
  shift
  while [ "\${1:-}" = "-i" ] || [ "\${1:-}" = "-e" ]; do if [ "\$1" = "-e" ]; then shift 2; else shift; fi; done
  c="\$1"; shift
  if [ "\$c" = peak-lab-db ] && [ "\$1" = mysql ]; then   # the count and checksum of the N tables (bin/check-tables): the tables do not change
    case "\$*" in
      *CHECKSUM*) printf '10000\ndb.t\t111\n'; exit 0 ;;
      *performance_schema*) d="\$(printf '%s' "\$*" | sed -n "s/.*object_schema = '\([^']*\)'.*/\1/p")"; printf '%s\tentities\t0\t0\t0\n%s\tviews\t1\t0\t0\n' "\$d" "\$d"; exit 0 ;;
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
    printf '{"sapi":"fpm-fcgi","php":"8.3.36","max_execution_time":"%s","memory_limit":"%s"}\n' "\$(val php 30)" "\$(val mem 512M)"; exit 0
  fi
  if [ "\$c" = peak-lab-tools ] && [ "\$1" = php ]; then
    shift
    exec "$real_docker" run --rm -i --pull=never --network none --cpuset-cpus="${PEAK_DEV_CPUSET:-12-15}" -u "$(id -u):$(id -g)" -v "$repo_real:/peak-sim:ro" -v "$out:/out" "$php_image" nice php "\$@"
  fi
  if [ "\$c" = peak-lab-k6 ]; then echo "\$*" >>"\$w/k6.calls"; exit 99; fi
fi
exit 99
FAKE
chmod +x "$work/fakebin/docker"

# a copy of the lab's bin directory with the real scripts; preflight (the closed-network check) and the CPU sampler are stubs
for f in measure-calibration check-limits check-writes check-tables prediction-gate _lab.sh; do cp "$lab/bin/$f" "$work/lab/bin/$f"; done
mkdir -p "$work/rec" "$work/lab/routes"; cp "$lab/routes/route-list.json" "$work/lab/routes/route-list.json"
python3 "$here/make-statement-record.py" "$lab/routes/route-list.json" "$work/rec/ok.json" --variant=ok
rec="$work/rec/ok.json"   # the made-up record of the statements the write check reads (every write in it is to views)

ln -s "$lab/scenarios" "$work/lab/scenarios"   # the gate itself is the real one
printf '#!/usr/bin/env bash\nexit 0\n' >"$work/lab/bin/preflight"; chmod +x "$work/lab/bin/preflight"
printf '#!/usr/bin/env bash\nexit 0\n' >"$work/lab/observe/sample-cpu"; chmod +x "$work/lab/observe/sample-cpu"
for scale in 3000 10000 30000; do echo '{}' >"$out/targets-$scale.json"; done

# the plans: the five runs of the remeasured calibration, and two more of a different mix (a book page 80%, no ZIP), made here as the plan files are
d5="$data/calibration-limits-off"
python3 - "$work/plans" "$d5/cal-1/plan.json" <<'PY'
import json, os, sys
dest, base = sys.argv[1], json.load(open(sys.argv[2]))
routes = []
for r in base['routes']:
    v = 0.0 if r['route'] == 'GET /books/{bookSlug}/export/zip' else (0.8 * 2.0 if r['route'] == 'GET /books/{slug}' else 0.2 * 2.0 / 6)
    routes.append({'route': r['route'], 'rps': v})
plan = {'mix': 'book80', 'rps': sum(x['rps'] for x in routes), 'duration_sec': 600, 'warmup_sec': 120, 'routes': routes}
json.dump(plan, open(os.path.join(dest, 'book80.json'), 'w'))
PY
five=(); for n in 1 2 3 4 5; do five+=(--run=cal-$n:$d5/cal-$n/plan.json); done
scales=(--run-scale=cal-4:3000 --run-scale=cal-5:30000)
seven=("${five[@]}" --run=cal-6:"$work/plans/book80.json" --run=cal-7:"$work/plans/book80.json" "${scales[@]}" --run-scale=cal-6:30000 --run-scale=cal-7:30000)

reset_lab() { rm -f "$work"/s/*; : >"$work/docker.calls"; : >"$work/k6.calls"; rm -rf "$out/calibration" "$out/limits"; }
set_lab() { reset_lab; local kv; for kv in "$@"; do printf '%s' "${kv#*=}" >"$work/s/${kv%%=*}"; done; }
env_run() { PATH="$work/fakebin:$PATH" PEAK_REPO_ROOT="$repo_real" PEAK_LAB_STATE_DIR="$work/state" PEAK_LAB_OUT_DIR="$out" "$@"; }
mc() { env_run "$work/lab/bin/measure-calibration" "$@" --statement-record="$rec" >"$work/mc.out" 2>"$work/mc.err"; }
k6_calls() { wc -l <"$work/k6.calls" | tr -d ' '; }
lab_calls() { wc -l <"$work/docker.calls" | tr -d ' '; }
B=(php=0 pool=0s nginx=600s mem=4G)

# 1 ---------------------------------------------------------------------------------------------------------------------------------------
set_lab "${B[@]}"
mc --estimate="$est" "${five[@]}" "${scales[@]}" --measure=cal-1 --request-timeout=600s; rc=$?
expect_code "1 the five runs of the remeasured calibration: refused" 14 $rc
[ "$(lab_calls)" = 0 ] && pass "1b no container of the lab was touched" || fail "1b the lab was touched ($(head -3 "$work/docker.calls" | tr '\n' ' '))"
[ "$(k6_calls)" = 0 ] && pass "1c the load tool was never called" || fail "1c k6 was called"
has "$work/mc.out" 'degree 0.005938' && has "$work/mc.out" 'without cal-4  *degree 0.003834' && pass "1d the degrees are printed (0.005938 for all, 0.003834 without cal-4)" || fail "1d no degrees in the output ($(head -12 "$work/mc.out" | tr '\n' ' '))"
has "$work/mc.err" 'CALIBRATION GATE REFUSED' && pass "1e it says the gate refused" || fail "1e ($(cat "$work/mc.err"))"
[ -s "$out/calibration/calibration-gate.json" ] && has "$out/calibration/calibration-gate.json" '"passed": false' && pass "1f the degrees are kept in calibration-gate.json" || fail "1f no record of the gate"
[ ! -e "$out/calibration/cal-1" ] && pass "1g no run directory was made" || fail "1g a run directory was made"

# 2 ---------------------------------------------------------------------------------------------------------------------------------------
set_lab "${B[@]}" scale=10000
mc --estimate="$est" "${five[@]}" "${scales[@]}" --measure=cal-1,cal-2 --request-timeout=600s --switch-scale; rc=$?
expect_code "2 a lab that is fully ready does not change the answer" 14 $rc
[ "$(lab_calls)" = 0 ] && [ "$(k6_calls)" = 0 ] && pass "2b still nothing of the lab touched, no load" || fail "2b"

# 3 ---------------------------------------------------------------------------------------------------------------------------------------
set_lab "${B[@]}" scale=10000
mc --estimate="$est" "${seven[@]}" --measure=cal-1 --request-timeout=600s; rc=$?
has "$work/mc.out" 'CALIBRATION GATE OK' && pass "3 the five plus two runs of a different mix: the gate opens" || fail "3 the gate did not open ($(tail -5 "$work/mc.out" | tr '\n' ' ') $(cat "$work/mc.err"))"
[ "$(k6_calls)" -ge 1 ] && pass "3b the entry reaches the load tool (so 1c means something)" || fail "3b k6 was never reached ($(head -3 "$work/mc.err" | tr '\n' ' '))"
expect_code "3c the stand-in k6 fails, which stops it at 13" 13 $rc
has "$work/mc.out" 'RUN SETTINGS cal-1' && has "$work/mc.out" 'RUN START cal-1' && pass "3d the limits were read and recorded, then the run started" || fail "3d ($(cat "$work/mc.out" | tail -4 | tr '\n' ' '))"
has "$out/calibration/cal-1/stats-before.tsv" 'entities' && pass "3e the statistics were read before the run" || fail "3e no stats-before.tsv"

# 4 ---------------------------------------------------------------------------------------------------------------------------------------
[ -s "$out/calibration/cal-1/calibration-gate.json" ] && has "$out/calibration/cal-1/calibration-gate.json" '"passed": true' && pass "4 the gate record is kept in the run's directory" || fail "4 no calibration-gate.json in the run directory"

# 4b every file the entry writes can be committed: none of them is matched by .gitignore (the load tool's log was once named k6.log and was dropped by *.log)
# the names come from the run directory of 3 and, as the stand-in k6 stops the run early, also from every "$d/<name>" the script itself writes to
ign_dir="docs/research/lab-data/calibration-limits-off/cal-x"
names="$( { ls "$out/calibration/cal-1"; grep -oE '"\$d/[A-Za-z0-9._-]+"' "$lab/bin/measure-calibration" | sed -E 's|^"\$d/||; s|"$||'; printf 'calibration-gate.json\n'; } | sort -u )"
n_names="$(printf '%s\n' "$names" | grep -c .)"
leaked="$(for nm in $names; do git -C "$repo_real" check-ignore -q --no-index "$ign_dir/$nm" && printf '%s ' "$nm"; done)"
[ "$n_names" -ge 15 ] && [ -z "$leaked" ] && pass "4b none of the $n_names output names is ignored by git (.gitignore)" || fail "4b names ignored by git: ${leaked:-none}; names checked: $n_names"
[ -f "$out/calibration/cal-1/k6-output.txt" ] && pass "4c the load tool's output is kept as k6-output.txt" || fail "4c no k6-output.txt"

# 5 THE GATE IS IN THE ENTRY -----------------------------------------------------------------------------------------------------------
n_lines="$(grep -c 'CALIBRATION-GATE$' "$lab/bin/measure-calibration")"
[ "$n_lines" = 1 ] && pass "5 the real entry has one line that calls the gate" || fail "5 the real entry has $n_lines gate lines"
gate_line="$(grep -n 'CALIBRATION-GATE$' "$lab/bin/measure-calibration" | cut -d: -f1)"
pre_line="$(grep -n 'bindir/preflight' "$lab/bin/measure-calibration" | head -1 | cut -d: -f1)"
[ -n "$gate_line" ] && [ -n "$pre_line" ] && [ "$gate_line" -lt "$pre_line" ] && pass "5b the gate comes before the closed-network check" || fail "5b the gate (line ${gate_line:-?}) is not before preflight (line ${pre_line:-?})"
# the same script with the gate line and the check of its answer taken out
sed '/CALIBRATION-GATE$/d' "$lab/bin/measure-calibration" | sed 's/^gate_rc=\$?$/gate_rc=0/' >"$work/lab/bin/measure-calibration-nogate"; chmod +x "$work/lab/bin/measure-calibration-nogate"
set_lab "${B[@]}" scale=10000
env_run "$work/lab/bin/measure-calibration-nogate" --estimate="$est" "${five[@]}" "${scales[@]}" --measure=cal-1 --request-timeout=600s --statement-record="$rec" >"$work/mc.out" 2>"$work/mc.err"; rc=$?
[ "$(k6_calls)" -ge 1 ] && pass "5c without the gate line the refused set reaches the load tool: the test in 1 is what stops it" || fail "5c the entry without the gate did not reach k6 ($(head -3 "$work/mc.err" | tr '\n' ' '))"

# 6 ---------------------------------------------------------------------------------------------------------------------------------------
set_lab "${B[@]}"
mc --estimate="$est" "${five[@]}" "${scales[@]}" --gate-only; expect_code "6 --gate-only on the five runs: 14" 14 $?
mc --estimate="$est" "${seven[@]}" --gate-only; rc=$?
expect_code "6b --gate-only on the seven runs: 0" 0 $rc
[ "$(lab_calls)" = 0 ] && pass "6c --gate-only touched no container" || fail "6c the lab was touched"

# 7 ---------------------------------------------------------------------------------------------------------------------------------------
set_lab php=30 pool=0s nginx=120s scale=10000   # what the lab is when nobody switched it
mc --estimate="$est" "${seven[@]}" --measure=cal-1 --request-timeout=600s; rc=$?
expect_code "7 the gate opens but the lab is left at the default: the limits guard stops it" 13 $rc
[ "$(k6_calls)" = 0 ] && pass "7b the load tool was never called" || fail "7b k6 was called"
has "$work/mc.err" 'max_execution_time' && pass "7c the refusal names the limit" || fail "7c ($(cat "$work/mc.err"))"

# 8 ---------------------------------------------------------------------------------------------------------------------------------------
set_lab "${B[@]}"
mc --estimate="$est" "${seven[@]}" --request-timeout=600s; expect_code "8 no --measure: usage" 2 $?
mc --estimate="$est" "${seven[@]}" --measure=cal-1; expect_code "8b no --request-timeout: usage" 2 $?
mc --estimate="$est" "${seven[@]}" --measure=cal-9 --request-timeout=600s; expect_code "8c --measure of a run that is not in --run: usage" 2 $?
[ ! -e "$out/calibration/calibration-gate.json" ] && [ "$(lab_calls)" = 0 ] && pass "8d none of them reached the gate or the lab" || fail "8d"

# 9 --gate-tables: the table names the gate counts rows of (decisions.md D264 decision 3: the Bagisto lab's estimate names the pseudo table n_shared, the guard of the
#   N tables reads the real tables). It goes to the gate and to nothing else; without it the entry is what it was.
# a copy of the lab whose gate is a spy: it writes the arguments it was given and runs the real gate
mkdir -p "$work/lab2/bin" "$work/lab2/observe" "$work/lab2/scenarios" "$work/lab2/routes"
cp "$work"/lab/bin/* "$work/lab2/bin/"; cp "$work/lab/observe/sample-cpu" "$work/lab2/observe/"; cp "$work/lab/routes/route-list.json" "$work/lab2/routes/"
printf '#!/usr/bin/env bash\necho "$*" >>"%s/gate.args"\nexec "%s/scenarios/calibration-gate" "$@"\n' "$work" "$lab" >"$work/lab2/scenarios/calibration-gate"
chmod +x "$work/lab2/scenarios/calibration-gate"
mc2() { env_run "$work/lab2/bin/measure-calibration" "$@" --statement-record="$rec" >"$work/mc.out" 2>"$work/mc.err"; }
degrees() { grep -E 'degree' "$work/mc.out"; }
# the estimate whose N-axis routes name n_shared instead of entities, images, references (nothing else is changed)
mkdir -p "$work/est-shared"
python3 - "$est/estimate.json" "$work/est-shared/estimate.json" <<'PY'
import re, sys
t = open(sys.argv[1]).read()
t2 = re.sub(r'"table": "(entities|images|references)"', '"table": "n_shared"', t)
assert t2 != t
open(sys.argv[2], 'w').write(t2)
PY
est_shared="$work/est-shared"

set_lab "${B[@]}"; rm -f "$work/gate.args"
mc2 --estimate="$est" "${seven[@]}" --gate-only; rc=$?
expect_code "9 without the option the seven runs open the gate as before" 0 $rc
[ "$(grep -c -- '--tables' "$work/gate.args")" = 0 ] && pass "9b without the option the gate is given no --tables (its own default stays)" || fail "9b the gate was given --tables ($(cat "$work/gate.args"))"
degrees >"$work/deg.plain"; cp "$out/calibration/calibration-gate.json" "$work/gate.plain.json"

set_lab "${B[@]}"; rm -f "$work/gate.args"
mc2 --estimate="$est" "${seven[@]}" --gate-only --gate-tables=entities,images,references; rc=$?
expect_code "9c the option naming the default tables: the gate opens" 0 $rc
[ "$(grep -c -- '--tables=entities,images,references' "$work/gate.args")" = 1 ] && pass "9d the list reaches the gate, once, as --tables" || fail "9d ($(cat "$work/gate.args"))"
cmp -s "$out/calibration/calibration-gate.json" "$work/gate.plain.json" && pass "9e the gate's record is byte for byte the one made without the option" || fail "9e the record differs"

set_lab "${B[@]}"
mc2 --estimate="$est_shared" "${seven[@]}" --gate-only; rc=$?
expect_code "9f the shared-table copy without the option: refused (the gate counts rows of tables the estimate does not name)" 14 $rc
has "$work/mc.out" 'smallest degree 0.000000' && pass "9g the degree is 0.000000: every route is out of the rates" || fail "9g ($(degrees | tail -2 | tr '\n' ' '))"

set_lab "${B[@]}"; rm -f "$work/gate.args"
mc2 --estimate="$est_shared" "${seven[@]}" --gate-only --gate-tables=n_shared; rc=$?
expect_code "9h the shared-table copy with --gate-tables=n_shared: the gate opens" 0 $rc
degrees >"$work/deg.shared"
cmp -s "$work/deg.shared" "$work/deg.plain" && pass "9i the degrees equal those of the original estimate with its own tables (every N table has the one count)" || fail "9i the degrees differ"
[ "$(lab_calls)" = 0 ] && pass "9j --gate-only touched no container" || fail "9j the lab was touched"

# the option does not reach the guard of the N tables: the run goes on to bin/check-tables (before), which reads the real tables, and then to the stand-in k6
set_lab "${B[@]}" scale=10000
mc2 --estimate="$est_shared" "${seven[@]}" --measure=cal-1 --request-timeout=600s --gate-tables=n_shared; rc=$?
expect_code "9k with the option the entry goes on, through the guard, to the load tool (the stand-in k6 stops it at 13)" 13 $rc
[ "$(k6_calls)" -ge 1 ] && pass "9l the load tool was reached" || fail "9l k6 was never reached ($(head -3 "$work/mc.err" | tr '\n' ' '))"
grep -q 'CHECKSUM' "$work/docker.calls" && pass "9m the guard of the N tables ran (CHECKSUM TABLE was asked)" || fail "9m check-tables did not run"
if grep -q 'n_shared' "$work/docker.calls"; then fail "9n the pseudo table reached the database side: $(grep -m1 'n_shared' "$work/docker.calls")"; else pass "9n the pseudo table n_shared never reached the database side (check-tables kept the real tables)"; fi
grep 'CHECKSUM' "$work/docker.calls" | grep -q 'entities' && pass "9o the CHECKSUM TABLE was asked of the real N tables" || fail "9o ($(grep -m1 CHECKSUM "$work/docker.calls"))"

# a bad value is a usage error before the gate and before the lab
for bad in 'Entities' 'a b' 'a,,b' ',a' 'a,' 'a;b'; do
  set_lab "${B[@]}"; rm -f "$work/gate.args"
  mc2 --estimate="$est" "${seven[@]}" --gate-only "--gate-tables=$bad"; rc=$?
  if [ "$rc" = 2 ] && [ ! -e "$work/gate.args" ] && [ "$(lab_calls)" = 0 ]; then pass "9p --gate-tables='$bad': usage, exit 2, the gate not called, the lab not touched"; else fail "9p --gate-tables='$bad' (exit $rc)"; fi
done

# 10 --search-gate-advisory ----------------------------------------------------------------------------------------------------------------------
# the five runs are refused by the gate (1); the flag lets the entry go on
set_lab "${B[@]}" scale=10000
mc --estimate="$est" "${five[@]}" "${scales[@]}" --measure=cal-1 --request-timeout=600s; rc=$?
expect_code "10a without the flag a refused gate still stops the entry at 14" 14 $rc
[ "$(k6_calls)" = 0 ] && [ ! -e "$out/calibration/search-gate-advisory.txt" ] && pass "10b and nothing is measured, no advisory file is written" || fail "10b"

set_lab "${B[@]}" scale=10000
mc --estimate="$est" "${five[@]}" "${scales[@]}" --measure=cal-1 --request-timeout=600s --search-gate-advisory; rc=$?
expect_code "10c with the flag the refused gate does not stop the entry: it goes through the guards to the load tool (the stand-in k6 stops it at 13)" 13 $rc
[ "$(k6_calls)" -ge 1 ] && pass "10d the load tool was reached" || fail "10d k6 was never reached ($(head -3 "$work/mc.err" | tr '\n' ' '))"
has "$work/mc.err" 'GATE ADVISORY: the calibration gate refused (exit 14)' && pass "10e it says in the output that the gate refused and that the run goes on" || fail "10e ($(head -3 "$work/mc.err" | tr '\n' ' '))"
has "$work/mc.out" 'degree 0.005938' && pass "10f the degrees are printed as before" || fail "10f no degrees in the output"
has "$out/calibration/calibration-gate.json" '"passed": false' && pass "10g calibration-gate.json keeps the refusal (passed false)" || fail "10g"
a="$out/calibration/search-gate-advisory.txt"
has "$a" 'search_gate_advisory=1' && has "$a" 'gate_exit=14' && has "$a" 'gate_passed=false' && has "$a" 'min_degree=0.0038' && has "$a" 'min_left_out=cal-4' && has "$a" 'threshold=0.5' \
  && pass "10h search-gate-advisory.txt has the flag, the gate's exit code 14, passed false, the smallest degree 0.0038 (without cal-4) and the threshold" || fail "10h ($(cat "$a" 2>/dev/null | tr '\n' ' '))"
has "$out/calibration/cal-1/search-gate-advisory.txt" 'gate_exit=14' && has "$out/calibration/cal-1/calibration-gate.json" '"passed": false' && pass "10i the run's directory keeps the same two records" || fail "10i"
grep -q 'CHECKSUM' "$work/docker.calls" && pass "10j the guard of the N tables ran before the load tool" || fail "10j"
# the write check still runs and still stops the entry (a record that writes outside views)
python3 "$here/make-statement-record.py" "$lab/routes/route-list.json" "$work/rec/bad.json" --variant=other-table >/dev/null 2>&1
if [ -s "$work/rec/bad.json" ]; then
  set_lab "${B[@]}" scale=10000
  env_run "$work/lab/bin/measure-calibration" --estimate="$est" "${five[@]}" "${scales[@]}" --measure=cal-1 --request-timeout=600s --search-gate-advisory --statement-record="$work/rec/bad.json" >"$work/mc.out" 2>"$work/mc.err"; rc=$?
  [ "$rc" = 15 ] && [ "$(k6_calls)" = 0 ] && pass "10k with the flag a record that writes outside views still stops the entry (15), no load" || fail "10k (exit $rc)"
else
  fail "10k the made-up bad statement record could not be made"
fi
# a gate that fails for another reason still stops the entry, flag or not: an estimate directory with nothing in it
mkdir -p "$work/emptyest"; set_lab "${B[@]}" scale=10000
mc --estimate="$work/emptyest" "${seven[@]}" --measure=cal-1 --request-timeout=600s --search-gate-advisory; rc=$?
if [ "$rc" != 0 ] && [ "$rc" != 14 ] && [ "$rc" != 13 ] && [ "$(k6_calls)" = 0 ] && [ "$(lab_calls)" = 0 ]; then pass "10l an unusable estimate stops the entry with the gate's own exit code ($rc), flag or not"; else fail "10l (exit $rc)"; fi
# together with --gate-only it is a usage error before the gate and before the lab
set_lab "${B[@]}"; rm -f "$out/calibration/calibration-gate.json"
mc --estimate="$est" "${five[@]}" "${scales[@]}" --gate-only --search-gate-advisory; rc=$?
if [ "$rc" = 2 ] && [ "$(lab_calls)" = 0 ] && [ ! -e "$out/calibration/calibration-gate.json" ]; then pass "10m --search-gate-advisory with --gate-only: usage, exit 2, the gate not called, the lab not touched"; else fail "10m (exit $rc)"; fi
# a gate that opens, with the flag: nothing changes in the measuring, the advisory file says exit 0
set_lab "${B[@]}" scale=10000
mc --estimate="$est" "${seven[@]}" --measure=cal-1 --request-timeout=600s --search-gate-advisory; rc=$?
expect_code "10n a gate that opens, with the flag: the entry goes on to the load tool as without it" 13 $rc
has "$out/calibration/search-gate-advisory.txt" 'gate_exit=0' && has "$out/calibration/search-gate-advisory.txt" 'gate_passed=true' && pass "10o and the advisory file says the gate's exit was 0 and that it passed" || fail "10o"
# a call without the flag after one with it, in the same out directory, copies no advisory file into the run's directory
set_lab "${B[@]}" scale=10000; mkdir -p "$out/calibration"; printf 'search_gate_advisory=1\ngate_exit=14\n' >"$out/calibration/search-gate-advisory.txt"
mc --estimate="$est" "${seven[@]}" --measure=cal-2 --request-timeout=600s; rc=$?
[ ! -e "$out/calibration/cal-2/search-gate-advisory.txt" ] && pass "10p without the flag an old advisory file is not copied into the run's directory" || fail "10p"

echo
echo "calibration gate tests: $passed passed, $failed failed, $skipped skipped"
[ "$failed" -eq 0 ] && [ "$skipped" -eq 0 ]
