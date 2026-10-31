#!/bin/sh
# Export the fixture app's artisan JSON once, on PHP 8.3, so the tool reads files and never calls artisan itself.
# (S-31(d): pre-export mode. The fixture app is Laravel 13 and does not run on PHP 8.2.)
#
# Run inside the php-dev container with the fixture app installed and migrated (see docker/README.md):
#   sh docker/export-artisan.sh
#
# Output (stdout and stderr are kept apart; display_errors=stderr; only valid JSON is written;
# absolute paths become <scrubbed>; see docs/research/artisan-compat.md section 3.4):
#   fixtures/artisan-export/route-list.json
#   fixtures/artisan-export/model-show/<Model>.json
set -eu

ROOT=$(cd "$(dirname "$0")/.." && pwd)
APP="$ROOT/fixtures/laravel-app"
OUT="$ROOT/fixtures/artisan-export"
MODELS="Customer Order Item Product ProductStat"

case $(php -r 'echo PHP_VERSION_ID >= 80300 ? "ok" : "old";') in
    ok) ;;
    *) echo "export-artisan: PHP 8.3 or newer is required (the fixture app is Laravel 13)" >&2; exit 1 ;;
esac

WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT

# php -r SCRUB <in> <out> <root>: decode, replace absolute paths, refuse leftovers, write stable pretty JSON.
SCRUB='
[$self, $in, $out, $root] = $argv;
$data = json_decode(file_get_contents($in), false, 512, JSON_THROW_ON_ERROR);
$needles = array_values(array_unique([$root . "/fixtures/laravel-app", $root]));
$left = 0;
$walk = function ($v) use (&$walk, $needles, &$left) {
    if (is_string($v)) {
        foreach ($needles as $n) {
            // Whole path segments only: "/app" must not eat the start of "hidden/appends".
            $v = preg_replace("#(?<![A-Za-z0-9_.\\/-])" . preg_quote($n, "#") . "(?![A-Za-z0-9_.-])#", "<scrubbed>", $v);
        }
        if (preg_match("#(^|[\\s\"\x27(=:])/(app|home|Users|root|tmp|var|usr|mnt|opt)/#", $v)) {
            $left++;
        }
        return $v;
    }
    if (is_array($v)) {
        return array_map($walk, $v);
    }
    if (is_object($v)) {
        foreach (get_object_vars($v) as $k => $x) {
            $v->$k = $walk($x);
        }
    }
    return $v;
};
$data = $walk($data);
if ($left > 0) {
    fwrite(STDERR, "export-artisan: $left string(s) still look like absolute paths\n");
    exit(3);
}
file_put_contents($out, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) . "\n");
'

# export_one <name> <out-file> <artisan args...>
export_one() {
    name=$1; dest=$2; shift 2
    raw="$WORK/$name.stdout"; err="$WORK/$name.stderr"
    code=0
    (cd "$APP" && php -d display_errors=stderr artisan "$@" >"$raw" 2>"$err") || code=$?
    if [ "$code" -ne 0 ]; then
        echo "export-artisan: '$*' exited $code" >&2
        cat "$err" >&2
        head -c 2000 "$raw" >&2
        exit 1
    fi
    if [ -s "$err" ]; then
        echo "export-artisan: '$*' wrote to stderr (a warning); refusing to export" >&2
        cat "$err" >&2
        exit 1
    fi
    php -r "$SCRUB" "$raw" "$WORK/$name.json" "$ROOT" || { echo "export-artisan: '$*' did not give clean JSON" >&2; exit 1; }
    mkdir -p "$(dirname "$dest")"
    mv "$WORK/$name.json" "$dest"
    echo "export-artisan: wrote ${dest#"$ROOT"/}"
}

export_one route-list "$OUT/route-list.json" route:list --json
for m in $MODELS; do
    export_one "model-$m" "$OUT/model-show/$m.json" model:show "$m" --json
done
