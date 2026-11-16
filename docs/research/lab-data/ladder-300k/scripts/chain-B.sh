#!/usr/bin/env bash
# B (limits off: max_execution_time 0, memory_limit 4G), scenarios s-1 .. s-4; stops there so that s-5 waits for the lead's reply.
cd "$HOME/projects/peak-sim" || exit 1
W=$HOME/.cache/peak-lab/out/cmd281t6b
export PEAK_LAB_OUT_DIR=$HOME/.cache/peak-lab/out
CAP=$HOME/projects/peak-sim-trials/_out/cmd277_recapture2/bookstack/capture.json
PRED=poc/lab/predictions/ladder-prediction-3.json
# (line left out of this copy: it sent a note to another process)
# (line left out of this copy: it sent a note to another process)
snap() { # $1 = label: app / web logs, read-only copies
  mkdir -p "$W/logs"
  docker logs peak-lab-app > "$W/logs/app-$1.txt" 2>&1; date -u -Is > "$W/logs/app-$1.at"
  docker logs peak-lab-web > "$W/logs/web-$1.txt" 2>&1
}
echo "$(date -Is) CHAIN-B START" >> $W/chain.log
for sc in ${SCENARIOS:-s-1 s-2 s-3 s-4}; do
  sw=""; [ "$sc" = s-1 ] && [ -z "$NO_SWITCH" ] && sw="--switch-scale"
  bash $W/stats.sh 300000 $W/stats-scenarios.tsv "before-$sc"
poc/lab/bin/read-memory $W/mem-sequence.txt --label="before-$sc"
  echo "$(date -Is) START $sc scale=300000" >> $W/chain.log
  poc/lab/bin/measure-ladder --scenario=$sc --predictions=$PRED --statement-record=$CAP --scheme=B --request-timeout=600s $sw > $W/$sc.output.txt 2>&1
  rc=$?
  echo "$(date -Is) END $sc exit=$rc" >> $W/chain.log
  poc/lab/bin/read-memory $W/mem-sequence.txt --label="after-$sc"
  bash $W/stats.sh 300000 $W/stats-scenarios.tsv "after-$sc"
  snap "after-$sc"
  if [ $rc -ne 0 ]; then
    echo "$(date -Is) CHAIN-B STOP at $sc exit=$rc" >> $W/chain.log
    if [ $rc -eq 19 ]; then
      mkdir -p $W/oom-stop-$sc
      docker inspect peak-lab-app --format '{{json .State}} mem={{.HostConfig.Memory}}' > $W/oom-stop-$sc/inspect-app.txt 2>&1
      docker inspect peak-lab-db --format '{{json .State}}' > $W/oom-stop-$sc/inspect-db.txt 2>&1
      docker logs --tail 200 peak-lab-app > $W/oom-stop-$sc/app-logs-tail.txt 2>&1
      cp $W/mem-sequence.txt $W/oom-stop-$sc/
# (line left out of this copy: it sent a note to another process)
    fi
    touch $W/chain.done
    note "B $sc 終了 exit=$rc (ログ $W/$sc.output.txt)"
    exit $rc
  fi
  note "B $sc 終了 exit=$rc (ログ $W/$sc.output.txt)"
done
echo "$(date -Is) CHAIN-B DONE (${SCENARIOS:-s-1 s-2 s-3 s-4})" >> $W/chain.log
touch $W/chain.done
# (line left out of this copy: it sent a note to another process)
