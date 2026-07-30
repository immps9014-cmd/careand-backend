---
name: e2e-test-setup
description: careand-member-web(및 동일 스택인 admin-web/www)을 lt-server에서 헤드리스 브라우저로 E2E 테스트할 때의 필수 셋업과 함정. "E2E 테스트", "헤드리스 테스트", "hydration 안 됨", "playwright로 확인" 요청 시 사용한다.
---

lt-server(103.55.191.157, 아웃바운드 화이트리스트 제한)에서 careand Next.js 앱을 playwright로 헤드리스 테스트할 때 반드시 지켜야 할 것들.

## 핵심 함정: 외부 CDN 폰트가 hang → hydration 불발

앱이 Pretendard 폰트를 `https://cdn.jsdelivr.net/gh/orioncactus/pretendard/...`에서 로드하는데,
이 서버는 jsdelivr(Cloudflare)로 나가는 아웃바운드가 차단돼 있어 **응답 없이 hang**한다.
HTML 파서가 멈추면서 `document.readyState`가 계속 `loading`에 머물고, end-of-body 스크립트가
실행되지 않아 **React hydration이 아예 일어나지 않는다** (`window.__next_f` length 0, 링크 클릭이
SPA 라우팅이 아니라 풀 리로드로 동작). **제품 버그가 아니다** — 실제 사용자 환경(인터넷 정상 접근)에서는
문제없이 로드된다. playwright 버전 문제로 오인하기 쉬우니 먼저 이걸 의심할 것.

**해결**: 테스트 컨텍스트에서 외부 폰트/CDN 요청을 abort한다.

```js
await page.route(/cdn\.jsdelivr\.net|fonts\.googleapis\.com|fonts\.gstatic\.com|unpkg\.com/, r => r.abort());
```

`goto`는 `waitUntil:"commit"`을 쓴다 (`load`/`networkidle`은 차단 안 하면 폰트 hang 때문에 안 끝남).
hydration 완료 대기는 `__reactFiber`/`__reactProps` DOM 속성 폴링으로 확인.

## 헤드리스 크롬 구동: 반드시 playwright-core로, 직접 CDP 금지

이 서버의 EDR가 `--remote-debugging-port=NNNN`로 뜬 헤드리스 크롬 프로세스를 **signal 16(SIGSTKFLT)로 즉사**시킨다.
이걸 실행한 Bash 툴 호출까지 **exit 144**로 같이 죽는다 (로그/스크린샷 0 상태로).
`chrome --version`, `--dump-dom about:blank`처럼 포트를 안 여는 호출은 정상 동작한다.

- 손수 CDP-over-TCP(WebSocket 9222 등)를 여는 방식은 이 서버에서 **사용 불가**.
- **playwright-core는 `--remote-debugging-pipe`(fd 3/4)로 통신하므로 EDR에 안 걸리고 정상 동작한다.**
  헤드리스 브라우저는 항상 playwright-core로만 구동할 것.
- `pkill -f chrome`은 명령줄에 "chrome" 문자열이 든 자기 자신(bash) 프로세스까지 매칭돼 죽일 수 있다 —
  `pkill -f chrome-headless-shell`처럼 구체적인 패턴을 쓸 것.
- `run_in_background`/harness 백그라운드로 node를 띄우면 조기 종료되는 현상이 있었다 →
  `timeout 75 node pw_verify.js`처럼 **포그라운드 실행**이 안정적.

## 환경 셋업

- playwright(전체 패키지)는 npm 미설치 상태. `npm i playwright-core --registry https://registry.npmmirror.com/`
  ([[lt-server-npm-reverse-tunnel]] 미러 경유)로 scratchpad에 설치.
  기존 설치본이 있다면 재사용 가능: `/root/caren/careand-member-web/.ds-sync/node_modules/playwright-core`.
- 브라우저 캐시: `~/.cache/ms-playwright/chromium-1223`(+`chromium_headless_shell-1223`).
  **playwright-core@1.60.0이 rev 1223과 매칭된다** (1.61.1은 rev 1228을 기대해서 설치 실패) — 버전 반드시 1.60.0으로 고정.
- executablePath 후보 둘 다 동작 확인됨:
  - `~/.cache/ms-playwright/chromium-1223/chrome-linux64/chrome`
  - `~/.cache/ms-playwright/chromium_headless_shell-1223/chrome-headless-shell-linux64/chrome-headless-shell`
- launch args: `["--no-sandbox", "--disable-gpu", "--ignore-certificate-errors", "--host-resolver-rules=MAP caren.aiclaude.kr 127.0.0.1"]`
- context: `ignoreHTTPSErrors: true`

## 로그인 세션 만들기

두 가지 방식이 있고 **안정성은 실제 UI 로그인 쪽이 더 높다**:

1. **UI 폼 로그인 (권장, 더 안정적)**: 로그인 폼에 직접 입력→제출. persist 스토어 주입은 hydration
   타이밍과 겹쳐 불안정한 경우가 있었음.
   - 데모 계정: `guardian0@demo.careand.kr` / `Demo1234!` (보호자), `test@gmail.com` / `test1234` (기관)
2. **localStorage/쿠키 직접 주입**: `page.addInitScript`로 localStorage 키 `careand-member-auth` =
   `{"state":{user,accessToken,refreshToken,isAuthenticated:true},"version":0}` + 쿠키
   `careand_auth=1`(domain=`careand.aiclaude.kr`, path=`/app`, 미들웨어 게이트용) 주입.
   `waitUntil:"networkidle"` + 1.5초 대기 조합에서 성공한 사례 있음.
   또는 백엔드에 직접 `POST /api/v1/auth/login`(Host 헤더 `careand.aiclaude.kr`, `--resolve 127.0.0.1`)으로
   토큰을 먼저 받아와 주입하는 방법도 가능.

미들웨어는 비로그인 접근 시 `/app/login?redirect=<원래경로+쿼리>`로 리다이렉트한다 ([[careand-platform-topology]] 참조).

## 최소 동작 예시

```js
const { chromium } = require('/root/caren/careand-member-web/.ds-sync/node_modules/playwright-core');

const browser = await chromium.launch({
  headless: true,
  executablePath: process.env.HOME + '/.cache/ms-playwright/chromium-1223/chrome-linux64/chrome',
  args: ['--no-sandbox', '--disable-gpu', '--ignore-certificate-errors',
         '--host-resolver-rules=MAP caren.aiclaude.kr 127.0.0.1'],
});
const ctx = await browser.newContext({ ignoreHTTPSErrors: true });
const page = await ctx.newPage();
await page.route(/cdn\.jsdelivr\.net|fonts\.googleapis\.com|fonts\.gstatic\.com|unpkg\.com/, r => r.abort());
await page.goto('https://caren.aiclaude.kr/app/login', { waitUntil: 'commit' });
// ... 로그인 폼 입력 → 제출 → 목표 페이지 networkidle 대기 → DOM probe / 스크린샷
await browser.close();
```

이 스킬은 admin-web/www에도 동일하게 적용된다 (같은 Next.js 스택, 같은 jsdelivr 의존성).
