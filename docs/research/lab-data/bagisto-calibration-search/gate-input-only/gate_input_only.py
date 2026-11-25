#!/usr/bin/env python3 -I
"""Inputs-only gate over R_3 in {0.3,0.37} x R_4 in 14 values x R_0,R_1 in 6 values x R_2 in 14 values (same call as gategrid.py).
usage: gate5c.py <container> <out.tsv> <workdir-host>"""
import json, subprocess, sys, os
from concurrent.futures import ThreadPoolExecutor
ctr, out, work = sys.argv[1], sys.argv[2], sys.argv[3]
RG = ["1","2","4","8","16","32"]; R2G = ["0.05","0.07","0.1","0.15","0.2","0.3","0.37","0.45","0.7","1","1.5","2.2","3.3","5"]
R3 = ["0.3","0.37"]; R4 = R2G
P = "/rec/plans"
def one(t):
    r3, r4, r0, r1, r2 = t
    tag = f"r3-{r3}_r4-{r4}_r0-{r0}_r1-{r1}_r2-{r2}"
    cmd = ["docker","exec",ctr,"php","/peak-sim/poc/lab/scenarios/calibration-gate.php",
      "--estimate=/rec/estimate-shared","--scale=10000","--tables=n_shared",
      "--run-scale=cal-4:3000","--run-scale=cal-5:30000","--run-scale=cal-6:30000","--run-scale=cal-7:30000",
      f"--run=cal-1:{P}/even_r{r0}.json",f"--run=cal-2:{P}/first80_r{r0}.json",f"--run=cal-3:{P}/odd80_r{r0}.json",
      f"--run=cal-4:{P}/even_r{r1}.json",f"--run=cal-5:{P}/even_r{r2}.json",
      f"--run=cal-6:{P}/top1_r{r3}.json",f"--run=cal-7:{P}/top2_r{r4}.json",
      f"--out=/w/{tag}.json"]
    p = subprocess.run(cmd, capture_output=True, text=True)
    try:
        d = json.load(open(f"{work}/{tag}.json")); md = "%.6f" % d["min_degree"]; lo = d["min_left_out"]
    except Exception:
        md = "ERR"; lo = (p.stderr or p.stdout)[-80:].replace("\n"," ")
    return f"{r3}\t{r4}\t{r0}\t{r1}\t{r2}\t{p.returncode}\t{md}\t{lo}"
tasks = [(a,b,r0,r1,r2) for a in R3 for b in R4 for r0 in RG for r1 in RG for r2 in R2G]
with ThreadPoolExecutor(8) as ex, open(out, "w") as f:
    f.write("R_3\tR_4\tR_0\tR_1\tR_2\tgate_exit\tsmallest_degree\tleft_out\n")
    for line in ex.map(one, tasks):
        f.write(line + "\n"); f.flush()
