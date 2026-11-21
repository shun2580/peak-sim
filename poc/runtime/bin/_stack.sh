#!/usr/bin/env bash
# Shared setup for the host-side scripts (sourced, not run). Gives the compose its host paths and throwaway
# values. Nothing here is a secret worth keeping: the passwords are made again for every run, and the stack's containers
# are removed after it. The database volume is kept unless --purge is given (see stack_down).
set -euo pipefail

_here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RUNTIME_DIR="$(cd "$_here/.." && pwd)"

# PEAK_REPO_ROOT is the path the Docker daemon sees. It differs from RUNTIME_DIR's parent only when the scripts
# run inside a driver container (the RuntimeDocker tests), which sets it explicitly.
: "${PEAK_REPO_ROOT:=$(cd "$RUNTIME_DIR/../.." && pwd)}"
: "${PEAK_COMPOSE_PROJECT:=peak-measure}"
: "${PEAK_COMPOSE_FILE:=$RUNTIME_DIR/compose/measure.compose.yaml}"
: "${PEAK_OUT_DIR:=${HOME}/.cache/peak-measure/${PEAK_COMPOSE_PROJECT}}"

_rand() { head -c 18 /dev/urandom | od -An -tx1 | tr -d ' \n'; }
: "${PEAK_MEASURE_DB_ROOT_PASSWORD:=$(_rand)}"
: "${PEAK_MEASURE_DB_PASSWORD:=$(_rand)}"
: "${PEAK_MEASURE_RUN_ID:=$(head -c 6 /dev/urandom | od -An -tx1 | tr -d ' \n')}"

export PEAK_REPO_ROOT PEAK_COMPOSE_PROJECT PEAK_OUT_DIR
export PEAK_MEASURE_DB_ROOT_PASSWORD PEAK_MEASURE_DB_PASSWORD PEAK_MEASURE_RUN_ID
[ -n "${PEAK_APP_ROOT:-}" ] && export PEAK_APP_ROOT

mkdir -p "$PEAK_OUT_DIR" 2>/dev/null || true

# PEAK_PHP_IMAGE runs the stack in another, already built PHP image. An `up` then never builds: a build would tag the
# default Dockerfile's result with that name and overwrite the image. The image must already be on this machine
# (made with `bin/stack build-image`); it is never pulled.
compose() {
  local args=() arg is_up=0
  for arg in "$@"; do
    [ "$arg" = "up" ] && is_up=1
    args+=("$arg")
  done
  if [ -n "${PEAK_PHP_IMAGE:-}" ] && [ "$is_up" = 1 ]; then
    if ! docker image inspect "$PEAK_PHP_IMAGE" >/dev/null 2>&1; then
      echo "PEAK_PHP_IMAGE=$PEAK_PHP_IMAGE is not an image on this machine. Build it first: PEAK_PHP_EXTENSIONS=<a,b> bin/stack build-image $PEAK_PHP_IMAGE" >&2
      return 1
    fi
    local rewritten=()
    for arg in "${args[@]}"; do
      [ "$arg" = "--build" ] || rewritten+=("$arg")
    done
    args=("${rewritten[@]}" --no-build)
  fi
  docker compose -p "$PEAK_COMPOSE_PROJECT" -f "$PEAK_COMPOSE_FILE" "${args[@]}"
}

# The one place that removes the stack's database volume. Without an argument (or with 0) it removes the containers and
# the network and keeps the volume, so a database that took hours to build survives every clean-up. Only an explicit 1
# (the scripts' --purge) adds --volumes. No other `down` in this repository may remove a volume.
stack_down() { # $1: 1 = remove the database volume as well
  if [ "${1:-0}" = 1 ]; then
    compose down --volumes --remove-orphans
  else
    compose down --remove-orphans
  fi
}

# Refuses (returns 2) when the database volume of this compose project is still there from an earlier run: the volume
# holds a database made with other throwaway passwords, which mysql would keep, so the new stack could not log in.
# Call it before anything is started and before the clean-up trap is set, so a refused run cannot purge what it refused.
refuse_if_kept_volume() {
  local kept
  kept="$(docker volume ls -q \
    --filter "label=com.docker.compose.project=$PEAK_COMPOSE_PROJECT" \
    --filter "label=com.docker.compose.volume=measure-dbdata" 2>/dev/null)" || return 0
  [ -n "$kept" ] || return 0
  {
    echo "bin/stack: the database volume of project '$PEAK_COMPOSE_PROJECT' is still there from an earlier run (it holds a database made with other throwaway passwords)."
    echo "Use another PEAK_COMPOSE_PROJECT, or remove it on purpose: PEAK_COMPOSE_PROJECT=$PEAK_COMPOSE_PROJECT bin/stack down --purge"
  } >&2
  return 2
}

# Run a PHP script of poc/runtime inside the sealed PHP container.
in_php() { compose exec -T measure-php php "$@"; }
