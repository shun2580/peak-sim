#!/usr/bin/env bash
# Test A prep (static check): static check, no Docker. Every option the three scripts pass to bin/measure and bin/variance-run is an option the
# copy's own option parser knows (read from the case patterns of the real scripts). Prints MISSING for any that is not.
R=$HOME/projects/peak-sim-trials/_scratch/run-286-t5a/rep-7a4cf32
accepted() { grep -oE -- '--[a-z-]+(=\*)?\)' "$1" | sed -e 's/[=*)]//g' | sort -u; }
check() { # tool options...
  local tool=$1; shift
  local acc; acc=$(accepted "$R/poc/runtime/bin/$tool")
  local o bad=0
  for o in "$@"; do
    if echo "$acc" | grep -qx -- "$o"; then echo "$tool $o ok"; else echo "$tool $o MISSING"; bad=1; fi
  done
  return $bad
}
rc=0
check measure --app --static --data --scales --profile --only --request-timeout --warm --out || rc=1
check variance-run --app --tasks --scale --out --label --routes --dbs --laps --data --profile --php-image --stats-auto-recalc --tap --project-prefix --source-commit --request-timeout --keep-stack || rc=1
exit $rc
