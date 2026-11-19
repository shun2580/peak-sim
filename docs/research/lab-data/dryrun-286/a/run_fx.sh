#!/usr/bin/env bash
set -uo pipefail
# T: trials directory, R: scratch clone of the repository, O: output directory (set by the caller).
: "${T:?set T}" "${R:?set R}" "${O:?set O}"
P=$R/poc/lab/predictions
cd "$R"
echo "start_iso=$(date -Iseconds) tool_commit=$(git rev-parse HEAD) project=peak-dr6-a-fx" > $O/fixtures.order.txt
PEAK_COMPOSE_PROJECT=peak-dr6-a-fx bash $R/poc/lab/bin/measure-extrapolation \
  --estimate=$P/extrapolation-3-fixtures-estimate.json --marked=$P/extrapolation-3-fixtures-marked.json measure -- \
  --app=$R/fixtures/laravel-app --static=$T/_out/extrapolation/fixtures/analysis.json --data=seeder --scales=30000 \
  --only='=/ok/eager,=/ng/n-plus-one,=/ok/bulk,=/ng/first-or-create-in-loop' --request-timeout=600 --warm=off \
  --out=$O/fixtures > $O/fixtures.stdout.log 2> $O/fixtures.stderr.log
rc=$?
echo "exit=$rc end_iso=$(date -Iseconds)" >> $O/fixtures.order.txt
echo "$(date -Iseconds) A dry run on the fixtures app: measure finished exit=$rc"
