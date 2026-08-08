# Care& Portal (`public/portal.html`) — 디자인 노트

`ui-ux-pro-max` 스킬 기준 감사·적용 기록 (2026-08-08). 이 파일은 스킬의 `--design-system --persist`
산출물이 아니라, 그 산출물(범용 랜딩페이지 템플릿)이 이미 존재하는 프로덕션 페이지에는 그대로
안 맞아 직접 정리한 요약이다. 다음에 이 페이지를 다시 손볼 때 왜 이런 값을 썼는지 참고할 것.

## 브랜드 팔레트 — admin-web/member-web/www와 공유하는 SSOT를 따른다

`careand-admin-web`(tailwind.config.ts), `careand-member-web`·`careand-www`(lib/theme.ts)가 공유하는
코랄/테라코타 브랜드(`brand.DEFAULT #D5603E`, `brand.600 #B94C2E`, `brand.100 #FAE3DB`)를 그대로 가져와
`--coral`/`--coral-d`/`--coral-soft` 변수에 반영했다.

**발견한 버그**: 기존 코드는 `--coral` 변수명에 `#10B981`(emerald green)을 담고 있었다 —
`careand-www/lib/theme.ts`의 주석("초록 emerald 대체" — 06-24 리브랜딩)에 따르면 플랫폼은 emerald에서
코랄로 이미 전환했는데, 이 포털 페이지만 리브랜딩 이전 색을 계속 쓰고 있었다. 회원 로그인 CTA, 히어로
그라디언트 텍스트, 서비스 카드 강조색 등 페이지의 주 브랜드 표현이 전부 옛 초록색으로 렌더링되고
있었던 것 — 단순 스타일 취향이 아니라 실제 브랜드 불일치 버그였다.

`--blue`도 플랫폼 시맨틱 토큰(`info: #3B82F6`)에 맞춰 `#3D5AFE` → `#3B82F6`로 정렬했다(관리자 콘솔
쪽 진입점을 회원과 구분하는 보조색 역할은 유지).

배경 블롭·카드 워시 등 순수 장식 그라디언트(민트/블루/틸 톤)는 브랜드를 주장하는 요소가 아니라서
그대로 두었다.

## 적용한 변경

1. **폰트 self-host**: `cdn.jsdelivr.net`(Pretendard) 외부 CDN 참조를 제거하고 admin/member/www와 동일하게
   `public/fonts/PretendardVariable.woff2`를 직접 서빙하도록 전환. 서버 아웃바운드 화이트리스트 차단
   리스크를 없애고(`[[lt-server-egress-restricted]]` 참고), 4개 프론트엔드의 폰트 로딩 방식을 통일했다.
2. **이모지 → 인라인 SVG 아이콘**: 히어로 CTA, 서비스/기능 카드, 등록 안내, 역할 카드, 제휴 문의 섹션의
   이모지 아이콘(💚📝🛡️🧓🏥🧹🤝📡🗣️💳👪📊🩺🏢☎✉)을 전부 stroke 기반 인라인 SVG로 교체
   (`ui-ux-pro-max` priority 4 안티패턴: "Emoji as icons"). 체크마크(✓)는 픽토그램이 아닌 타이포그래피
   기호라 그대로 유지.
3. **브랜드색 정정**: 위 참조.

## 손대지 않은 것

- 접근성 체크(대비율/포커스링/reduced-motion)는 이미 준수돼 있었다 — `prefers-reduced-motion` 미디어쿼리
  존재, 포커스링 제거(`outline:none`) 없음, 텍스트 대비도 본문 기준 양호. 새로 고칠 게 없었다.
- `autologin.html`/`autologin-member.html`은 `noindex`가 걸린 개발용 테스트 로그인 스텁(하드코딩된 테스트
  계정)이라 실제 제품 UI가 아니므로 이번 감사 범위에서 제외했다.
