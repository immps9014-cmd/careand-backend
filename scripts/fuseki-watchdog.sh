#!/usr/bin/env bash
# moai-fuseki 워치독 — cron */5
#
#   crontab: */5 * * * * /root/scripts/fuseki-watchdog.sh
#
# 왜 필요한가(2026-09-20 사고):
#   Fuseki JVM 이 컨테이너 메모리 한도에 붙어 **응답만 멈춘** 적이 있다. 이때
#   `docker ps` 는 "Up", OOMKilled=false, 포트 3030 도 LISTEN 상태였다. 즉 도커도
#   커널도 이상을 모른다 — 사람이 질의해 보기 전까지 아무도 모른다. 그 사각지대를 메운다.
#   moai·caren·kcro·tx-mes 네 시스템이 이 컨테이너 하나를 같이 쓴다.
#
# 원칙:
#   · 살아 있는지 판단은 **실제 질의**로 한다. 포트가 열려 있는 것은 근거가 못 된다.
#   · 한 번 실패로 재시작하지 않는다(순간 지연과 정지를 구분한다).
#   · 재시작은 15분에 한 번까지. 계속 죽으면 재시작 반복이 아니라 로그로 남긴다 —
#     크래시 루프는 고장을 감추기만 한다.
set -uo pipefail

FUSEKI="${FUSEKI_URL:-http://localhost:3030}"
CONTAINER="${FUSEKI_CONTAINER:-moai-fuseki}"
PROBE_DS="${FUSEKI_PROBE_DS:-tx-mes}"
LOG="${FUSEKI_WATCHDOG_LOG:-/var/log/fuseki-watchdog.log}"
STATE="${FUSEKI_WATCHDOG_STATE:-/var/lib/fuseki-watchdog.state}"
COOLDOWN=900            # 재시작 최소 간격(초)
TRIES=3                 # 연속 실패 판정 횟수
GAP=10                  # 시도 간격(초)

say() { echo "[$(date '+%F %T')] $*" >>"$LOG"; }

# PMS 관리자 알림(2026-09-25) — 이 서버는 외부 메일·Slack·Telegram 이 막혀 있어 워치독 기록이 로그에만 남았다.
# 재시작·복구 실패는 드문 사건이라 하루가 아니라 **시간 단위**로 묶는다(dedup 에 시각). 알림 실패는 워치독 동작과 무관.
pms() {   # pms <kind(≤30자)> <제목> <내용>
  local js=/var/www/telomx-backend/src/jobs/ops_alert.js
  [[ -f $js ]] || return 0
  local out
  out=$(timeout 20 /usr/bin/node "$js" --kind "$1" --title "$2" --body "$3" --dedup "$1:$(date +%H)" 2>&1) || out="PMS 알림 실패: $out"
  say "      → $out"
}

# 살아 있는가 — ping(프로세스) + 질의 1건(데이터 경로). 둘 다 통과해야 정상이다.
alive() {
  curl -fs -m 5 -o /dev/null "$FUSEKI/\$/ping" 2>/dev/null || return 1
  curl -fs -m 10 -o /dev/null -X POST "$FUSEKI/$PROBE_DS/sparql" \
       -H 'Content-Type: application/sparql-query' \
       --data-binary 'ASK { GRAPH ?g { ?s ?p ?o } }' 2>/dev/null || return 1
  return 0
}

for ((i = 1; i <= TRIES; i++)); do
  if alive; then exit 0; fi
  [[ $i -lt $TRIES ]] && sleep "$GAP"
done

# ── 여기부터는 3회 연속 실패 ────────────────────────────────────────────────
# 진단값은 로그 한 줄에 들어가야 한다 — 실패하면 빈 줄이 섞이므로 개행을 없앤다.
mem=$(timeout 10 docker stats --no-stream --format '{{.MemUsage}}' "$CONTAINER" 2>/dev/null | tr -d '\n')
state=$(timeout 10 docker inspect -f '{{.State.Status}} OOMKilled={{.State.OOMKilled}} restarts={{.RestartCount}}' "$CONTAINER" 2>/dev/null | tr -d '\n')
mem=${mem:-조회불가}; state=${state:-조회불가}
say "DOWN  ${TRIES}회 연속 무응답 · 컨테이너=$state · 메모리=$mem"

last=$(cat "$STATE" 2>/dev/null || echo 0)
now=$(date +%s)
if (( now - last < COOLDOWN )); then
  say "      재시작 억제 — 최근 $(( (now - last) / 60 ))분 전에 이미 재시작했다. 손으로 볼 것."
  pms fuseki_down_repeat "[Fuseki] 반복 장애 — 사람 확인 필요" \
    "moai-fuseki 가 재시작 $(( (now - last) / 60 ))분 만에 다시 무응답입니다(재시작 반복은 억제). 3사 MES·caren·kcro 온톨로지·MCP 가 멈춥니다.
컨테이너=$state · 메모리=$mem · 로그 $LOG"
  exit 1
fi

mkdir -p "$(dirname "$STATE")"
echo "$now" > "$STATE"
say "      docker restart $CONTAINER 시도"
if ! timeout 120 docker restart "$CONTAINER" >/dev/null 2>&1; then
  say "FAIL  재시작 명령 실패 — 도커 데몬까지 확인할 것"
  pms fuseki_down "[Fuseki] 다운 — 재시작 명령 실패" \
    "moai-fuseki 무응답으로 docker restart 를 시도했으나 명령이 실패했습니다. 도커 데몬까지 확인하세요.
컨테이너=$state · 메모리=$mem · 로그 $LOG"
  exit 1
fi

# 기동에 20초쯤 걸린다. 복구를 확인한 뒤에 끝낸다 — "재시작했다" 는 복구했다는 뜻이 아니다.
for _ in $(seq 1 20); do
  if alive; then
    mem2=$(timeout 10 docker stats --no-stream --format '{{.MemUsage}}' "$CONTAINER" 2>/dev/null | tr -d '\n')
    say "OK    복구 확인 · 메모리=$mem2"
    pms fuseki_restarted "[Fuseki] 자동 재시작 — 복구됨" \
      "moai-fuseki 가 ${TRIES}회 연속 무응답이라 자동 재시작했고 응답이 돌아왔습니다. 자주 반복되면 메모리 한도를 확인하세요.
재시작 전 컨테이너=$state · 메모리=$mem → 복구 후 메모리=$mem2 · 로그 $LOG"
    exit 0
  fi
  sleep 3
done
say "FAIL  재시작했으나 60초 안에 응답하지 않는다 — 손으로 볼 것"
pms fuseki_down "[Fuseki] 다운 — 재시작 후에도 무응답" \
  "moai-fuseki 를 재시작했으나 60초 안에 응답하지 않습니다. 3사 MES·caren·kcro 온톨로지·MCP 가 멈춰 있습니다.
재시작 전 컨테이너=$state · 메모리=$mem · 로그 $LOG"
exit 1
