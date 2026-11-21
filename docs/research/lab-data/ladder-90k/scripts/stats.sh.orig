#!/usr/bin/env bash
# read-only: innodb_table_stats of the scale's DB, with a time and a label -> appended to $2
set -uo pipefail
source <home>/projects/peak-sim/poc/lab/bin/_lab.sh
scale="$1"; file="$2"; label="$3"
name="peak_sim_measure_${PEAK_MEASURE_RUN_ID}_lab_${scale}"
now="$(date '+%F %T')"
docker exec -e MYSQL_PWD="$PEAK_MEASURE_DB_ROOT_PASSWORD" peak-lab-db mysql -uroot -N -B -e \
 "SELECT table_name,last_update,n_rows FROM mysql.innodb_table_stats WHERE database_name='${name}' ORDER BY table_name" 2>/dev/null \
 | sed "s|^|${now}\t${label}\t${scale}\t|" >> "$file"
