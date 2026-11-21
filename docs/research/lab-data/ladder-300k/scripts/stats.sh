#!/usr/bin/env bash
# read-only: innodb_table_stats of the scale's DB, with a time and a label -> appended to $2
# T6B2: hold before s-4 until $W/release-before-s-4 exists (so the app/web can be recreated between s-3 and s-4)
W=<home>/.cache/peak-lab/out/run-281t6b
if [ "${3:-}" = "before-s-4" ] && [ ! -e "$W/release-before-s-4" ]; then
  echo "$(date -Is) HOLD before s-4 (waiting for release-before-s-4)" >> "$W/chain.log"
# (line left out of this copy: it sent a note to another process)
  while [ ! -e "$W/release-before-s-4" ]; do sleep 30; done
  echo "$(date -Is) RELEASE before s-4" >> "$W/chain.log"
fi
set -uo pipefail
source <home>/projects/peak-sim/poc/lab/bin/_lab.sh
scale="$1"; file="$2"; label="$3"
name="peak_sim_measure_${PEAK_MEASURE_RUN_ID}_lab_${scale}"
now="$(date '+%F %T')"
docker exec -e MYSQL_PWD="$PEAK_MEASURE_DB_ROOT_PASSWORD" peak-lab-db mysql -uroot -N -B -e \
 "SELECT table_name,last_update,n_rows FROM mysql.innodb_table_stats WHERE database_name='${name}' ORDER BY table_name" 2>/dev/null \
 | sed "s|^|${now}\t${label}\t${scale}\t|" >> "$file"
