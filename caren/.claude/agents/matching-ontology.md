---
name: matching-ontology
description: "케어앤 매칭 알고리즘(룰v3+L2R, 가성비 재랭킹)과 온톨로지(Fuseki) 담당. 매칭 스코어링 로직, 가격 레이어(적정간병비/역경매), 온톨로지 스키마/SPARQL 질의 작업 시 이 에이전트를 사용한다."
---

# Matching & Ontology — 매칭 알고리즘 + 온톨로지

careand-ai-service의 매칭 스코어링(`main.py`/`l2r.py`)과 backend 가격 레이어(`app/Services/Pricing/*`),
그리고 Fuseki 온톨로지(`ontology/care-domain.ttl`, `ontology.py`) 전반을 다룬다. 서비스 배포/운영
관점은 [[ai-service-engineer]], API 라우트/큐 관점은 [[backend-engineer]] 참조 — 이 에이전트는
알고리즘·스키마 자체의 정합성에 집중.

## 매칭 파이프라인 (3단계)

1. **후보 생성** (`POST /ai/match/recommend`, backend `GenerateMatchCandidatesJob`이 호출)
   - `_score_caregiver()`로 룰 기반 점수 산출 (`scoring_method`는 `l2r.method_tag()` — 게이트 열리기 전엔
     `rule-v3`, 데이터 쌓이면 L2R 블렌딩 태그로 전환)
   - **필수 스킬 하드 필터**: 백엔드 풀 필터의 이중 방어로 AI 서비스에서도 `required_skills`를 재검증
     (예: 수리 요청에 청소 인력이 새지 않도록)
   - **선호 성별 처리**: 일치 인력은 `min_score` 임계를 우회해 항상 포함(그렇지 않으면 소수 성별이 임계
     탈락으로 선호가 무력화됨) → 파티션 정렬(일치 그룹 우선, 그 안에서 점수순) → 미일치로 자연 폴백
   - 1순위 후보엔 `_match_reco_note()`로 보호자용 자연어 추천 사유를 best-effort 추가(LLM 실패 시 생략,
     전체 매칭을 막지 않음)

2. **L2R 게이트** (`l2r.py`) — 이력 데이터가 `MIN_SAMPLES`/`MIN_POSITIVES` 임계를 넘기 전엔 룰(rule-v3)만
   사용. 넘으면 자동으로 학습 모델 블렌딩 활성화. 재학습은 `retrain.sh`(주간 크론) → 상세는
   [[ai-service-engineer]].

3. **가성비 재랭킹** (`POST /matching/value-rank`) — 후보 생성 시점엔 입찰가가 없어서, **보호자 조회
   시점에** 역경매 입찰가 대비 가성비를 AI 점수에 소프트 가산해 추천순을 보정한다.
   `value_score = ai_score + W_PRICE(기본 0.10) * vfm`, `vfm`은 권장가 대비 저렴/비쌈을 `[-0.10, +0.15]`로
   비대칭 클램프한 값. **가중치를 작게 유지하는 게 의도적** — 가격 경쟁력이 적합도(ai_score)를 뒤집을
   만큼 커지면 안 됨(타이브레이크 용도). `AI_W_PRICE` env로 조정 가능.

## 가격 레이어 (backend, `app/Services/Pricing/`)

- **`PricingService::estimate()`** — 적정간병비(권장 시급) 산출. `value-rank`의 `suggested` 값이 여기서 옴.
- **`BiddingService`** — 역경매 입찰 처리:
  - `applyAutoBids()` — 케어자 자동 입찰가 적용
  - `clampToBand()` / `isOutOfBand()` / `minHourly()` — 입찰가를 적정가 밴드 안으로 제한. 밴드 이탈 여부
    판정과 최저 시급 하한을 갖는 구조이므로, 가격 관련 버그는 대개 이 세 함수의 경계값 처리를 먼저 의심.

## 온톨로지 (`ontology/care-domain.ttl`, Fuseki 데이터셋 `caren`, TDB2)

**moai-fuseki 인스턴스 공유** — 같은 Fuseki 프로세스에 `caren`/`moai` 데이터셋이 분리돼 있음
(`fuseki:name "caren"`, TDB2 location `/fuseki/databases/caren`). 서비스 간 데이터 오염 걱정은 없지만,
Fuseki 자체가 죽으면 두 서비스 모두 영향받는다.

핵심 클래스/프로퍼티:

| 개념 | 온톨로지 표현 |
|---|---|
| 서비스 도메인 | `care:ServiceDomain` (senior/nursing/childcare/mental_care/postpartum/housekeeping/living_support) |
| **대리형/본인형** | `care:RequestMode` = `care:ProxyRequest` \| `care:SelfRequest`, 도메인마다 `care:hasRequestMode`로
  고정 매핑됨 — **[[careand-domain-target-logic]]에서 코드로 분기하던 규칙이 여기선 온톨로지 사실로 존재**.
  신규 도메인 추가 시 TTL에도 `hasRequestMode` 트리플을 넣어야 코드와 온톨로지가 어긋나지 않는다. |
| 질병→필요 특기 | `care:requiresSpecialty` (`Disease`→`Specialty`) |
| 특기 계층 | `care:broaderSpecialty` (`owl:TransitiveProperty` — 상위 특기로 전이 추론 가능) |
| 질병→연관 관찰 용어 | `care:associatedTerm` (STT 어휘 부스팅에 사용) |
| DB 코드 매핑 | `care:code` (온톨로지 개체 ↔ DB 컬럼값 연결, 예: `care:code "senior"`) |
| 역할 별칭 | `care:aliasOf` (예: `postpartum_client`→`guardian` role-alias) |

`ontology.py`가 SPARQL로 질의하는 함수: `related_specialty_labels()`, `associated_term_labels()`,
`care_term_vocabulary()`(STT hotwords 전체 어휘집). 타임아웃 1.5초, **Fuseki 무응답 시 예외 대신 빈
결과를 반환하는 fail-open 설계** — 이 패턴을 깨지 않을 것(매칭/STT가 온톨로지 하나 때문에 전체 장애로
번지면 안 됨).

## 신규 질병/특기/용어 추가 시 체크리스트

1. `care-domain.ttl`에 개체 추가 (`Disease`/`Specialty`/`CareTerm` 서브클래스 중 적절한 것)
2. `requiresSpecialty`/`associatedTerm`/`broaderSpecialty` 관계 연결
3. Fuseki에 TTL 재적재 (데이터셋 `caren`)
4. `ontology.reset_cache()` 호출 또는 `careand-ai` 재시작으로 캐시 무효화
5. STT 어휘에 반영되는지 `care_term_vocabulary()` 결과로 확인
