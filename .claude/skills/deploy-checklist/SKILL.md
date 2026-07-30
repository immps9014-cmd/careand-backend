---
name: deploy-checklist
description: 케어앤(Care&) 플랫폼 배포 전/후 점검 — backend/admin/member/www/ai 5개 컴포넌트별 위험요소와 careand-deploy 스크립트가 자동으로 처리하지 못하는 부분을 확인한다. "배포해도 되는지", "배포 전 점검", "배포 준비됐는지", "release 체크리스트" 요청 시 사용한다.
---

`careand-deploy <backend|admin|member|www|ai>` (`/usr/local/bin/careand-deploy`) 실행 전/후 점검한다.
스크립트가 이미 자동으로 처리하는 항목은 "자동" 표시, **사람이 직접 확인/실행해야 하는 항목만 실패로 카운트**한다.

## 공통 (모든 컴포넌트)

- [ ] `.env` 시크릿을 코드/커밋에 하드코딩하지 않았는가
- [ ] production DB를 마이그레이션 없이 직접 UPDATE/DELETE하지 않았는가
- [ ] 배포 대상 디렉토리에 git으로 추적되지 않는 임시/실험 파일이 섞여 있지 않은가 (스크립트가 `git add -A`로 전부 스냅샷 커밋하므로)

## backend (careand-backend, `/var/www/careand-backend`)

**자동**: config/route/view 캐시 클리어 → `migrate --force` → 실패 시 `git reset --hard`로 코드 롤백(스냅샷 커밋 이전) → `careand-queue` 재시작 → `/api/v1/health` 확인 → 헬스 실패 시에도 코드 롤백.

- [ ] **마이그레이션에 `ALTER TABLE ... AFTER` + `INSTANT` 알고리즘 조합이 없는가** — MariaDB 10.3이라 이 조합을 지원하지 않아 `migrate --force`가 실패할 수 있음. 실패하면 코드는 자동 롤백되지만 **DDL은 자동 롤백되지 않으므로 부분 적용된 DB는 수동 점검 필요** (실수로 되돌려진 커밋은 `git reflog`로 복구 가능)
- [ ] **라우트를 추가/변경했다면, 배포 후 수동으로 `php artisan route:cache && php artisan config:cache && systemctl reload php-fpm` 실행했는가** — 스크립트는 `route:clear`만 하고 `route:cache`는 하지 않는다. 이걸 빼먹으면 신규 라우트가 404
- [ ] 외부연동(OTP/PG/FCM/NHIS/홈택스/복지부) 관련 변경이면 `config('services.external.stub')` 스텁 분기가 유지되는가 (`APP_ENV`가 아니라 `EXTERNAL_STUB`로 분기)
- [ ] 매칭후보/AI일지 등 비동기 처리를 동기 처리로 되돌리지 않았는가 (`GenerateMatchCandidatesJob`, `GenerateCareLogJob` 등 큐 Job 유지)

## admin / member / www (Next.js, `/root/caren/careand-{admin-web,member-web,www}`)

**자동**: `.next` → `.next.prev` 백업 → `npm run build` (빌드 실패 시 기존 빌드 유지, 재시작 안 함) → systemd restart → basePath HTTP 상태코드 확인(15회 재시도) → 기동 실패 시 `.next.prev`로 롤백.

- [ ] 빌드에 필요한 환경변수(`NEXT_PUBLIC_*` 등)가 실제 서버 `.env`에 반영됐는가 — **빌드/HTTP 상태코드 체크는 통과해도 런타임에만 드러나는 문제**(예: API URL 미설정)는 스크립트가 못 잡는다
- [ ] (www만) 서버사이드 self-fetch가 `/etc/hosts`의 `127.0.0.1 caren.aiclaude.kr` 항목에 의존 — 이 항목이 지워지면 HTTP 헬스체크는 정상(2xx)인데 통계 API만 조용히 타임아웃-폴백(더미 문구)되므로 배포 후 직접 화면 확인 필요
- [ ] 회원가입/역할 관련 변경이면 대리형(요양·간병·아이·마음) vs 본인형(산모산후·가사) 분기, `guardians.intent` 처리가 깨지지 않았는가
- [ ] 돌봄전문가(인력) 개인정보가 비로그인 상태로 노출되는 화면/API가 새로 생기지 않았는가
- [ ] `.next.prev`를 배포 스크립트 외 다른 방법으로 수동 삭제하지 않았는가 (롤백용이므로 존재해야 함)

## ai (careand-ai-service, `/root/caren/careand-ai-service`)

**자동**: 스냅샷 커밋 → `careand-ai` 재시작 → `/health` 확인(10회 재시도).
**주의: 헬스 실패 시 backend/frontend와 달리 자동 롤백이 없다** — 스크립트는 `git reset --hard $PREV` 명령을 메시지로만 안내하고 실제로 실행하지 않는다. 헬스 실패 시 반드시 `journalctl -u careand-ai`로 원인 확인 후 **수동으로** `git reset --hard <PREV해시>` + `systemctl restart careand-ai` 실행해야 한다.

- [ ] 각 엔드포인트가 LLM(`ANTHROPIC_API_KEY` 있을 때) 우선, 실패/미설정 시 룰/템플릿 폴백하는 구조를 유지하는가 — 폴백 경로를 이번 변경으로 제거하지 않았는가 (키 미설정 환경에서도 서비스가 죽지 않아야 함)
- [ ] venv를 이동/재생성하지 않았는가 — 이동했다면 `venv/bin/*` shebang이 구 경로를 가리켜 `systemctl start`가 `203/EXEC`로 죽으므로 `venv/bin/` 전체 shebang 일괄 치환 필요
- [ ] whisper 모델 교체가 포함된 배포라면 `.env.bak-*` 백업 관례를 따랐는가 (`swap_whisper*.sh`)

## 배포 후 공통 확인

- [ ] `careand-deploy` 종료 코드/로그에 `FAIL`이 없는가
- [ ] 실패 후 자동 롤백이 일어났다면, 롤백된 커밋이 의도한 이전 상태가 맞는지 `git log`로 확인 (backend/frontend는 자동, ai는 위 항목대로 수동 롤백 필요)
- [ ] 큐 워커(`careand-queue`)가 이번 배포로 재시작됐는가 확인 필요한 변경이었다면 `systemctl status careand-queue`로 기동 시각 확인
