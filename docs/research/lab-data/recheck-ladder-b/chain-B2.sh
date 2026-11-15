#!/usr/bin/env bash
cd /home/nishikawa/projects/peak-sim
W=$HOME/.cache/peak-lab/out/cmd277t6
export PEAK_LAB_OUT_DIR=$HOME/.cache/peak-lab/out
CAP=/home/nishikawa/projects/peak-sim-trials/_out/cmd277_recapture2/bookstack/capture.json
PRED=poc/lab/predictions/ladder-prediction-2.json
note() { bash /home/nishikawa/projects/multi-agent-shogun/scripts/inbox_write.sh ashigaru3 "$1" info bgjob; }
echo "$(date '+%F %T') CHAIN-B2 START (after the 0713 stop and the restart)" >> $W/chain.log
for sc in r-2 r-3 r-4 r-5; do
  case $sc in r-1|r-2) scale=50000;; *) scale=200000;; esac
  sw=""; [ "$sc" = r-3 ] && sw="--switch-scale"
  bash $W/stats.sh $scale $W/stats-scenarios-restart.tsv "before-$sc-restart"
  echo "$(date '+%F %T') START $sc scale=$scale" >> $W/chain.log
  poc/lab/bin/measure-ladder --scenario=$sc --predictions=$PRED --statement-record=$CAP --scheme=B --request-timeout=600s $sw > $W/$sc.output.txt 2>&1
  rc=$?
  echo "$(date '+%F %T') END $sc exit=$rc" >> $W/chain.log
  bash $W/stats.sh $scale $W/stats-scenarios-restart.tsv "after-$sc-restart"
  note "B2 $sc 終了 exit=$rc (ログ $W/$sc.output.txt)"
  if [ $rc -ne 0 ]; then echo "$(date '+%F %T') CHAIN-B2 STOP at $sc exit=$rc" >> $W/chain.log; touch $W/chain.done; exit $rc; fi
done
echo "$(date '+%F %T') CHAIN-B2 DONE" >> $W/chain.log
touch $W/chain.done
note "B2 全シナリオ(r-2〜r-5)終了。設定は未だ戻していない(印の測り・戻しはこれから)"
