#!/usr/bin/env bash
# recheck_nginx.sh <run dir with settings-nginx.txt> [more run dirs]: the reading of the nginx settings file for the same-conditions check
# (written before the fifth rate search; the two notice lines of the config test are taken off both files, nothing is rewritten).
#  - the two notice lines are the lines "nginx: the configuration file ... syntax is ok" and "nginx: configuration file ... test is successful"
#    of the confirming run's file; each must appear as a whole line exactly once in the file under check;
#  - the rest of both files must then be the same, byte for byte.
# Reads only. Prints one line per run dir (the text of the notice lines is not printed).
set -uo pipefail
REF=$PEAK_SIM_REPO/docs/research/lab-data/bagisto-calibration-search/ten-cpus/cal8-r32-confirm/cal-8/settings-nginx.txt
python3 -I - "$REF" "$@" <<'PY'
import sys
ref = open(sys.argv[1], "rb").read().split(b"\n")
def notice(ls):
    return [l for l in ls if l.startswith(b"nginx: ") and (l.endswith(b" syntax is ok") or l.endswith(b" test is successful"))]
rn = notice(ref)
assert len(rn) == 2 and len(set(rn)) == 2, "the confirming file does not hold exactly the two notice lines"
rest_ref = [l for l in ref if l not in rn]
for d in sys.argv[2:]:
    ls = open(d + "/settings-nginx.txt", "rb").read().split(b"\n")
    counts = [ls.count(l) for l in rn]
    others = [l for l in notice(ls) if l not in rn]
    rest = [l for l in ls if l not in rn]
    ok_n = counts == [1, 1] and not others
    ok_r = rest == rest_ref
    print("%s: notice lines once each: %s; other notice-like lines: %d; rest same byte for byte: %s; lines %d vs %d -> %s" % (
        d, "yes" if ok_n else "NO %s" % counts, len(others), "yes" if ok_r else "NO", len(ls), len(ref), "SAME" if ok_n and ok_r else "DIFFERENT"))
PY
