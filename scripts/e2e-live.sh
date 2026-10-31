#!/usr/bin/env bash
# End-to-end check with a real artisan (PHP 8.3 only): the fixture Laravel 13 app and its MySQL are started in Docker,
# peak-sim reads the app once through the real artisan (--artisan-command) and once through the files exported
# beforehand (--export-dir), and the two JSON reports must be identical except for input.mode.
#
#   scripts/e2e-live.sh <compose-project>
#   scripts/e2e-live.sh --self-test
#
# This is not part of the PHPUnit suites (they must run with nothing skipped on PHP 8.2 and 8.3; see
# phpunit.live.xml and docs/research for how the two are told apart). <compose-project> is required (no default), and
# only that project is torn down at the end (docker compose down -v), whatever the result.
# The fixture app's vendor/ and .env are created inside fixtures/laravel-app (both are git-ignored).
# Exit status: 0 identical; 1 the reports differ; 2 usage; 3 a step failed before the comparison.
set -euo pipefail

usage() {
    cat >&2 <<'EOF'
usage: scripts/e2e-live.sh <compose-project>
       scripts/e2e-live.sh --self-test
EOF
}

ROOT=$(cd "$(dirname "$0")/.." && pwd)

# The comparison, run with php inside the container: php /dev/stdin <export.json> <live.json>
# Both reports are decoded, input.mode is taken out (and checked to be the two expected, different, values), and the
# rest must be equal. On a difference the first differing path is printed, then the values on both sides.
read -r -d '' COMPARE_PHP <<'PHP' || true
<?php
[$self, $exportFile, $liveFile] = $argv;
$load = static function (string $file): array {
    $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data)) {
        fwrite(STDERR, "e2e-live: $file is not a JSON object\n");
        exit(3);
    }
    return $data;
};
$a = $load($exportFile);
$b = $load($liveFile);
$modeA = $a['input']['mode'] ?? null;
$modeB = $b['input']['mode'] ?? null;
if ($modeA !== 'export-dir' || $modeB !== 'artisan-command') {
    fwrite(STDERR, "e2e-live: input.mode is " . json_encode($modeA) . " and " . json_encode($modeB)
        . "; expected \"export-dir\" and \"artisan-command\" (the two input paths were not both used)\n");
    exit(3);
}
unset($a['input']['mode'], $b['input']['mode']);

$first = static function (mixed $x, mixed $y, string $path) use (&$first): ?array {
    if (is_array($x) && is_array($y)) {
        foreach (array_unique(array_merge(array_keys($x), array_keys($y))) as $k) {
            if (!array_key_exists($k, $x) || !array_key_exists($k, $y)) {
                return [$path . '/' . $k, array_key_exists($k, $x) ? $x[$k] : '(absent)', array_key_exists($k, $y) ? $y[$k] : '(absent)'];
            }
            $d = $first($x[$k], $y[$k], $path . '/' . $k);
            if ($d !== null) {
                return $d;
            }
        }
        return null;
    }
    return $x === $y ? null : [$path, $x, $y];
};
$diff = $first($a, $b, '');
$routes = is_array($a['routes'] ?? null) ? count($a['routes']) : 0;
if ($diff === null) {
    if ($routes === 0) {
        fwrite(STDERR, "e2e-live: the reports are identical but contain no routes; that proves nothing\n");
        exit(3);
    }
    echo "identical: $routes routes (input.mode excluded)\n";
    exit(0);
}
echo "DIFFERENT at " . ($diff[0] === '' ? '(root)' : $diff[0]) . "\n";
echo "  export-dir:       " . json_encode($diff[1], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
echo "  artisan-command:  " . json_encode($diff[2], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
exit(1);
PHP

self_test() {
    # The comparison needs php, which exists only in Docker; here only the usage handling can be checked.
    local rc=0
    bash "$0" >/dev/null 2>&1 || rc=$?
    [ "$rc" -eq 2 ] || { echo "self-test: no argument should exit 2, got $rc" >&2; return 1; }
    rc=0
    bash "$0" 'Bad Name' >/dev/null 2>&1 || rc=$?
    [ "$rc" -eq 2 ] || { echo "self-test: a bad project name should exit 2, got $rc" >&2; return 1; }
    echo "self-test: ok (usage errors exit 2; the comparison itself runs in the container)"
}

PROJECT=""
if [ "$#" -eq 0 ]; then
    usage
    echo "e2e-live: the compose project name is required" >&2
    exit 2
fi
for arg in "$@"; do
    case $arg in
        --self-test) self_test; exit $? ;;
        -h | --help) usage; exit 0 ;;
        -*) usage; echo "e2e-live: unknown option: $arg" >&2; exit 2 ;;
        *)
            if [ -n "$PROJECT" ]; then
                usage; echo "e2e-live: only one compose project name can be given" >&2; exit 2
            fi
            PROJECT=$arg
            ;;
    esac
done
printf '%s' "$PROJECT" | grep -Eq '^[a-z0-9][a-z0-9_-]*$' || { echo "e2e-live: the compose project name must be lower-case letters, digits, '-' or '_'" >&2; exit 2; }
command -v docker >/dev/null 2>&1 || { echo "e2e-live: docker is required (PHP and MySQL run only in Docker)" >&2; exit 2; }

export HOST_UID=${HOST_UID:-$(id -u)}
export HOST_GID=${HOST_GID:-$(id -g)}
export PHP_VERSION=8.3

dc() {
    docker compose -f "$ROOT/docker/compose.yaml" -p "$PROJECT" "$@"
}
in_app() {
    dc exec -T php-dev sh -c "$1"
}

# shellcheck disable=SC2329 # called through trap
cleanup() {
    # Only this run's own project: containers, network and the database volume.
    dc down -v >/dev/null 2>&1 || true
}
trap cleanup EXIT

step() {
    echo "e2e-live: $*" >&2
}
fail() {
    echo "e2e-live: failed at: $1" >&2
    exit 3
}

step "starting php-dev (PHP 8.3) and MySQL"
dc up -d --build --wait >&2 || fail "docker compose up"

step "installing the fixture app and migrating the database"
in_app 'cd fixtures/laravel-app && composer install --no-interaction --no-progress && cp -n .env.example .env && php artisan key:generate --no-interaction && php artisan migrate:fresh --seed --no-interaction' >&2 \
    || fail "fixture app install / migrate"

step "reading the app from the exported files (--export-dir)"
in_app 'mkdir -p /tmp/e2e && php bin/peak-sim analyze fixtures/laravel-app --export-dir=fixtures/artisan-export --format=json >/tmp/e2e/export.json 2>/tmp/e2e/export.err' \
    || { in_app 'cat /tmp/e2e/export.err' >&2 || true; fail "analysis with --export-dir"; }

step "reading the app through the real artisan (--artisan-command)"
in_app 'php bin/peak-sim analyze fixtures/laravel-app "--artisan-command=php -d display_errors=stderr artisan" --format=json >/tmp/e2e/live.json 2>/tmp/e2e/live.err' \
    || { in_app 'cat /tmp/e2e/live.err' >&2 || true; fail "analysis with --artisan-command"; }

step "comparing the two reports"
rc=0
dc exec -T php-dev php /dev/stdin /tmp/e2e/export.json /tmp/e2e/live.json <<<"$COMPARE_PHP" || rc=$?
case $rc in
    0) echo "e2e-live: OK" >&2 ;;
    1) echo "e2e-live: the reports differ" >&2 ;;
    *) echo "e2e-live: the comparison could not be made (exit $rc)" >&2; rc=3 ;;
esac
exit "$rc"
