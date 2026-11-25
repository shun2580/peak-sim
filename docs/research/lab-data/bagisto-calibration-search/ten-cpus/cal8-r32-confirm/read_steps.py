import re, sys
# read_steps.py <k6-output.txt>: the steps (type iii) after the load window, read from the k6 'running' lines.
# window end = first running line at or after 10m00s (all executors reach 100% there); a step = the rise of 'complete' bundled over runs of rises
# that follow each other within 5 s; 'have' = steps of 15-16 at 55-70 s apart linked in 2 or more; 'weak' = 2+ runs of 13-18, or a single 15-16.
txt = open(sys.argv[1], errors='replace').read().replace('\r', '\n')
rows = []
for m in re.finditer(r'running \((\d+)m(\d+(?:\.\d+)?)s\), (\d+)/(\d+) VUs, (\d+) complete', txt):
    t = int(m.group(1))*60 + float(m.group(2)); rows.append((t, int(m.group(3)), int(m.group(5))))
end = next((i for i, r in enumerate(rows) if r[0] >= 600), None)
if end is None: print("no window end seen"); sys.exit(0)
after = rows[end:]
steps = []  # (time of first rise, size)
cur = None; prev_c = after[0][2]; prev_t = after[0][0]
for t, v, c in after[1:]:
    if c > prev_c:
        if cur is not None and t - cur[1] <= 5: cur[1] = t; cur[2] += c - prev_c
        else:
            if cur: steps.append((cur[0], cur[2]))
            cur = [t, t, c - prev_c]
    prev_c, prev_t = c, t
if cur: steps.append((cur[0], cur[2]))
print("window end at k6 t=%.1fs; after: %d rows to t=%.1fs; last VUs=%d; complete %d -> %d" % (after[0][0], len(after), after[-1][0], after[-1][1], after[0][2], after[-1][2]))
print("steps (t, size):", [(round(t), s) for t, s in steps if t - after[0][0] > 5][:60])
big = [(t, s) for t, s in steps if 15 <= s <= 16 and t - after[0][0] > 5]
chain = best = 0; last = None
for t, s in big:
    if last is not None and 55 <= t - last <= 70: chain += 1
    else: chain = 1
    best = max(best, chain); last = t
runs = [(t, s) for t, s in steps if 13 <= s <= 18 and t - after[0][0] > 5]
print("15-16 steps: %d; longest link of 55-70 s gaps: %d; 13-18 runs: %d" % (len(big), best, len(runs)))
print("type iii:", "have" if best >= 2 else ("weak" if len(runs) >= 2 or len(big) == 1 else "none"))
