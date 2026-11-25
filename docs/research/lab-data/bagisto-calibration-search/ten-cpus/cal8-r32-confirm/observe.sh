#!/usr/bin/env bash
# observe.sh <label> <outdir>: one read-only look at the stall. Reads /proc and docker stats; puts nothing into a container, changes nothing, kills nothing.
# It writes <outdir>/<label>.txt: the time (date), the app container's listen queue and connections on port 9000, each php-fpm worker's state and wchan,
# the web container's connections to port 9000, and the CPU of the app and the database.
set -uo pipefail
label="$1"; out="$2"; f="$out/$label.txt"
APP=peak-lab-bagisto-app; WEB=peak-lab-bagisto-web; DB=peak-lab-bagisto-db
{
echo "label=$label"
echo "time=$(date '+%F %T')"
echo "== app container: sockets on port 9000 (raw /proc/net/tcp and tcp6 lines, then a summary)"
docker exec $APP sh -c 'cat /proc/net/tcp /proc/net/tcp6' 2>&1 | awk 'NR==1 || $0 ~ /:2328 / ' > "$out/.app-net.$$"
cat "$out/.app-net.$$"
python3 -I - "$out/.app-net.$$" <<'PY'
import sys, collections
lq=0; listen=0; est=collections.Counter(); states=collections.Counter(); sendq=0
for line in open(sys.argv[1]).read().splitlines()[1:]:
    p=line.split()
    if len(p)<5: continue
    loc, rem, st, q = p[1], p[2], p[3], p[4]
    lport=loc.rsplit(':',1)[1]
    if lport!='2328': continue
    tx,rx=q.split(':'); rx=int(rx,16)
    states[st]+=1
    if st=='0A': listen+=1; lq=max(lq,rx)
    elif st=='01': est[rem.rsplit(':',1)[0]]+=1
    elif st=='06': pass
print("summary: listen_sockets=%d listen_rx_queue(Recv-Q)=%d established_total=%d states=%s" % (listen,lq,sum(est.values()),dict(states)))
print("established_by_peer(hex ip)=%s" % dict(est))
PY
rm -f "$out/.app-net.$$"
echo "== app container: php-fpm workers (pid state wchan)"
docker exec $APP sh -c 'for d in /proc/[0-9]*; do p=${d#/proc/}; c=$(tr "\0" " " <$d/cmdline 2>/dev/null | cut -c1-40); case "$c" in php-fpm*) s=$(sed "s/.*) //;s/ .*//" $d/stat 2>/dev/null); w=$(cat $d/wchan 2>/dev/null); echo "$p state=$s wchan=$w cmd=$c";; esac; done' 2>&1 | sort -n
echo "== app container: each php-fpm worker's socket fds (pid fd->socket inode); the listen socket is the one in the LISTEN line above"
docker exec $APP sh -c 'for d in /proc/[0-9]*; do c=$(tr "\0" " " <$d/cmdline 2>/dev/null | cut -c1-9); [ "$c" = "php-fpm: " ] && echo "${d#/proc/} $(ls -l $d/fd 2>/dev/null | grep -o "[0-9]* -> socket:\[[0-9]*\]" | tr "\n" ";")"; done' 2>&1 | sort -n
echo "== app container: all sockets on port 9000 with inode (raw, full)"
docker exec $APP sh -c 'cat /proc/net/tcp /proc/net/tcp6' 2>&1 | awk '$2 ~ /:2328$/ {print $2,$3,$4,$5,$10}' | head -400
echo "== app container: wchan counts"
docker exec $APP sh -c 'for d in /proc/[0-9]*; do c=$(tr "\0" " " <$d/cmdline 2>/dev/null | cut -c1-9); [ "$c" = "php-fpm: " ] && echo "$(cat $d/wchan 2>/dev/null)"; done' 2>&1 | sort | uniq -c
echo "== web container: sockets with remote port 9000 (summary; raw lines follow)"
docker exec $WEB sh -c 'cat /proc/net/tcp /proc/net/tcp6' 2>&1 | awk 'NR==1 || $3 ~ /:2328$/' > "$out/.web-net.$$"
python3 -I - "$out/.web-net.$$" <<'PY'
import sys, collections
states=collections.Counter(); peers=collections.Counter(); tx=0
for line in open(sys.argv[1]).read().splitlines()[1:]:
    p=line.split()
    if len(p)<5: continue
    if not p[2].endswith(':2328'): continue
    states[p[3]]+=1; peers[p[2].rsplit(':',1)[0]]+=1
    tx+=int(p[4].split(':')[0],16)
print("summary: to_port_9000 by state (01=ESTABLISHED 02=SYN_SENT 06=TIME_WAIT 08=CLOSE_WAIT)=%s  peers=%s  send_queue_bytes_total=%d" % (dict(states), dict(peers), tx))
PY
cat "$out/.web-net.$$"; rm -f "$out/.web-net.$$"
echo "== web container: nginx stub_status (port 8081, read only)"
docker exec $WEB sh -c 'wget -qO- http://127.0.0.1:8081/__lab/status 2>&1' 2>&1
echo "== docker stats (app web db k6), one reading"
docker stats --no-stream --format '{{.Name}} cpu={{.CPUPerc}} mem={{.MemUsage}}' $APP $WEB $DB peak-lab-bagisto-k6 2>&1
echo "== db: connections"
docker exec -e MYSQL_PWD="$(grep ^PEAK_MEASURE_DB_ROOT_PASSWORD= $HOME/.cache/peak-lab-bagisto/state.env | cut -d= -f2)" $DB mysql -uroot -N -B -e "select count(*) as threads_connected from information_schema.processlist; select command,count(*) from information_schema.processlist group by command" 2>&1 | grep -v "Using a password"
} > "$f" 2>&1
echo "$(date '+%F %T') wrote $f" >> "$out/observe-log.txt"
