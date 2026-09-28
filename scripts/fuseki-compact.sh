#!/usr/bin/env bash
# moai-fuseki TDB2 데이터셋 매일 압축 — cron 15 5 * * *
#
#   crontab: 15 5 * * * /root/scripts/fuseki-compact.sh
#
# 왜 필요한가(2026-09-25 사고):
#   3사 MES 온톨로지(tx-mes)는 매시간 그래프를 통째로 교체(PUT)한다(hisense :25 · naturefood :40 ·
#   infurs :55). TDB2 는 교체된 옛 데이터를 파일에서 지우지 않고 쌓아 두므로, 실데이터 28만
#   트리플이 약 5일 만에 **28GB** 로 불어 루트 디스크(99GB)를 100% 채웠다. MariaDB 가 멈추고
#   hisense 등 전 사이트에서 database error 가 났다. 압축하면 109MB 가 된다.
#   caren(:10)·kcro 도 주기 재적재라 같은 방식으로 자란다 — 함께 압축한다.
#
# 원칙:
#   · 압축은 살아 있는 데이터만 새 Data-000N 에 옮기고 옛 것을 지운다(deleteOld=true).
#     새 파일을 쓸 공간이 필요하므로 여유가 1GB 미만이면 하지 않고 로그만 남긴다
#     (공간 부족으로 압축하다 디스크를 0 으로 만들면 오늘 사고를 스스로 재현한다).
#   · 데이터셋 하나씩 순서대로, 끝날 때까지 기다린다(동시 압축은 메모리 1GB 컨테이너에 부담).
#   · 결과는 한 줄씩 /var/log/fuseki-compact.log 에 남긴다.
set -uo pipefail

FUSEKI="${FUSEKI_URL:-http://localhost:3030}"
DATA_DIR="${FUSEKI_DATA_DIR:-/home/jesetech/moai/fuseki-data/databases}"
LOG="${COMPACT_LOG:-/var/log/fuseki-compact.log}"
DATASETS="${COMPACT_DATASETS:-tx-mes caren kcro moai}"
MIN_FREE_KB=$((1024 * 1024))   # 1GB

log() { echo "[$(date '+%F %T')] $*" >>"$LOG"; }

if ! curl -fsS -m 5 "$FUSEKI/\$/ping" >/dev/null 2>&1; then
  log "SKIP Fuseki 응답 없음"; exit 1
fi

for ds in $DATASETS; do
  [ -d "$DATA_DIR/$ds" ] || continue
  free_kb=$(df -Pk "$DATA_DIR" | awk 'NR==2{print $4}')
  if [ "$free_kb" -lt "$MIN_FREE_KB" ]; then
    log "SKIP $ds 여유 공간 부족 ($((free_kb / 1024))MB < 1GB) — 사람이 먼저 공간을 비울 것"
    continue
  fi
  before=$(du -sk "$DATA_DIR/$ds" | cut -f1)
  t0=$SECONDS
  task=$(curl -fsS -m 30 -X POST "$FUSEKI/\$/compact/$ds?deleteOld=true" 2>/dev/null \
         | grep -o '"taskId" *: *"[0-9]*"' | grep -o '[0-9]*')
  if [ -z "$task" ]; then log "FAIL $ds 압축 요청 실패"; continue; fi

  ok=""
  for _ in $(seq 1 120); do            # 최대 10분
    st=$(curl -fsS -m 5 "$FUSEKI/\$/tasks/$task" 2>/dev/null)
    if echo "$st" | grep -q '"finished"'; then
      echo "$st" | grep -q '"success" *: *true' && ok=yes || ok=no
      break
    fi
    sleep 5
  done
  after=$(du -sk "$DATA_DIR/$ds" | cut -f1)
  case "$ok" in
    yes) log "OK $ds $((before / 1024))MB -> $((after / 1024))MB · $((SECONDS - t0))초" ;;
    no)  log "FAIL $ds 압축 실패(task $task) · $((before / 1024))MB" ;;
    *)   log "FAIL $ds 10분 안에 안 끝남(task $task)" ;;
  esac
done
