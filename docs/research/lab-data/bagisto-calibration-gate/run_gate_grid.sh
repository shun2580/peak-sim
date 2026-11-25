#!/usr/bin/env bash
# Runs the calibration gate (poc/lab/scenarios/calibration-gate) over a grid of request rates, from inputs only: an estimate directory, a route list and
# the plans made from it. It reads files and starts no database, no application and no lab container.
#
#   [JOBS=4] run_gate_grid.sh <work dir> <estimate dir (with estimate.json, shared-table copy)> <route-list.json> <set: 5|7>
#
# Set 5 = cal-1..cal-5; set 7 = cal-1..cal-7 (cal-6: the route ranked 1 at 80% and the other seven at 20% together; cal-7: the same for rank 2).
# Grid: R_0 (cal-1..3) and R_1 (cal-4) in 1, 2, 4, 8, 16, 32; R_2 (cal-5) and R_3 (cal-6 and cal-7) in the stage values plus 0.37.
# Counts: cal-1..3 at 10,000, cal-4 at 3,000, cal-5..7 at 30,000, all N tables at one count (the pseudo table n_shared).
# Writes <work dir>/grid<set>.tsv: set, R_0, R_1, R_2, R_3 ("-" for set 5), gate exit code, smallest degree, the run left out in the smallest set.
# In set 7 the rate R_3 is one value for cal-6 and cal-7 together. Set 7 is 7,056 calls of the gate (about 0.5 s each; JOBS runs them side by side).
set -uo pipefail
work="$1"; est="$2"; routes="$3"; set_="$4"
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo="$(cd "$here/../../../.." && pwd)"
if [ "${5:-}" != "--one" ]; then
mkdir -p "$work/plans" "$work/gate_out"
python3 -I - "$routes" "$work" <<'PY'
import json, sys
rl = [s["route"] for s in json.load(open(sys.argv[1]))["selected"]]
for k, name in ((0, "top1"), (1, "top2")):
    rps = {r: (0.8 if i == k else 0.2 / 7) for i, r in enumerate(rl)}
    json.dump({"rps_after": rps, "dropped": []}, open("%s/alloc_%s.json" % (sys.argv[2], name), "w"), indent=1)
PY
img="${PEAK_DEV_IMAGE:-peak-sim-php-dev:8.3}"
mkplan() {  # mkplan <out> <make-plan args>
  local out="$1"; shift
  docker run --rm --pull=never --network none --read-only -u "$(id -u):$(id -g)" -v "$repo:/peak-sim:ro" -v "$work:/w:rw" -v "$(dirname "$routes"):/r:ro" \
    "$img" nice php /peak-sim/poc/lab/load/make-plan.php --routes="/r/$(basename "$routes")" "$@" > "$out"
}
RG="1 2 4 8 16 32"; R2G="0.05 0.07 0.1 0.15 0.2 0.3 0.37 0.45 0.7 1 1.5 2.2 3.3 5"
for r in $RG; do for m in even first80 odd80; do mkplan "$work/plans/${m}_r$r.json" --mix=$m --rps=$r; done; done
for r in $R2G; do
  mkplan "$work/plans/even_r$r.json" --mix=even --rps=$r
  mkplan "$work/plans/top1_r$r.json" --mix=top1_80 --rps=$r --allocation=/w/alloc_top1.json
  mkplan "$work/plans/top2_r$r.json" --mix=top2_80 --rps=$r --allocation=/w/alloc_top2.json
done
fi
gate1() {  # gate1 <R0> <R1> <R2> <R3>   (R3 is "-" for set 5)
  local tag="set${set_}_r0-$1_r1-$2_r2-$3$([ "$set_" = 7 ] && echo "_r3-$4")"
  local args=(--estimate="$est" --scale=10000 --tables=n_shared --run-scale=cal-4:3000 --run-scale=cal-5:30000
    --run=cal-1:"$work/plans/even_r$1.json" --run=cal-2:"$work/plans/first80_r$1.json" --run=cal-3:"$work/plans/odd80_r$1.json"
    --run=cal-4:"$work/plans/even_r$2.json" --run=cal-5:"$work/plans/even_r$3.json" --out="$work/gate_out/$tag.json")
  if [ "$set_" = 7 ]; then
    args+=(--run-scale=cal-6:30000 --run-scale=cal-7:30000 --run=cal-6:"$work/plans/top1_r$4.json" --run=cal-7:"$work/plans/top2_r$4.json")
  fi
  "$repo/poc/lab/scenarios/calibration-gate" "${args[@]}" > "$work/gate_out/$tag.txt" 2> "$work/gate_out/$tag.err"
  local rc=$?
  python3 -I -c "
import json
d = json.load(open('$work/gate_out/$tag.json'))
print('%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s' % ('$set_', '$1', '$2', '$3', '$4', $rc, '%.6f' % d['min_degree'], d['min_left_out']))"
}
if [ "${5:-}" = "--one" ]; then gate1 "$6" "$7" "$8" "$9"; exit 0; fi
printf 'set\tR_0\tR_1\tR_2\tR_3\tgate_exit\tsmallest_degree\tleft_out_in_smallest_set\n' > "$work/grid$set_.tsv"
{
  for r0 in $RG; do for r1 in $RG; do for r2 in $R2G; do
    if [ "$set_" = 7 ]; then for r3 in $R2G; do echo "$r0 $r1 $r2 $r3"; done; else echo "$r0 $r1 $r2 -"; fi
  done; done; done
} | xargs -P "${JOBS:-1}" -L 1 bash -c '"$0" "$1" "$2" "$3" "$4" --one "$5" "$6" "$7" "$8"' "${BASH_SOURCE[0]}" "$work" "$est" "$routes" "$set_" >> "$work/grid$set_.tsv"
