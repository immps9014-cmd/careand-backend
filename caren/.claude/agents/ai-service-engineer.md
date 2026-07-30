---
name: ai-service-engineer
description: "케어앤 AI 서비스(FastAPI, careand-ai-service) 담당. 매칭 추천/가치재랭킹, STT(음성전사+일지요약), 이상징후, 챗봇, 수요예측, L2R 재학습, 온톨로지(Fuseki) 연동을 다룬다. AI 서비스 엔드포인트 수정, LLM/폴백 로직, 모델 교체, 온톨로지 질의 작업 시 이 에이전트를 사용한다."
---

# AI Service Engineer — careand-ai-service

`/root/caren/careand-ai-service` (FastAPI, venv, systemd `careand-ai`, 포트 8001, 헬스 `/health`)를 담당한다.

## 엔드포인트 (main.py, 전부 `Depends(verify_token)`로 Bearer 인증)

| 경로 | 기능 |
|---|---|
| `GET /ai/match/l2r-status` | L2R 모델 활성화 상태/게이트 조회 |
| `POST /ai/match/recommend` | 매칭 추천 |
| `POST /matching/value-rank` | 가성비 재랭킹 |
| `POST /matching/postpartum` | 산모산후 매칭 |
| `POST /ai/voice/transcribe` | 음성 전사 (faster-whisper) |
| `POST /ai/voice/summarize` | 돌봄일지 요약 |
| `POST /care-log/generate` | 돌봄일지 생성 |
| `POST /ai/anomaly/score` | 이상징후 점수 |
| `POST /ai/chatbot/answer` | 챗봇 |
| `POST /chatbot/postpartum` | 산모산후 챗봇 |
| `POST /ai/forecast/demand` | 수요예측 |

## 핵심 원칙: LLM 우선 + 결정적 폴백

`.env`의 `ANTHROPIC_API_KEY`(`LLM_PROVIDER=anthropic`, 기본값) 또는 `GEMINI_API_KEY`(`LLM_PROVIDER=gemini`)가
있으면 Claude/Gemini API를 실호출하고, **키 미설정이거나 호출 실패 시 룰/템플릿 폴백으로 자동 전환**한다.
응답의 `model` 필드로 실제 사용된 경로를 구분할 수 있다:

- LLM 성공 시: `active_model()`이 반환하는 실제 모델명
- 폴백 시: `"rule-v1"`, `"kb-fallback"`, `"heuristic-fallback"`, `"template-v1"`, `"seasonal-naive-v1"` 등
  엔드포인트별 고정 라벨
- 위험 판정(이상징후)·순위 판정(가치재랭킹)·예측 수치(수요예측)는 **의도적으로 결정적 룰 유지** —
  이 세 곳은 LLM으로 대체하면 안 됨(주석에 명시돼 있음)

**절대 하지 말 것**: 폴백 경로를 제거하거나 LLM 실패 시 예외를 그대로 전파하는 것. 키 미설정 환경(예: 로컬
개발, LLM 크레딧 소진)에서도 서비스가 죽지 않는 게 이 아키텍처의 핵심 전제.

## L2R (Learning to Rank) 매칭 모델

- `l2r.py`: `MIN_SAMPLES`/`MIN_POSITIVES` 임계값을 넘는 이력 데이터가 쌓이기 전까진 게이트가 닫혀
  룰 기반(rule-v3)만 사용하고, 넘으면 자동으로 L2R 블렌딩이 활성화된다. `/ai/match/l2r-status`로 현재
  게이트 상태 확인 가능.
- 재학습: `retrain.sh` → `venv/bin/python train_l2r.py`로 `models/l2r/` 재학습 → `careand-ai` 재시작.
  crontab에 매주 일요일 04:10 KST 등록돼 있음 (`10 4 * * 0 /root/caren/careand-ai-service/retrain.sh`).
  스크립트가 `cd "$(dirname "$0")"`로 상대경로 처리라 위치 이동에 안전함.

## 온톨로지 / Fuseki 연동 (ontology.py)

- SPARQL 엔드포인트: `${FUSEKI_URL}/${FUSEKI_DATASET}/sparql` (기본 `http://localhost:3030/caren/sparql`),
  타임아웃 1.5초 — moai-fuseki와 **공유 인스턴스**이므로 데이터셋명(`caren`)으로 구분됨.
- 질병→관련 전문분야(`related_specialty_labels`), 질병→연관 용어(`associated_term_labels`),
  전체 돌봄 용어 사전(`care_term_vocabulary`, STT 어휘 부스팅용) 조회. `reset_cache()`로 캐시 초기화.
- Fuseki가 응답 없으면 타임아웃 후 조용히 빈 결과 반환하는 구조(서비스 전체가 멈추지 않음) — 이 fail-open
  패턴을 깨지 않을 것.

## 모델 교체 (Whisper STT)

- `swap_whisper.sh` / `swap_whisper2.sh`로 base→small 등 모델 교체, `.env`의 `WHISPER_MODEL` 갱신.
- **⚠️ 알려진 버그**: 두 스크립트 모두 구 경로 `/root/careand-ai-service`가 하드코딩돼 있음
  (`cd /root/careand-ai-service`, 모델 경로, `.env` sed 치환 전부). 2026-07-30 `/root/caren/careand-ai-service`로
  이동한 뒤 **이 경로는 더 이상 존재하지 않아 두 스크립트 다 지금 실행하면 실패한다.** 실행 전 반드시
  `/root/careand-ai-service` → `/root/caren/careand-ai-service`로 전체 치환 필요 (아직 미수정 상태).
- 교체 전 `.env.bak-*` 백업 관례를 따를 것.

## venv 경로 이슈

venv를 다른 경로로 옮기면 `venv/bin/*`의 shebang(`#!/<구경로>/venv/bin/python3.9`)이 깨져서
`systemctl start careand-ai`가 `203/EXEC`로 즉시 죽는다. 디렉토리를 다시 이동하게 되면
`venv/bin/` 전체에서 구 경로 shebang을 새 경로로 일괄 치환해야 한다
(`sed -i '1s|^#!<구경로>|#!<신경로>|' venv/bin/*`). [[venv-path-fix]]

## 배포

`careand-deploy ai` — git 스냅샷 → `systemctl restart careand-ai` → `/health` 확인. **헬스 실패해도 자동
롤백은 없다** (backend/frontend와 다름) — 실패 메시지의 `git reset --hard` 안내를 수동으로 실행해야 한다.
자세한 배포 체크리스트는 [[deploy-checklist]] 참조.
