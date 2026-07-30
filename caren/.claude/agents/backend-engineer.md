---
name: backend-engineer
description: "케어앤 Laravel 백엔드(careand-backend) 담당. API 라우트/컨트롤러, 큐 Job, 마이그레이션, 외부연동 스텁, 인증/권한을 다룬다. backend API 수정, 마이그레이션 작성, 큐 Job 추가/변경, 외부연동(OTP/PG/FCM/NHIS 등) 작업 시 이 에이전트를 사용한다."
---

# Backend Engineer — careand-backend

`/var/www/careand-backend` (Laravel 10, PHP-FPM 127.0.0.1:9000, DB `careand_platform` MySQL, 캐시/큐 Redis).
Apache DocumentRoot·PHP-FPM 설정 의존성 때문에 `/root/caren`이 아니라 이 경로에 그대로 있음.

## 인증/권한

- API 인증은 **JWT**(`tymon/jwt-auth`, guard `api`, driver `jwt`) — **Sanctum이 아님**. 토큰명은
  `access_token`/`refresh_token`.
- 역할 기반 접근제어는 `spatie/laravel-permission` — 관리자 전용 라우트는 `middleware('role:admin')`.
- 공개(비로그인) 엔드포인트는 `routes/api.php`의 `Route::prefix('public')->middleware('throttle:60,1')`
  그룹, 나머지는 전부 `middleware('auth:api')`.

## 라우트 구조 (`routes/api.php`, `v1` prefix)

`auth`, `seniors`, `caregivers`, `guardians`, `organizations`, `matching`, `care-sessions`, `payments`,
`settlements`, `anomaly-alerts`, `chatbot`, `notifications` — 일반 인증 그룹.
`admin/*`(dashboard, ai-models, cs, caregivers, care-logs, announcements) — `role:admin` 추가 미들웨어.
`webhooks/*` — 별도 그룹(외부 콜백).

## 외부연동 스텁 분기 (중요 — [[careand-main-claude-md]] 절대 규칙)

`app/Services/External/{Nhis,Hometax,Mohw,PrivateQual,Kuksiwon,Pg,Fcm}Service.php` + `app/Services/OtpService.php`
전부 `config('services.external.stub')`(`config/services.php` → `.env`의 `EXTERNAL_STUB`, 기본값 `true`)로
스텁/실호출을 분기한다. **`app()->environment()`(APP_ENV)로 분기하지 말 것** — 프로덕션이어도 이 서비스들은
스텁을 유지해야 하는 경우가 있어 별도 플래그로 뗀 것. 새 외부연동을 추가할 때도 이 패턴을 따를 것.

## 큐 Job (비동기 처리 — 동기로 되돌리지 말 것)

| Job | 디스패치 위치 | 용도 |
|---|---|---|
| `GenerateMatchCandidatesJob` | `MatchRequestController` | 매칭요청 생성 시 AI 서비스 호출→후보 산출 |
| `GenerateCareLogJob` | `CareSessionController` | 돌봄일지 생성 |
| `ProcessVoiceLogJob` | `CareSessionController` | 음성로그 처리(STT 연동) |

큐 커넥션은 Redis (`QUEUE_CONNECTION=redis`), 워커는 systemd `careand-queue`
(`php artisan queue:work redis`). **Job 코드만 바꾸고 `careand-queue`를 재시작하지 않으면 워커가 옛
코드를 계속 실행한다** — `careand-deploy backend`는 자동으로 재시작하지만, 수동 배포 시엔 직접
`systemctl restart careand-queue` 필요.

## 마이그레이션

- `database/migrations/`에 날짜 접두 파일명 (`2026_06_30_000003_...`).
- **MariaDB 10.3**이라 `ALTER TABLE ... AFTER` + `INSTANT` 알고리즘 조합을 지원하지 않는 경우가 있음 —
  이 조합이 필요한 마이그레이션은 알고리즘을 지정하지 않거나 나눠서 작성할 것.
- production DB 직접 UPDATE/DELETE 금지 — 반드시 마이그레이션 또는 승인된 스크립트로.
- `careand-deploy backend`가 마이그레이션 실패 시 **코드까지 `git reset --hard`로 롤백**한다. DDL은 자동
  롤백되지 않으므로 부분 적용된 DB는 배포 실패 후 수동 점검 필요 (되돌려진 커밋은 `git reflog`로 복구 가능).
  자세한 절차는 [[deploy-checklist]] 참조.

## 라우트/설정 변경 후 필수 절차

라우트나 config를 추가/변경했다면 배포 후 반드시:
```
php artisan route:cache && php artisan config:cache && systemctl reload php-fpm
```
`careand-deploy backend`는 배포 **전** `route:clear`/`config:clear`만 하고 배포 **후** `route:cache`는
하지 않으므로, 캐시를 안 하면 신규 라우트가 404가 난다.

## 도메인 분기 (대리형 vs 본인형)

회원가입/매칭 로직에서 대리형(요양보호사가 어르신을 대신 요청 — 요양/간병/아이/마음)과 본인형(산모산후/가사,
본인이 직접 요청)이 갈린다. `guardians.intent`(`'care'|'housekeeping'`)로 가사요청자를 구분하며 별도
role이 아니다. 새 도메인을 추가할 때 이 분기를 따를 것. 상세는 [[careand-domain-target-logic]].

## 알려진 설계-구현 괴리

매칭 설계서(§9)는 "보호자가 여러 케어자를 지목→가장 먼저 수락한 1명 자동 매칭"(선착순) 방식을 기술하지만,
**현재 구현은 `Admin/OperationsController::manualAssign`(관리자가 단일 인력을 즉시 confirmed 처리,
`is_manual=1`)만 존재** — 설계=목표, 코드=현재상태. 이 영역을 건드릴 땐 어느 쪽이 최신 의도인지 먼저
확인할 것 (섣불리 "설계에 맞게 고치지" 말 것).

## 주의 — `.bak-*` 파일 노이즈

`app/Http/Controllers/Api/`, `app/Jobs/`, `app/Models/` 등에 `*.bak-20260626-cgbrowse` 같은 백업
파일이 다수(25개+) 남아있다. `.php` 확장자가 아니라 Composer 오토로드에는 안 잡히지만, grep/검색 시
살아있는 코드로 착각하기 쉽다 — 파일명에 `.bak`이 있으면 무시할 것. 편집 대상은 항상 `.bak` 없는 원본 파일.
