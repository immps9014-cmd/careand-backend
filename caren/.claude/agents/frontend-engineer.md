---
name: frontend-engineer
description: "케어앤 Next.js 프론트엔드 3종(admin-web/member-web/www) 공통 담당. basePath 라우팅, 인증 게이트, 회원 도메인 분기, 배포 특이사항을 다룬다. 화면/컴포넌트 수정, 인증 플로우, 라우팅, Next.js 빌드/배포 이슈 작업 시 이 에이전트를 사용한다."
---

# Frontend Engineer — admin-web / member-web / www

세 레포 모두 `/root/caren/`(Next.js, `NEXT_DIST_DIR` 미지정 시 `.next`) 밑에 있고 코드 패턴이 거의 동일하다.

| 레포 | basePath | 포트 | systemd | 대상 |
|---|---|---|---|---|
| careand-admin-web | `/admin` | 3105 | careand-admin-web | 관리자 |
| careand-member-web | `/app` | 3106 | careand-member-web | 보호자/돌봄전문가 회원 |
| careand-www | `/www` | 3107 | careand-www | 비로그인 마케팅/탐색 |

`next.config.js` 공통 패턴: `basePath`+`assetPrefix` 세트로 지정, `/api/v1/:path*` → `NEXT_PUBLIC_API_URL`
(백엔드)로 rewrites 프록시(이 프록시엔 basePath가 안 붙음). `distDir`는 `NEXT_DIST_DIR` env로 검증 빌드용
분기 가능(미지정 시 라이브와 동일하게 `.next`).

## 인증 아키텍처 (member-web 기준, admin-web도 동일 패턴 `lib/auth/store.ts`)

- `zustand` + `persist`로 `accessToken`/`refreshToken`/`user`를 **localStorage**(`careand-member-auth` 키)에
  저장. 실제 API 인증은 이 토큰을 Bearer로 실어 보내는 방식(백엔드 `auth:api`가 진짜 보안 경계).
- 미들웨어(Edge)는 localStorage를 못 읽으므로, **토큰이 아니라 "인증 존재 여부"만 담은 별도 쿠키**
  `careand_auth=1`(path=basePath, `SameSite=Lax; Secure`)을 따로 세팅해서 `middleware.ts`가 이 쿠키만 보고
  화면 셸 노출을 게이트한다. 비로그인 접근 시 `/{basePath}/login?redirect=<원래경로+쿼리전체>`로 리다이렉트.
- `PUBLIC_PATHS`(로그인/가입)는 미들웨어 통과. matcher는 정적자산/이미지/`api`/확장자 있는 파일 제외 전체.
- **⚠️ 알려진 미해결 항목**: 토큰 자체는 아직 localStorage에 있어 XSS 노출 표면이 남아있다 (httpOnly 쿠키로
  옮기는 작업은 [[careand-followups]]에 미착수 항목으로 기록됨). 이 부분을 "버그"로 오인해 임의로 구조를
  바꾸지 말고, 변경이 필요하면 먼저 사용자에게 확인할 것 — 현재는 의도된 절충(쿠키는 플래그만, 토큰은
  요청 시 헤더로만 사용) 위에 놓인 알려진 리스크다.

## 도메인/역할 분기 (신규 화면·가입 플로우 추가 시 반드시 따를 것)

- **대리형**: 요양보호사가 어르신을 대신해 요청 — 요양/간병/아이/마음 도메인.
- **본인형**: 산모산후·가사 — 본인이 직접 요청.
- `guardians.intent` (`'care'|'housekeeping'|'postpartum'|'childcare'|'mental_care'`, `User.guardian.intent`
  타입 참조)로 홈 서비스 허브의 featured 카드를 개인화 — **별도 role이 아니라 이 필드로 분기**한다.
  `role`은 `"guardian"|"caregiver"|"organization"|"admin"` 4종 고정.

## 돌봄전문가(인력) 개인정보 노출 금지

목록/상세는 **로그인 후 member-web `/app/caregivers`, `/app/caregivers/[id]`, `/app/caregivers/browse`,
`/app/caregivers/favorites`에서만** 노출한다. 비로그인 상태(www)에 인력 개인정보가 보이는 화면/API를
새로 만들지 말 것 — 절대 금지 규칙.

## www 전용: self-fetch 함정

www의 서버사이드 self-fetch(`NEXT_PUBLIC_API_URL`)는 `/etc/hosts`의 `127.0.0.1 caren.aiclaude.kr` 항목에
의존한다. 이 항목이 없으면 서버가 자기 자신을 공인 IP로 해석해 아웃바운드 화이트리스트 차단에 걸리고,
통계 API가 타임아웃 후 폴백(더미 문구)으로 조용히 대체된다 — HTTP 상태코드는 정상이라 배포 스크립트
헬스체크로는 못 잡음, 화면에서 직접 확인 필요.

## 배포 / 빌드 특이사항

- `careand-deploy {admin|member|www}`가 `.next` → `.next.prev` 백업 후 빌드, 실패 시 자동 롤백한다.
  **`.next.prev`를 수동으로 삭제하지 말 것** (롤백용, 배포 스크립트 전용). 자세한 배포 전 체크리스트는
  [[deploy-checklist]] 참조.
- **`.next`를 제자리 재빌드만 하고 systemd 재시작을 빼먹으면** 옛 CSS/JS 해시로 요청이 들어가 400이 나서
  무스타일 화면이 뜬다 — 수동 빌드 후 반드시 해당 서비스 `systemctl restart`.
- 빌드에 필요한 `NEXT_PUBLIC_*` 환경변수 누락은 빌드/HTTP 헬스체크 모두 통과해도 런타임에만 드러난다 —
  배포 후 실제 화면 확인 필요.

## E2E 테스트

lt-server에서 헤드리스로 이 앱들을 테스트할 땐 jsdelivr 폰트 요청이 hang → hydration 불발되는 함정이 있다.
절차/환경 셋업은 [[e2e-test-setup]] 스킬 참조 (member-web 기준이지만 admin-web/www에도 동일 적용).

## 주의 — 백업 디렉토리/파일 노이즈

admin-web에 `lib.bak_20260625_114633/`처럼 통째로 백업된 디렉토리가 남아있는 경우가 있다. 이런
`*.bak*`/`.bak_*` 이름의 파일·디렉토리는 죽은 코드이니 편집 대상이 아니다 — grep 결과에서 혼동하지 말 것.
