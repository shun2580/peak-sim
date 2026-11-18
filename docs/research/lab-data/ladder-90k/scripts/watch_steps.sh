#!/usr/bin/env bash
# reads the table stats after each finished step (ended_at.txt) and the max statement time; notes when output stands still for 30 min
W=$HOME/.cache/peak-lab/out/cmd284t5b
source "$HOME/projects/peak-sim/poc/lab/bin/_lab.sh"
seen=$W/watch.seen; touch $seen; last_alert=0
while [ ! -e $W/chain.done ]; do
  for f in $HOME/.cache/peak-lab/out/ladder/s-*-90000/rung-*/ended_at.txt; do
    [ -e "$f" ] || continue
    grep -qxF "$f" $seen && continue
    echo "$f" >> $seen
    d=$(dirname "$f"); sc=$(basename $(dirname $d))
    bash $W/scripts/stats.sh 90000 $W/stats-steps.tsv "after-$sc/$(basename $d)"
  done
  now=$(date +%s)
  newest=$(find $HOME/.cache/peak-lab/out/ladder/s-*-90000 -type f -printf '%T@\n' 2>/dev/null | sort -n | tail -1 | cut -d. -f1)
  mx=$(docker exec -e MYSQL_PWD="$PEAK_MEASURE_DB_ROOT_PASSWORD" peak-lab-db mysql -uroot -N -B -e "SELECT IFNULL(MAX(TIME),0) FROM information_schema.PROCESSLIST WHERE COMMAND NOT IN ('Sleep','Daemon') AND ID<>CONNECTION_ID()" 2>/dev/null)
  echo "$(date -Is) newest_file_age=$(( now - ${newest:-$now} ))s max_stmt_time=${mx:-?}s" >> $W/watch.log
  if [ -n "$newest" ] && [ $(( now - newest )) -gt 1800 ] && [ $(( now - last_alert )) -gt 3600 ]; then
    last_alert=$now
    : # (a line that sent a note to another process of the working environment was here; replaced by this comment)
  fi
  sleep 300
done
