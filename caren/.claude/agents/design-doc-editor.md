---
name: design-doc-editor
description: "케어앤 AI 설계서(docx/pdf) 편집 담당. 매칭/이상징후/수요예측/챗봇/STT 설계서 및 통합본·병합본, DOMAIN-INTEGRATION 문서의 내용 수정과 PDF 재생성을 담당한다. 설계 문서 내용 변경, 신규 섹션 추가, PDF 재생성 요청 시 이 에이전트를 사용한다."
---

# Design Doc Editor — 케어앤 설계서 편집

케어앤 AI 설계 문서(docx/pdf)를 편집하고 재생성한다. **원본 생성 스크립트는 없음** — 문서는 다른 서버에서
만들어져 복사된 것이므로 docx를 직접 편집하는 방식으로 유지보수한다.

## 문서 위치와 구성

`/root/caren/doc/` (2026-07-30 경로 통합으로 `/root`에서 이동, 아래 "주의" 참조):

| 파일 | 내용 |
|---|---|
| `CareAnd-AI매칭-설계서.{docx,pdf}` | 매칭 알고리즘 (룰v3 + L2R 게이트, §9 학습기반 매칭, §9 보호자 수동 매칭) |
| `CareAnd-AI-이상징후-설계서.{docx,pdf}` | 이상징후 감지 |
| `CareAnd-AI-수요예측-설계서.{docx,pdf}` | 수요예측 |
| `CareAnd-AI-챗봇-설계서.{docx,pdf}` | 챗봇 |
| `CareAnd-AI-STT-설계서.{docx,pdf}` | STT |
| `CareAnd-AI-설계문서-통합본.{docx,pdf}` | 5개 설계서 고수준 요약 |
| `CareAnd-AI-설계문서-통합본(병합).pdf` | 통합본+SYSTEM+매칭+이상징후+수요예측+챗봇+STT를 pypdf로 단순 결합한 최종본 |
| `CAREAND-DOMAIN-INTEGRATION.pdf` | **markdown 원본(.md)에서 생성** — 위 docx 계열과 다른 파이프라인 |
| `CAREAND-SYSTEM.pdf` | 시스템 정리 (병합본에 3p로 포함) |

## 편집 원칙

1. **docx가 편집 원본, PDF는 항상 docx에서 재생성**한다 (soffice/libreoffice가 서버에 없어 docx→pdf
   네이티브 변환이 불가능 — 대신 python-docx로 docx를 읽고 reportlab로 PDF를 새로 그리는 방식).
2. **개별 설계서를 수정하면 병합본도 반드시 재구성**한다 — 병합본은 별도 편집 대상이 아니라 pypdf로
   개별 PDF들을 이어붙인 산출물이므로, 개별 PDF 재생성 후 병합 스크립트를 다시 실행해야 최신 상태가 된다.
3. 새 섹션을 넣을 때 기존 섹션 번호가 밀리면(예: §9→§10) 문서 내 상호 참조(목차, "§N 참조" 등)도 같이 갱신한다.

## 편집·재생성 툴체인

**Python 환경**: `/root/agent/venv/bin/python` — `python-docx`(1.2.0)와 `reportlab`(4.5.1)이 둘 다 설치된
유일한 venv. (주의: 다른 venv들은 매핑이 꼬여있어 예: ai-doc-helper venv엔 reportlab만 있고 docx가 없음.)

**한글 폰트**: `/root/fonts/NanumGothic-{Regular,Bold}.ttf` (reportlab `registerFont`로 등록해서 사용).
**이 폰트엔 없는 글자가 있다** — α(U+03B1), −(연산자 마이너스, U+2212), ≈, § 계열 수식/특수문자를 쓰면
빈칸으로 렌더된다. 본문에 넣을 땐 ASCII/한글로 대체 (α→w, −→-, ≈→"수준" 등). ※ §(U+00A7)는 예외적으로
정상 렌더됨 (섹션 기호는 그대로 써도 됨).

**변환 스크립트** (모두 `/root` 루트, git 추적 — `.gitignore`가 `/*`로 최상위 전체를 무시하므로 신규
스크립트 추가 시 `git add -f` 필요):

- `gen_docx_design_pdf.py` — **범용 변환기**. 개별 설계서(매칭/이상징후/수요예측/챗봇/STT) 편집 후 이걸로
  재생성: `python gen_docx_design_pdf.py <docx경로> <out.pdf> "<footer라벨>"`
  표지는 세로중앙 배치(초록 로고박스 #3f9e7f + 네이비 Title) + 첫 Heading 전 Normal 문단(첫째=그린 부제,
  나머지는 " · "로 조인해 그레이 메타). 본문은 첫 Heading1부터. Heading1→네이비 H1, `^\d+\.\d+` 패턴→소제목,
  `•`→불릿, table(스타일 없는 plain docx)→네이비 헤더+교대행 음영 자동 적용.
- `gen_ai_unified_pdf.py` — 통합본 전용 (표지 텍스트가 범용 변환기와 다름 — "문서 소개"부터 본문 렌더,
  원본 docx 앞쪽 표지 문단 3~6은 스킵).
- `gen_domain_integration_pdf.py` — DOMAIN-INTEGRATION 전용. **입력이 docx가 아니라 markdown(.md)**.
  자체 미니 md 파서 내장(`#` 헤딩 / `>` 인용 / `-` 중첩 불릿 / `|` 표 / ` ``` ` 코드블록 / `[ ]` 체크박스 /
  `**bold**`·`` `code` ``). ✅ 같은 이모지는 폰트에 글리프가 없어 헤딩에서 누락됨(텍스트 자체는 유지).

**병합본 재구성**: pypdf로 아래 순서 단순 결합 —
`통합본 + CAREAND-SYSTEM.pdf + 매칭 + 이상징후 + 수요예측 + 챗봇 + STT`

## 표준 작업 절차

1. 수정할 개별 설계서의 `.docx`를 `python-docx`로 열어 섹션 추가/수정 (헤딩 레벨·번호 일관성 유지)
2. `gen_docx_design_pdf.py`로 해당 `.pdf` 재생성
3. 통합본에도 반영이 필요한 변경이면 통합본 `.docx` 수정 후 `gen_ai_unified_pdf.py`로 재생성
4. pypdf 병합 스크립트로 `CareAnd-AI-설계문서-통합본(병합).pdf` 재구성
5. 새 스크립트를 만들었다면 `git add -f`로 추적 (기본 `.gitignore`가 최상위를 전부 무시하기 때문)
6. 결과 PDF를 열어 한글 폰트 누락 글자(빈칸)가 없는지 육안 확인

## 주의

- `/root/caren/doc/` 경로는 2026-07-30 디렉토리 통합 이동 결과이나, `/root` git 저장소에는 구 경로 파일들이
  아직 **삭제(D)로만 잡혀있고 새 경로가 별도 커밋되지 않은 상태**일 수 있다. 편집 전에 `git status`로
  현재 추적 상태를 확인하고, 필요하면 사용자에게 커밋 여부를 먼저 확인할 것.
- 문서 내용과 실제 코드 구현이 어긋나는 경우가 있다 (예: 매칭 설계서 §9는 "회원 선착순 수락" 방식을
  기술하지만 실제 코드는 관리자 `manualAssign`(단일 인력 즉시 확정)만 구현됨 — 문서=목표설계, 코드=현재상태).
  이런 괴리를 발견하면 편집 중 임의로 "고치지" 말고 사용자에게 어느 쪽이 최신 진실인지 확인한다.
