# summarize_obs.py <obs dir>: one table row per observation file (read only). Run with python3 -I.
import sys, os, re, glob, collections
d = sys.argv[1]
order = ['w-1min','end+5s','end+1m','end+2m','end+5m','end+10m','end+15m','end+20m','end+25m','tail-end']
print("| look | time | Recv-Q (:9000) | ESTAB in app | ESTAB web->:9000 | workers by wchan | worker sockets with data waiting | unaccepted ESTAB | stub_status Active | k6 running line | app% | db% |")
print("| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |")
for lab in order:
    f = os.path.join(d, lab + '.txt')
    if not os.path.exists(f): continue
    t = open(f).read()
    g = lambda pat, default='-': (re.search(pat, t, re.M) or [None, default])[1]
    time_ = g(r'^time=(.*)$')
    rq = g(r'Recv-Q\)=(\d+)'); est = g(r'established_total=(\d+)')
    web = re.search(r"to_port_9000 by state.*?=(\{.*?\})\s+peers", t)
    web_est = '-'
    if web: web_est = eval(web.group(1)).get('01', 0)
    wc = re.search(r'== app container: wchan counts\n(.*?)\n==', t, re.S)
    wch = '; '.join(f"{l.split()[0]}×{l.split()[1]}" for l in wc.group(1).splitlines() if l.strip() and 'do_epoll_wait' not in l) if wc else '-'
    # sockets: inode lines (full raw listing) -> ESTAB with inode 0 = taken by the kernel, not accepted; ESTAB with an inode = held by a worker
    raw = re.search(r'all sockets on port 9000 with inode.*?\n(.*?)\n== ', t, re.S)
    unacc = '-'; held = '-'
    if raw:
        un = 0; hd = 0; hd_data = 0
        for l in raw.group(1).splitlines():
            p = l.split()
            if len(p) < 5 or p[2] != '01': continue
            rx = int(p[3].split(':')[1], 16)
            if p[4] == '0': un += 1
            else:
                hd += 1
                if rx > 0: hd_data += 1
        unacc = un; held = f"{hd} held by workers ({hd_data} with unread bytes)"
    act = g(r'Active connections: (\d+)')
    k6 = g(r'running \((.*?)\), (\d+)/(\d+) VUs, (\d+) complete', '')
    m = re.search(r'running \((.*?)\), (\d+)/(\d+) VUs, (\d+) complete', t)
    k6s = f"{m.group(1)} VUs {m.group(2)}/{m.group(3)} complete {m.group(4)}" if m else '-'
    app = g(r'peak-lab-bagisto-app cpu=([\d.]+)%'); db = g(r'peak-lab-bagisto-db cpu=([\d.]+)%')
    print(f"| {lab} | {time_} | {rq} | {est} | {web_est} | {wch} | {held} | {unacc} | {act} | {k6s} | {app} | {db} |")
