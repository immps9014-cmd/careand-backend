---
name: ai-model-swap
description: careand-ai-service의 Whisper STT 모델 교체 절차(다운로드→비교→적용→스모크테스트) 및 L2R 매칭 모델 재학습 절차. "whisper 모델 교체", "STT 모델 업그레이드", "L2R 재학습" 요청 시 사용한다.
---

`/root/caren/careand-ai-service`의 두 가지 모델 교체 시나리오: **Whisper STT 모델**과 **L2R 매칭 모델**.

## Whisper STT 모델 교체

이 서버는 CPU 추론(int8)이고 RAM이 빠듯해서(가용 ~1.7G) 기본은 `base`, 필요시 `small` 등으로 상향한다.
모델은 **네트워크에서 자동 다운로드하지 않음**(`local_files_only=True`) — 반드시 사전에 로컬 준비.

**절차** (`swap_whisper2.sh`가 표준, 4단계 자동화):
1. `download_model()`로 신규 모델을 `models/<size>-ct2/`에 다운로드 (최대 8회 재시도)
   - 다운로드가 막히면 `fetch_model.sh`(hf-mirror.com ↔ huggingface.co 교차, resume 지원, 최대 14시간
     재시도)나 `chunked_dl.sh`로 대체 가능
2. **기존 모델과 비교**: 동일 오디오로 base vs 신규 모델 전사 결과·소요시간·신뢰도(`avg_logprob`→`exp`)
   비교 출력. 여러 파일로 비교하려면 `cmp_models.py` 사용
   (`AUDIOS = glob(...storage/app/voice-logs/1/*.webm)` — 실제 업로드된 음성 로그 사용)
3. `.env`의 `WHISPER_MODEL`을 새 모델 경로로 교체 — **교체 전 반드시 `.env.bak-<날짜>-whisper`로 백업**
   (관례 준수, 실패 시 즉시 되돌릴 수 있어야 함)
4. `systemctl restart careand-ai` → `/ai/voice/transcribe` 스모크 테스트(실제 오디오 URL로 호출해 응답 확인)

- [ ] 다운로드 실패 시 `.env`를 건드리지 말고 중단 (스크립트가 이미 이렇게 방어함 — 그대로 유지)
- [ ] 교체 후 `WHISPER_THREADS`(기본 2, 2코어 박스 기준)가 신규 모델 크기에 맞게 여전히 적절한지 확인
- [ ] `swap_whisper.sh`(구버전, 비교만 하고 반영 로직 약함)보다 **`swap_whisper2.sh`를 기본으로 사용**할 것
  — 다운로드 검증(사이즈 임계 400MB 체크)과 실패시 미반영 가드가 더 탄탄함

## L2R 매칭 모델 재학습

`retrain.sh` → `venv/bin/python train_l2r.py`로 `models/l2r/` 재학습 → `careand-ai` 재시작.
crontab에 매주 일요일 04:10 KST 자동 등록(`crontab -l`로 확인). 게이트(`MIN_SAMPLES`/`MIN_POSITIVES`)를
넘는 이력이 쌓이면 이 한 번의 재학습으로 룰 기반(rule-v3)에서 L2R 블렌딩으로 자동 전환된다.
수동 실행 시에도 스크립트가 상대경로(`cd "$(dirname "$0")"`)로 동작해 위치 이동에 안전하다.
게이트 상태는 `GET /ai/match/l2r-status`로 확인 (상세는 [[matching-ontology]], [[ai-service-engineer]]).

## 공통 주의사항

- 모델 교체/재학습 전후로 `journalctl -u careand-ai -n 50`으로 재시작이 깔끔했는지(크래시 없이) 확인.
- 스크립트 내 경로는 전부 절대경로 하드코딩(`/root/caren/careand-ai-service/...`)이므로, **디렉토리를
  다시 이동하게 되면 이 스킬에 나열된 스크립트 전체를 재점검**해야 한다 (2026-07-30 `/root/`→`/root/caren/`
  이동 때 `swap_whisper.sh` 등 5개 스크립트가 구경로로 방치돼 있던 전례 있음).
