#!/usr/bin/env bash
# B (limits off: max_execution_time 0, memory_limit 4G, request timeout 600s) at 90,000 rows, scenarios s-1 .. s-5 in one chain.
# The app and web containers are recreated (bin/use-db 90000) before s-2 .. s-5, after the app and web logs were copied.
cd "$HOME/projects/peak-sim" || exit 1
W=$HOME/.cache/peak-lab/out/cmd284t5b
export PEAK_LAB_OUT_DIR=$HOME/.cache/peak-lab/out PEAK_LAB_SCALE=90000
CAP=$HOME/projects/peak-sim-trials/_out/cmd277_recapture2/bookstack/capture.json
PRED=poc/lab/predictions/ladder-prediction-4-b90k.json
# (a line that sent a note to another process of the working environment was here; replaced by this comment)
snap() { # $1 = label: app / web logs, read-only copies
  docker logs peak-lab-app > "$W/logs/app-$1.txt" 2>&1; date -u -Is > "$W/logs/app-$1.at"
  docker logs peak-lab-web > "$W/logs/web-$1.txt" 2>&1
}
echo "$(date -Is) CHAIN-B START" >> $W/chain.log
prev=""
for sc in ${SCENARIOS:-s-1 s-2 s-3 s-4 s-5}; do
  if [ -n "$prev" ]; then
    echo "$(date -Is) RECREATE app/web before $sc (use-db 90000)" >> $W/chain.log
    poc/lab/bin/read-memory $W/mem-sequence.txt --label="before-recreate-$sc"
    git diff -- poc/lab/compose | sha256sum > $W/recreate/compose-diff-before-recreate-$sc.sha
    poc/lab/bin/check-oom save $W/recreate/oom-before-recreate-$sc.txt > /dev/null 2>&1
    poc/lab/bin/use-db 90000 > $W/recreate/use-db-recreate-$sc.txt 2>&1 || { echo "$(date -Is) CHAIN-B STOP use-db failed before $sc" >> $W/chain.log; touch $W/chain.done; note "use-db failed before $sc"; exit 11; }
    poc/lab/bin/check-limits --require=unlimited --request-timeout=600s --dir=$W/recreate/limits-after-recreate-$sc > $W/recreate/check-limits-after-recreate-$sc.txt 2>&1 || { echo "$(date -Is) CHAIN-B STOP check-limits failed before $sc" >> $W/chain.log; touch $W/chain.done; note "check-limits failed before $sc"; exit 13; }
    docker inspect peak-lab-app --format 'HostConfig.Memory={{.HostConfig.Memory}} started={{.State.StartedAt}}' >> $W/recreate/check-limits-after-recreate-$sc.txt 2>&1
    echo "$(date -Is) recreated and checked before $sc" >> $W/chain.log
  fi
  bash $W/scripts/stats.sh 90000 $W/stats-scenarios.tsv "before-$sc"
  poc/lab/bin/read-memory $W/mem-sequence.txt --label="before-$sc"
  echo "$(date -Is) START $sc scale=90000" >> $W/chain.log
  poc/lab/bin/measure-ladder --scenario=$sc --predictions=$PRED --statement-record=$CAP --scheme=B --request-timeout=600s > $W/$sc.output.txt 2>&1
  rc=$?
  echo "$(date -Is) END $sc exit=$rc" >> $W/chain.log
  poc/lab/bin/read-memory $W/mem-sequence.txt --label="after-$sc"
  bash $W/scripts/stats.sh 90000 $W/stats-scenarios.tsv "after-$sc"
  snap "after-$sc"
  if [ $rc -ne 0 ]; then
    echo "$(date -Is) CHAIN-B STOP at $sc exit=$rc" >> $W/chain.log
    if [ $rc -eq 19 ]; then
      mkdir -p $W/oom-stop-$sc
      docker inspect peak-lab-app --format '{{json .State}} mem={{.HostConfig.Memory}}' > $W/oom-stop-$sc/inspect-app.txt 2>&1
      docker inspect peak-lab-db --format '{{json .State}}' > $W/oom-stop-$sc/inspect-db.txt 2>&1
      docker logs --tail 200 peak-lab-app > $W/oom-stop-$sc/app-logs-tail.txt 2>&1
      cp $W/mem-sequence.txt $W/oom-stop-$sc/
    fi
    touch $W/chain.done
    note "B $sc 終了 exit=$rc (ログ $W/$sc.output.txt)"
    exit $rc
  fi
  note "B $sc 終了 exit=$rc (ログ $W/$sc.output.txt)"
  prev=$sc
done
echo "$(date -Is) CHAIN-B DONE (${SCENARIOS:-s-1 s-2 s-3 s-4 s-5})" >> $W/chain.log
touch $W/chain.done
note "B 全シナリオ終了 (chain.done)"
