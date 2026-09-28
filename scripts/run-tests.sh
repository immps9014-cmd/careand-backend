#!/usr/bin/env bash
# 백엔드 시험 실행 — 운영 디렉터리를 복사해 설정 캐시·업로드 파일 없이, 시험 DB(careand_platform_test)로만 돌린다 (2026-09-29, S6)
#   사용: scripts/run-tests.sh [phpunit 인자...]   예) scripts/run-tests.sh --testsuite Feature --filter Settlement
# 운영 디렉터리에서 vendor/bin/phpunit 을 직접 돌리지 말 것 — 설정 캐시 때문에 운영 DB 가 대상이 된다(CreatesApplication 이 막기는 함).
set -euo pipefail
SRC="$(cd "$(dirname "$0")/.." && pwd)"
DST="${CAREAND_TEST_DIR:-/var/tmp/careand-backend-test}"
mkdir -p "$DST"
rsync -a --delete \
  --exclude '.git/' --exclude 'storage/app/' --exclude 'storage/logs/' --exclude 'storage/framework/cache/' \
  --exclude 'storage/framework/sessions/' --exclude 'storage/framework/views/' \
  --exclude 'bootstrap/cache/config.php' --exclude 'bootstrap/cache/routes-v7.php' --exclude 'bootstrap/cache/events.php' \
  "$SRC/" "$DST/"
mkdir -p "$DST"/storage/{app/public,logs,framework/{cache/data,sessions,views}}
cd "$DST"
# 외부 연동은 전부 끈다(토스·알림톡 실호출 금지). 시험은 Http::fake 로 필요한 응답만 만든다
export EXTERNAL_STUB=true PG_LIVE=false ALIMTALK_LIVE=false
exec php -d memory_limit=512M vendor/bin/phpunit "$@"
