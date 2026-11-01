#!/usr/bin/env bash
# Shared setup for the host-side scripts (sourced, not run). Gives the compose its host paths and throwaway
# values. Nothing here is a secret worth keeping: the stack is destroyed after a run.
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

compose() { docker compose -p "$PEAK_COMPOSE_PROJECT" -f "$PEAK_COMPOSE_FILE" "$@"; }

# Run a PHP script of poc/runtime inside the sealed PHP container.
in_php() { compose exec -T measure-php php "$@"; }
