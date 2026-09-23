#!/bin/bash
# 인증서 3장을 DNS-01(가비아 수동 TXT) → HTTP-01(완전 자동)로 전환한다. 2026-09-23
#   가비아는 NS 레코드도 API 도 없어 DNS-01 자동화가 불가 → 모든 vhost 공통 경로
#   /.well-known/acme-challenge/ (conf.d/05-acme-challenge.conf) 로 확인받는다.
#   인증서 이름(경로)은 그대로 둔다 → Apache vhost 설정 무수정.
#   aiclaude.kr-wildcard 는 이름만 남고 와일드카드 대신 실제 호스트 24개 목록 인증서가 된다.
#   새 aiclaude.kr 호스트를 만들면 이 스크립트의 목록에 추가하고 다시 실행한다.
set -euo pipefail
GTS=https://dv.acme-v02.api.pki.goog/directory
ACCT=142d73b90e7bc5f3a4f6a64c087ea137
WR=/var/www/acme-challenge
BAK=/root/letsencrypt-renewal.bak-20260923
COMMON=(--non-interactive --agree-tos --webroot -w "$WR" --server "$GTS" --account "$ACCT" --force-renewal)

mkdir -p "$BAK"
for n in aiclaude.kr-wildcard www.kgmca.kr telom-x.com; do
  [ -d "$BAK/live-$n" ] || cp -a "/etc/letsencrypt/live/$n" "$BAK/live-$n"
done

AI=()
for h in asb auth careand caren crm docs edu factory g gmca hisens hpw htj infurs jesetech kcro lt nature-food shop sjm spacer sslnc telomx www; do
  AI+=(-d "$h.aiclaude.kr")
done

run() { echo "== $1"; shift; certbot certonly "${COMMON[@]}" "$@" 2>&1 | tail -6; }
run aiclaude.kr --cert-name aiclaude.kr-wildcard "${AI[@]}"
run kgmca.kr    --cert-name www.kgmca.kr -d www.kgmca.kr -d kgmca.kr
run telom-x.com --cert-name telom-x.com  -d telom-x.com -d www.telom-x.com

apachectl configtest && systemctl reload httpd
echo "== 결과"
for n in aiclaude.kr-wildcard www.kgmca.kr telom-x.com; do
  printf '%-22s ' "$n"; openssl x509 -in "/etc/letsencrypt/live/$n/cert.pem" -noout -issuer -enddate | tr '\n' ' '; echo
  grep -E '^(authenticator|server)' "/etc/letsencrypt/renewal/$n.conf" | tr '\n' ' '; echo
done
