#!/usr/bin/env bash
# The Bagisto lab's web server file (docs/simulator-spec.md 7.4.7): compose/nginx/lab-bagisto.conf is the BookStack lab's lab.conf less the upstream
# keepalive and fastcgi_keep_conn, laid over lab.compose.yaml by compose/lab.bagisto.override.yaml through bin/with-bagisto. No stack is started, no
# volume is touched, no image is built: the compose file is only rendered (`docker compose config`), nginx is only asked to read its file once
# (`nginx -t` in a throw-away container with no volume, no network and a read-only mount), and bin/lab-config-b runs on a throw-away repository.
#
#   poc/lab/tests/bagisto-nginx.sh
#
# 1  the two nginx files differ in those two lines and nothing else; lab.conf still has both, lab-bagisto.conf has neither
# 2  rendered: the BookStack lab's web server mounts lab.conf, the Bagisto lab's mounts lab-bagisto.conf; everything else about the web server's
#    mounts and entry is the same
# 3  the Bagisto wrapper does not take the file or the compose files from the environment
# 4  nginx reads both files (`nginx -t`), and the Bagisto one has no keepalive and no fastcgi_keep_conn in the configuration it prints (`nginx -T`)
# 5  bin/lab-config-b under the Bagisto wrapper puts B on lab-bagisto.conf (timeout 1800s, the PHP ini) and leaves lab.conf alone; restore gives back
#    the bytes; without the wrapper it works on lab.conf as before; the two files still differ by the same two lines while B is on
# No test is skipped: a precondition that is not met is a failure, not a skip.
set -uo pipefail
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
lab="$(cd "$here/.." && pwd)"
repo="$(cd "$lab/../.." && pwd)"
nginx_image="${NGINX_IMAGE:-nginx:1.28-alpine}"

passed=0; failed=0; skipped=0
pass() { passed=$((passed + 1)); printf 'ok   %s\n' "$1"; }
fail() { failed=$((failed + 1)); printf 'FAIL %s\n' "$1"; }
check() { local label="$1"; shift; if "$@"; then pass "$label"; else fail "$label"; fi; }

work="$(mktemp -d)"; trap 'rm -rf "$work"' EXIT
a="$lab/compose/nginx/lab.conf"; b="$lab/compose/nginx/lab-bagisto.conf"

# 1 ------------------------------------------------------------------------------------------------------------------------------
diff "$a" "$b" | grep -E '^[<>]' | sed 's/^\(.\) */\1 /' | sort >"$work/d.txt"
printf '%s\n' '< fastcgi_keep_conn on;' '< keepalive 64;' >"$work/want.txt"
check "1a the files differ in the two lines and nothing else" cmp -s "$work/d.txt" "$work/want.txt"
check "1b lab.conf has the upstream keepalive and fastcgi_keep_conn once each" bash -c '[ "$(grep -c "^ *keepalive 64;" "$1")" = 1 ] && [ "$(grep -c "^ *fastcgi_keep_conn on;" "$1")" = 1 ]' _ "$a"
check "1c lab-bagisto.conf has neither (keepalive_timeout, the client side, is in both)" bash -c '! grep -qE "^ *(keepalive [0-9]|fastcgi_keep_conn)" "$1" && grep -q "^ *keepalive_timeout 65;" "$1"' _ "$b"

# 2 ------------------------------------------------------------------------------------------------------------------------------
reduce() {  # the web server's mounts (source from the repository on, target, mode), its entry, nothing else
  python3 -c '
import json, sys
w = json.load(sys.stdin)["services"]["lab-web"]
for v in sorted(w["volumes"], key=lambda v: v["target"]):
    src = v["source"]
    src = src.split("/poc/lab/", 1)[1] if "/poc/lab/" in src else "<out>"
    print("mount", src, v["target"], "ro" if v.get("read_only") else "rw")
print("entrypoint", " ".join(w["entrypoint"]))
print("read_only", w.get("read_only"), "user", w.get("user"))
'
}
env -u PEAK_LAB_NGINX_CONF -u PEAK_LAB_COMPOSE_FILES -u PEAK_LAB_APP_CPUS -u PEAK_LAB_CPUSET_APP \
  PEAK_REPO_ROOT="$repo" PEAK_APP_ROOT="$work" PEAK_LAB_OUT_DIR="$work" PEAK_LAB_LOAD_DIR="$work" \
  PEAK_MEASURE_DB_ROOT_PASSWORD=x PEAK_MEASURE_DB_PASSWORD=x PEAK_MEASURE_RUN_ID=x PEAK_MEASURE_APP_KEY=x \
  docker compose -p peak-lab -f "$lab/compose/lab.compose.yaml" config --format json 2>"$work/book.err" | reduce >"$work/book.txt" 2>>"$work/book.err"
"$lab/bin/with-bagisto" bash -c ". '$lab/bin/_lab.sh'; compose config --format json" 2>"$work/bag.err" | reduce >"$work/bag.txt" 2>>"$work/bag.err"
sed 's/^/# BookStack lab: /' "$work/book.txt"; sed 's/^/# Bagisto lab: /' "$work/bag.txt"
check "2a the BookStack lab's web server mounts lab.conf at /etc/nginx/nginx.conf, read-only" grep -qx 'mount compose/nginx/lab.conf /etc/nginx/nginx.conf ro' "$work/book.txt"
check "2b the Bagisto lab's web server mounts lab-bagisto.conf there, read-only" grep -qx 'mount compose/nginx/lab-bagisto.conf /etc/nginx/nginx.conf ro' "$work/bag.txt"
check "2c the other mount (the out directory), the entry and the read-only root are the same in the two" bash -c 'diff <(grep -v "/etc/nginx/nginx.conf" "$1") <(grep -v "/etc/nginx/nginx.conf" "$2") >/dev/null && [ "$(grep -c "^mount" "$2")" = 2 ]' _ "$work/book.txt" "$work/bag.txt"

# 3 ------------------------------------------------------------------------------------------------------------------------------
got="$(PEAK_LAB_NGINX_CONF=poc/lab/compose/nginx/lab.conf PEAK_LAB_COMPOSE_FILES="$lab/compose/lab.compose.yaml" "$lab/bin/with-bagisto" bash -c 'echo "$PEAK_LAB_NGINX_CONF"; echo "$PEAK_LAB_COMPOSE_FILES"' 2>&1)"
check "3a an own PEAK_LAB_NGINX_CONF and PEAK_LAB_COMPOSE_FILES in the environment do not win under the Bagisto wrapper" bash -c 'echo "$1" | head -1 | grep -qx "poc/lab/compose/nginx/lab-bagisto.conf" && echo "$1" | tail -1 | grep -q "lab.bagisto.override.yaml"' _ "$got"
PEAK_LAB_NGINX_CONF=poc/lab/compose/nginx/lab.conf PEAK_LAB_COMPOSE_FILES="$lab/compose/lab.compose.yaml" "$lab/bin/with-bagisto" bash -c ". '$lab/bin/_lab.sh'; compose config --format json" 2>/dev/null | reduce >"$work/bag2.txt" 2>/dev/null
check "3b and the rendered mount is still lab-bagisto.conf" grep -qx 'mount compose/nginx/lab-bagisto.conf /etc/nginx/nginx.conf ro' "$work/bag2.txt"

# 4 ------------------------------------------------------------------------------------------------------------------------------
nginx_run() {  # nginx_run <file> <flag>: the flag (-t or -T) on that file in a throw-away container: no network, no volume (tmpfs only), a read-only mount
  docker run --rm --network none --add-host lab-app:127.0.0.1 --read-only --tmpfs /tmp --tmpfs /out/web:mode=1777 \
    -v "$1:/etc/nginx/nginx.conf:ro" --entrypoint nginx "$nginx_image" "$2" 2>&1
}
for f in "$a" "$b"; do
  out="$(nginx_run "$f" -t)"; rc=$?
  check "4 nginx -t reads $(basename "$f")" bash -c '[ "$1" = 0 ] && echo "$2" | grep -q "test is successful"' _ "$rc" "$out"
done
printed="$(nginx_run "$b" -T)"
check "4c nginx -T on lab-bagisto.conf prints no keepalive and no fastcgi_keep_conn directive" bash -c 'echo "$1" | grep -q "fastcgi_pass lab_app" && ! echo "$1" | grep -qE "^ *(keepalive [0-9]|fastcgi_keep_conn) "' _ "$printed"
printed="$(nginx_run "$a" -T)"
check "4d and on lab.conf it prints both (the BookStack lab is unchanged)" bash -c 'echo "$1" | grep -qE "^ *keepalive 64;" && echo "$1" | grep -qE "^ *fastcgi_keep_conn on;"' _ "$printed"

# 5 ------------------------------------------------------------------------------------------------------------------------------
R="$work/repo"; mkdir -p "$R/poc/lab/compose/nginx" "$R/poc/lab/compose/php"
cp "$a" "$R/poc/lab/compose/nginx/lab.conf"; cp "$b" "$R/poc/lab/compose/nginx/lab-bagisto.conf"; cp "$lab/compose/php/zz-lab.ini" "$R/poc/lab/compose/php/zz-lab.ini"
git -C "$R" init -q . && git -C "$R" config user.email t@example.invalid && git -C "$R" config user.name t && git -C "$R" add -A && git -C "$R" commit -q -m "chore: seed"
sha_all() { (cd "$R" && sha256sum poc/lab/compose/nginx/lab.conf poc/lab/compose/nginx/lab-bagisto.conf poc/lab/compose/php/zz-lab.ini); }
cfg_env() { PEAK_REPO_ROOT="$R" PEAK_LAB_STATE_DIR="$work/state" PEAK_LAB_OUT_DIR="$work/out" "$@"; }
before="$(sha_all)"
cfg_env "$lab/bin/with-bagisto" "$lab/bin/lab-config-b" status >"$work/o" 2>&1
check "5a under the Bagisto wrapper, status says default" grep -q '^STATE default' "$work/o"
check "5a2 and it names lab-bagisto.conf, not lab.conf" bash -c 'grep -q "nginx/lab-bagisto.conf" "$1" && ! grep -q "nginx/lab.conf" "$1"' _ "$work/o"
cfg_env "$lab/bin/with-bagisto" "$lab/bin/lab-config-b" apply >"$work/o" 2>&1; rc=$?
check "5b apply under the Bagisto wrapper: 0" test "$rc" -eq 0
check "5c lab-bagisto.conf now has fastcgi_read_timeout 1800s" grep -q '^ *fastcgi_read_timeout 1800s;' "$R/poc/lab/compose/nginx/lab-bagisto.conf"
check "5d lab.conf is untouched (still 120s, same bytes as the seed)" bash -c 'cd "$1" && git diff --quiet -- poc/lab/compose/nginx/lab.conf && grep -q "^ *fastcgi_read_timeout 120s;" poc/lab/compose/nginx/lab.conf' _ "$R"
check "5e the PHP ini has max_execution_time=0 and memory_limit=4G" bash -c 'grep -q "^max_execution_time=0$" "$1" && grep -q "^memory_limit=4G$" "$1"' _ "$R/poc/lab/compose/php/zz-lab.ini"
diff "$R/poc/lab/compose/nginx/lab.conf" "$R/poc/lab/compose/nginx/lab-bagisto.conf" | grep -E '^[<>]' | sed 's/^\(.\) */\1 /' | sort >"$work/d2.txt"
printf '%s\n' '< fastcgi_keep_conn on;' '< fastcgi_read_timeout 120s;' '< keepalive 64;' '> fastcgi_read_timeout 1800s;' | sort >"$work/want2.txt"
check "5f with B on, the two nginx files differ by the two lines and the timeout, nothing else" cmp -s "$work/d2.txt" "$work/want2.txt"
cfg_env "$lab/bin/with-bagisto" "$lab/bin/lab-config-b" restore >"$work/o" 2>&1; rc=$?
check "5g restore under the Bagisto wrapper: 0" test "$rc" -eq 0
check "5h the three files are byte for byte what they were, git sees no change" bash -c '[ "$1" = "$2" ] && git -C "$3" diff --quiet' _ "$before" "$(sha_all)" "$R"
cfg_env "$lab/bin/lab-config-b" apply >"$work/o" 2>&1; rc=$?
check "5i without the wrapper, apply works on lab.conf (1800s) and leaves lab-bagisto.conf alone" bash -c '[ "$1" = 0 ] && grep -q "^ *fastcgi_read_timeout 1800s;" "$2/poc/lab/compose/nginx/lab.conf" && git -C "$2" diff --quiet -- poc/lab/compose/nginx/lab-bagisto.conf' _ "$rc" "$R"
cfg_env "$lab/bin/lab-config-b" restore >"$work/o" 2>&1
check "5j and restore gives the bytes back" bash -c '[ "$1" = "$2" ]' _ "$before" "$(sha_all)"
cfg_env env PEAK_LAB_NGINX_CONF=poc/lab/compose/nginx/other.conf "$lab/bin/lab-config-b" status >"$work/o" 2>&1; rc=$?
check "5k a nginx file with no diff is refused (2)" test "$rc" -eq 2

echo "passed=$passed failed=$failed skipped=$skipped"
[ "$failed" -eq 0 ] && [ "$skipped" -eq 0 ]
