#!/usr/bin/env bash
# read-only: innodb_table_stats of the scale's DB, with a time and a label -> appended to $2
# guard (2026-11-17 18:3x, maintainer's request): for label before-s-5 only, wait until $W/RESUME-s-5 exists.
set -uo pipefail
W="$HOME/.cache/peak-lab/out/cmd284t5b"
if [ "${3:-}" = "before-s-5" ]; then
  gdir="${STATS_GUARD_DIR:-$W}"
  glog="${STATS_GUARD_LOG:-$W/chain.log}"
  if [ ! -e "$gdir/RESUME-s-5" ]; then
    echo "$(date -Is) PAUSED before s-5 (maintainer's request): waiting for $gdir/RESUME-s-5" >> "$glog"
    while [ ! -e "$gdir/RESUME-s-5" ]; do sleep 30; done
    echo "$(date -Is) RESUME-s-5 found, continuing to s-5" >> "$glog"
  fi
fi
source /home/nishikawa/projects/peak-sim/poc/lab/bin/_lab.sh
scale="$1"; file="$2"; label="$3"
name="peak_sim_measure_${PEAK_MEASURE_RUN_ID}_lab_${scale}"
now="$(date '+%F %T')"
docker exec -e MYSQL_PWD="$PEAK_MEASURE_DB_ROOT_PASSWORD" peak-lab-db mysql -uroot -N -B -e \
 "SELECT table_name,last_update,n_rows FROM mysql.innodb_table_stats WHERE database_name='${name}' ORDER BY table_name" 2>/dev/null \
 | sed "s|^|${now}\t${label}\t${scale}\t|" >> "$file"
