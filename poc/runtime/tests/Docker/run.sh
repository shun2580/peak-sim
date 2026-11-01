#!/usr/bin/env bash
# Runs the RuntimeDocker suite from the host. PHP is not needed on the host: the tests run in a driver container
# (PHP image of this repo) that has the Docker CLI and socket, and they start the measurement stacks through them.
#
#   bash poc/runtime/tests/Docker/run.sh [phpunit args...]
#
# Environment: PHP_VERSION (default 8.3), PEAK_EVIDENCE_DIR (default: the evidence directory beside the trials),
#              PEAK_COMPOSE_PROJECT (default peak-measure-dev; the tests add a suffix).
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo="$(cd "$here/../../../.." && pwd)"
php="${PHP_VERSION:-8.3}"
project="${PEAK_COMPOSE_PROJECT:-peak-measure-dev}"
evidence="${PEAK_EVIDENCE_DIR:-$HOME/projects/peak-sim-trials/_out/runtime/evidence}"
out="${PEAK_OUT_DIR:-$HOME/projects/peak-sim-trials/_out/runtime/out-$project}"
mkdir -p "$evidence" "$out"

docker_bin="$(readlink -f "$(command -v docker)")"
compose_plugin="$HOME/.docker/cli-plugins/docker-compose"
sock_gid="$(stat -c %g /var/run/docker.sock)"

git -C "$repo" rev-parse HEAD > "$evidence/commit.txt"
git -C "$repo" status --porcelain -- poc > "$evidence/poc-worktree-status.txt" || true

exec docker run --rm \
  -u "$(id -u):$(id -g)" --group-add "$sock_gid" \
  -v /var/run/docker.sock:/var/run/docker.sock \
  -v "$docker_bin:/usr/local/bin/docker:ro" \
  -v "$compose_plugin:/usr/local/lib/docker/cli-plugins/docker-compose:ro" \
  -v "$repo:/app:ro" -v "$evidence:/evidence" \
  -e HOME=/tmp -e PEAK_REPO_ROOT="$repo" -e PEAK_OUT_DIR="$out" \
  -e PEAK_EVIDENCE_DIR=/evidence -e PEAK_COMPOSE_PROJECT="$project" -e PHP_VERSION="$php" \
  -w /app "peak-sim-php-dev:$php" \
  vendor/bin/phpunit -c poc/runtime/phpunit.runtime.xml --testsuite RuntimeDocker "$@"
