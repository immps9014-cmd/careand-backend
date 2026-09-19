#!/bin/bash
#
# claude 유휴 백그라운드 워커 자동 정리
#
# 배경: FleetView(`claude agents` / `claude --bg`)로 띄운 백그라운드 세션은 응답을 마쳐도
#       스스로 종료되지 않고 상주한다. RAM 3.6GB 서버에서 세션 하나당 300~400MB를 먹으므로
#       방치하면 수십일간 1GB 가까이 잠식된다(2026-08-28 실측: 20일 방치 세션 포함 940MB).
#
# 주의: 워커를 개별 kill 하면 데몬이 roster.json을 보고 즉시 재기동한다.
#       지원되는 정리 경로는 `claude daemon stop --any` 뿐이며, 이는 워커 전체를 함께 내린다.
#       따라서 "실행 중인 워커가 전부 유휴일 때만" 데몬째 정리한다. 하나라도 살아 있으면 건너뛴다.
#
# 안전장치:
#   - 데몬이 없으면 아무것도 하지 않는다.
#   - 워커의 세션 파일 경로를 못 찾으면 '활동 중'으로 간주해 보존한다.
#   - 프로세스 자체가 IDLE_HOURS보다 젊으면 보존한다(옛 대화를 갓 resume한 워커 보호).
#   - 포그라운드 TTY 세션은 데몬 소켓을 쓰지 않으므로 영향받지 않는다.
#
# 대화 기록은 ~/.claude/projects/ 에 남아 `claude --resume`으로 복구 가능하다.
#
# 사용: claude-idle-worker-reaper.sh [--dry-run]
# 환경변수: IDLE_HOURS (기본 3)

set -uo pipefail
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

IDLE_HOURS="${IDLE_HOURS:-3}"
IDLE_SECS=$(( IDLE_HOURS * 3600 ))
CLAUDE_BIN=/root/.local/bin/claude
LOG=/var/log/claude-worker-reaper.log
LOCK=/var/run/claude-worker-reaper.lock
LOG_KEEP_LINES=500

DRY_RUN=0
[ "${1:-}" = "--dry-run" ] && DRY_RUN=1

log() { printf '%s %s\n' "$(date '+%F %T')" "$*" >> "$LOG"; }

# 중복 실행 방지
exec 9>"$LOCK" || exit 0
flock -n 9 || exit 0

# 로그 자가 정리 (이 스크립트가 또 다른 디스크/메모리 문제가 되지 않도록)
if [ -f "$LOG" ] && [ "$(wc -l < "$LOG")" -gt $(( LOG_KEEP_LINES * 2 )) ]; then
    tail -n "$LOG_KEEP_LINES" "$LOG" > "$LOG.tmp" && mv -f "$LOG.tmp" "$LOG"
fi

# argv 배열을 정확히 대조한다.
# `pgrep -f`만 쓰면 해당 문자열을 포함한 임의의 셸 명령까지 잡히므로(진단 명령 등),
# 반드시 argv[1] 위치까지 확인해 진짜 프로세스만 고른다.
# comm(프로세스명)은 버전 디렉토리명("2.1.250")으로 잡혀 신뢰할 수 없다.
argv_of() { tr '\0' '\n' < "/proc/$1/cmdline" 2>/dev/null; }

is_daemon() {
    local -a a; mapfile -t a < <(argv_of "$1")
    [ "${a[1]:-}" = "daemon" ] && [ "${a[2]:-}" = "run" ]
}
is_worker() {
    local -a a; mapfile -t a < <(argv_of "$1")
    [ "${a[1]:-}" = "bg-pty-host" ]
}

# 1) 데몬이 없으면 정리할 것도 없다
daemon_pid=""
for p in $(pgrep -f 'daemon run' 2>/dev/null); do
    [ "$p" = "$$" ] && continue
    if is_daemon "$p"; then daemon_pid=$p; break; fi
done
[ -z "$daemon_pid" ] && exit 0

# 2) 실행 중인 백그라운드 워커 수집
workers=()
for p in $(pgrep -f 'bg-pty-host' 2>/dev/null); do
    [ "$p" = "$$" ] && continue
    is_worker "$p" && workers+=("$p")
done
if [ "${#workers[@]}" -eq 0 ]; then
    # 워커가 없으면 데몬은 스스로 idle-exit 한다. 관여하지 않는다.
    exit 0
fi

now=$(date +%s)
idle_count=0
busy=""

for pid in "${workers[@]}"; do
    [ -r "/proc/$pid/cmdline" ] || continue

    cmdline=$(argv_of "$pid")

    # 세션 jsonl 경로 확보: `--resume <path>` 형태가 우선, 없으면 `--session-id <uuid>`로 역추적
    jsonl=$(printf '%s\n' "$cmdline" | grep -m1 -E '\.jsonl$')
    if [ -z "$jsonl" ]; then
        sid=$(printf '%s\n' "$cmdline" | grep -A1 -m1 -x -- '--session-id' | tail -1)
        if [ -n "$sid" ]; then
            jsonl=$(find /root/.claude/projects -maxdepth 2 -name "${sid}.jsonl" -print -quit 2>/dev/null)
        fi
    fi

    # 세션 파일을 특정할 수 없으면 보존 (판단 불가 = 살려둔다)
    if [ -z "$jsonl" ] || [ ! -f "$jsonl" ]; then
        busy="$busy $pid(세션파일미상)"
        continue
    fi

    proc_age=$(ps -o etimes= -p "$pid" 2>/dev/null | tr -d ' ')
    [ -z "$proc_age" ] && continue
    file_idle=$(( now - $(stat -c %Y "$jsonl") ))

    # 유휴 판정: 프로세스가 충분히 오래 살아있고 + 세션 기록도 그동안 갱신되지 않음
    if [ "$proc_age" -ge "$IDLE_SECS" ] && [ "$file_idle" -ge "$IDLE_SECS" ]; then
        idle_count=$(( idle_count + 1 ))
    else
        busy="$busy $pid(유휴 $(( file_idle / 60 ))분)"
    fi
done

# 3) 전부 유휴일 때만 데몬째 정리
if [ -n "$busy" ]; then
    log "SKIP 활동 중인 워커 있음:$busy (유휴 ${idle_count}/${#workers[@]})"
    exit 0
fi

# 회수량 추정: 데몬 + 워커 래퍼 + 각 워커의 자식(실제 CLI 프로세스, 메모리 대부분을 차지)
reap_pids="$daemon_pid ${workers[*]}"
for pid in "${workers[@]}"; do
    reap_pids="$reap_pids $(pgrep -P "$pid" 2>/dev/null | tr '\n' ' ')"
done
# shellcheck disable=SC2086  # 의도적 워드 분할: 중복 공백을 정리해 쉼표 목록을 만든다
reap_list=$(echo $reap_pids | tr ' ' ',')
rss_mb=$(ps -o rss= -p "$reap_list" 2>/dev/null | awk '{s+=$1} END {printf "%d", s/1024}')
[ -z "$rss_mb" ] && rss_mb=0

if [ "$DRY_RUN" -eq 1 ]; then
    log "DRY-RUN 워커 ${#workers[@]}개 전부 ${IDLE_HOURS}시간 이상 유휴 → 정리 대상 (약 ${rss_mb}MB)"
    exit 0
fi

if "$CLAUDE_BIN" daemon stop --any >/dev/null 2>&1; then
    log "정리완료 유휴 워커 ${#workers[@]}개 + 데몬 종료 (약 ${rss_mb}MB 회수, 기준 ${IDLE_HOURS}시간)"
else
    log "실패 'claude daemon stop --any' 종료코드 $? (워커 ${#workers[@]}개)"
fi
