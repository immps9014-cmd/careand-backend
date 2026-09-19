#!/bin/bash
# telomx_db 일일 백업 — PMS(프로젝트 관리) 데이터 보호용
# 보관: 14일 / 실행: cron 02:40 (careand-backup 03:30과 시간 분리)
set -uo pipefail

ENV_FILE=/var/www/telomx-backend/.env
DEST=/root/backups/telomx/daily
KEEP_DAYS=14
STAMP=$(date +%Y%m%d-%H%M%S)

mkdir -p "$DEST"

DB_NAME=$(sed -n 's/^DB_NAME=//p' "$ENV_FILE" | tr -d '"'"'"'\r')
DB_USER=$(sed -n 's/^DB_USER=//p' "$ENV_FILE" | tr -d '"'"'"'\r')
DB_HOST=$(sed -n 's/^DB_HOST=//p' "$ENV_FILE" | tr -d '"'"'"'\r')
export PGPASSWORD=$(sed -n 's/^DB_PASSWORD=//p' "$ENV_FILE" | tr -d '"'"'"'\r')

OUT="$DEST/${DB_NAME}_${STAMP}.sql.gz"

# 메모리 여유가 적은 서버라 우선순위를 낮춰 실행합니다.
if nice -n 15 ionice -c3 pg_dump -h "${DB_HOST:-localhost}" -U "$DB_USER" "$DB_NAME" 2>/tmp/telomx-dump.err | gzip -6 > "$OUT"; then
  SIZE=$(du -h "$OUT" | cut -f1)
  echo "[$(date '+%F %T')] OK  $OUT ($SIZE)"
else
  echo "[$(date '+%F %T')] FAIL $(cat /tmp/telomx-dump.err | head -3)"
  rm -f "$OUT"
  exit 1
fi

# 오래된 백업 정리
find "$DEST" -name '*.sql.gz' -mtime +$KEEP_DAYS -delete
echo "[$(date '+%F %T')] 보관 파일 $(ls -1 "$DEST"/*.sql.gz 2>/dev/null | wc -l)개"
