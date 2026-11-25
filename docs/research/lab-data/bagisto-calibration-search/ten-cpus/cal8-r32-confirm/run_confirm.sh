#!/usr/bin/env bash
# run_confirm.sh: the confirming run (cal-8 at 32 rps, the plan and the rates of cal8-r32), through search8.sh. Needs PEAK_SIM_REPO and PEAK_CAPTURE_JSON.
set -uo pipefail
export SEARCH_GATE_ADVISORY=1
bash "$PEAK_SIM_REPO/docs/research/lab-data/bagisto-calibration-search/ten-cpus/search8.sh" cal-8 cal8-r32-confirm 2 8 1 0.2 1 32
rc=$?
exit $rc
