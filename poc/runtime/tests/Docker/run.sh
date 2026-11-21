#!/usr/bin/env bash
# Runs the RuntimeDocker suite from the host, in two stages. PHP is not needed on the host.
#
#   bash poc/runtime/tests/Docker/run.sh [--stage=1|2|all] [phpunit args for stage 1...]
#
# Stage 1 (the sealed side): a driver container (PHP image of this repo, with the Docker CLI and socket) runs every test
#   that is NOT in the group `in-stack`. These tests start and tear down real stacks themselves, including a
#   deliberately leaky one, and run bin/measure from outside.
# Stage 2 (the inside): one sealed stack is brought up here, and the tests of the group `in-stack` run INSIDE its PHP
#   container (bin/stack exec ... --group in-stack). They need the container's environment: the DB service, the
#   measure user, the app mounted read-only. The stack is torn down afterwards, whatever happened.
#
# Environment: PHP_VERSION (default 8.3), PEAK_COMPOSE_PROJECT (default peak-measure-dev; stage 2 adds "-in"; the tests
#   of stage 1 add their own suffixes), PEAK_EVIDENCE_DIR (default: the evidence directory beside the trials; set your
#   own to leave the shared one untouched), PEAK_OUT_DIR (run directory, mounted into the stacks).
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo="$(cd "$here/../../../.." && pwd)"
php="${PHP_VERSION:-8.3}"
project="${PEAK_COMPOSE_PROJECT:-peak-measure-dev}"
case "$project" in
  peak-measure-dev*) ;;
  *) echo "run.sh: PEAK_COMPOSE_PROJECT must start with peak-measure-dev (these tests remove the database volumes of their projects): $project" >&2; exit 2 ;;
esac
evidence="${PEAK_EVIDENCE_DIR:-$HOME/projects/peak-sim-trials/_out/runtime/evidence}"
out="${PEAK_OUT_DIR:-$HOME/projects/peak-sim-trials/_out/runtime/out-$project}"
mkdir -p "$evidence" "$out"

stage="all"
case "${1:-}" in
  --stage=1|--stage=2|--stage=all) stage="${1#--stage=}"; shift ;;
esac

docker_bin="$(readlink -f "$(command -v docker)")"
compose_plugin="$HOME/.docker/cli-plugins/docker-compose"
sock_gid="$(stat -c %g /var/run/docker.sock)"

git -C "$repo" rev-parse HEAD > "$evidence/commit.txt"
git -C "$repo" status --porcelain -- poc > "$evidence/poc-worktree-status.txt" || true

status=0

if [ "$stage" = "1" ] || [ "$stage" = "all" ]; then
  # The repository and the run directory are also mounted at their own host paths, so a test can hand bin/measure a path
  # that both this container and the Docker daemon can see.
  docker run --rm \
    -u "$(id -u):$(id -g)" --group-add "$sock_gid" \
    -v /var/run/docker.sock:/var/run/docker.sock \
    -v "$docker_bin:/usr/local/bin/docker:ro" \
    -v "$compose_plugin:/usr/local/lib/docker/cli-plugins/docker-compose:ro" \
    -v "$repo:/app:ro" -v "$repo:$repo:ro" -v "$evidence:/evidence" -v "$out:$out" \
    -e HOME=/tmp -e PEAK_REPO_ROOT="$repo" -e PEAK_OUT_DIR="$out" \
    -e PEAK_EVIDENCE_DIR=/evidence -e PEAK_COMPOSE_PROJECT="$project" -e PHP_VERSION="$php" \
    -w /app "peak-sim-php-dev:$php" \
    vendor/bin/phpunit -c poc/runtime/phpunit.runtime.xml --testsuite RuntimeDocker --exclude-group in-stack "$@" || status=$?
fi

if [ "$stage" = "2" ] || [ "$stage" = "all" ]; then
  (
    export PEAK_COMPOSE_PROJECT="$project-in" PEAK_OUT_DIR="$out" PEAK_REPO_ROOT="$repo" PHP_VERSION="$php"
    trap 'bash "$repo/poc/runtime/bin/stack" down --purge >/dev/null 2>&1' EXIT
    bash "$repo/poc/runtime/bin/stack" up >/dev/null
    bash "$repo/poc/runtime/bin/stack" exec /peak-sim/vendor/bin/phpunit -c /peak-sim/poc/runtime/phpunit.runtime.xml --testsuite RuntimeDocker --group in-stack
  ) || status=$?
fi

exit "$status"
