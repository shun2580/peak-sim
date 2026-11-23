#!/usr/bin/env bash
# Step 3 (optional, to be agreed): the cleanup route alone at the sizes of the earlier capture (100 / 1,000 / 10,000 / 30,000 rows),
# ANALYZE right after the build as in that capture, one request each, with the tap. One database (own project) per size.
. "$(dirname "$0")/env.sh"
SIZES=${SIZES:-"100 1000 10000 30000"}
rc=0
for s in $SIZES; do
  SCALE=$s; before "size$s" || exit 2
  echo "start=$(stamp) scale=$s step=size" >> "$OUT/run_times.txt"
  vrun "size$s" "size$s" --routes="$ROUTE_CLEANUP" --laps=1 --tap=on --analyze=before
  c=$?; stop_stack "size$s"; echo "end=$(stamp) exit=$c step=size$s" >> "$OUT/run_times.txt"
  after "size$s" || { echo "A VOLUME IS GONE (size$s)" >&2; c=99; }
  [ "$c" -ne 0 ] && { rc=$c; break; }
done
exit $rc
