#!/usr/bin/env bash
# run_diag.sh: the diagnostic run (cal-8 at 32 rps, the plan and the rates of cal8-r32), through search8.sh. Needs PEAK_SIM_REPO and PEAK_CAPTURE_JSON.
set -uo pipefail
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
export SEARCH_GATE_ADVISORY=1
bash "$here/../search8.sh" cal-8 cal8-r32-diag 2 8 1 0.2 1 32
rc=$?
# (the original also sent one line to the person who started the run when it ended; that line is left out of this copy)
exit $rc
