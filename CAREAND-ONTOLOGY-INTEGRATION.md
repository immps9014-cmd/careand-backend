# CareAnd 온톨로지 적용 — 2026-07-30 작업 정리

캐런(caren) 플랫폼의 매칭 엔진과 STT(케어일지 음성인식)에 온톨로지(Apache Jena Fuseki + SPARQL)를
도입한 하루치 작업 전체 기록. 대상 레포: `careand-ai-service`(FastAPI), `careand-backend`(Laravel).

## 1. 아키텍처 결정

- **엔진**: Apache Jena Fuseki. moai 프로젝트에서 이미 이 서버(`gcloud-seoul-...`, `/root/caren`와
  `/home/jesetech/moai`가 물리적으로 같은 서버)에 Docker Hub 차단을 dnf installroot로 우회해
  구축해둔 `fuseki-base:jre21` 로컬 이미지와 `moai-fuseki` 컨테이너가 있어 그대로 재사용.
- **배치**: 전용 컨테이너를 새로 띄우지 않고 **`moai-fuseki` 컨테이너를 공유** — RAM 여유가
  거의 없었음(당시 available 535Mi, swap 2Gi/3Gi 사용 중)이 결정적 이유. 같은 Fuseki 인스턴스
  안에 `caren`이라는 별도 TDB2 데이터셋만 추가(`/moai`, `/caren` 공존). host network +
  `--localhost` 플래그로 이미 떠 있어 관리 API도 그대로 사용 가능.
- **파일 위치**: 처음엔 `/root/caren/ontology/`에 스키마·스크립트를 뒀으나, `/root` 최상위는
  `.gitignore`로 전부 무시하는 화이트리스트 repo(설정파일/문서만 추적)라 커밋이 안 됨 →
  `careand-ai-service/ontology/`(그 레포 안)로 옮겨서 커밋.
- 참고용 전용 배포 파일(`ontology/docker-compose.yml`, `ontology/Dockerfile`)도 만들어둠 —
  나중에 RAM이 확보되거나 다른 서버로 분리할 때 쓰는 용도, 지금은 실행 안 함.

## 2. 온톨로지 스키마 (`careand-ai-service/ontology/care-domain.ttl`)

네임스페이스 `http://caren.aiclaude.kr/ontology#`(`care:`). 현재 **271 트리플**.

**클래스**: `ServiceDomain`, `RequestMode`, `ActivityCategory`, `Disease`, `Specialty`, `Role`
(`Guardian`/`Caregiver`/`Recipient` 하위), `GuardianIntent`, `CareTerm`(`HealthStatus`/
`MentalStatus`/`LifeStatus` 하위).

**관계(ObjectProperty)**:
| 관계 | 도메인 → 레인지 | 용도 |
|---|---|---|
| `hasRequestMode` | ServiceDomain → RequestMode | 대리형/본인형 구분 |
| `requiresSpecialty` | Disease → Specialty | 질병-특기 매핑(매칭 feature) |
| `broaderSpecialty` | Specialty → Specialty(전이) | 특기 상하위(예: 인지자극 ⊂ 치매케어) |
| `aliasOf` | Role → Role | 역할 별칭(postpartum_client → guardian) |
| `hasIntent` | Guardian → GuardianIntent | 가사요청자 구분(별도 Role 아님) |
| `associatedTerm` | Disease → CareTerm | 질병별 연관 관찰 용어(STT 개인화용) |

**개체 규모**: 서비스 도메인 6개(senior/nursing/housekeeping·living_support alias/postpartum/
childcare/mental_care), 케어일지 활동분류 7개(`_CATEGORY_LABEL`과 1:1), 특기 7개, 질병 4개
(치매/뇌졸중/당뇨/파킨슨병 — PoC 샘플, 실 DB 전수는 아님), **케어 관찰 용어(CareTerm) 36개**
(건강상태 17 + 정신상태 10 + 생활상태 9 — 욕창·부종·섬망·배회·연하곤란·실금 등).

TTL은 Fuseki 내장 Jena riot 파서로 매 변경마다 문법 검증(`riotcmd.riot --validate`) 후 적재.

## 3. 매칭 엔진 통합 — `ontology_match` feature

**목적**: `l2r.py`의 특기 매칭이 질병-특기 문자열 완전일치만 봐서, "치매" 환자에게 "인지자극"
특기 보유자를 매칭 못 시키던 한계를 온톨로지 추론으로 보완.

- `ontology.py`(신규) — Fuseki SPARQL 클라이언트. stdlib `urllib`만 사용(신규 의존성 없음).
  `related_specialty_labels()`가 `requiresSpecialty`+`broaderSpecialty` 양방향 폐쇄로 관련
  특기를 조회.
- `l2r.py` — `FEATURE_NAMES`에 `ontology_match` 추가(`gender_match`와 동일하게 L2R 전용,
  결정적 `rule_score()`엔 영향 없음). `subscores()`에서 정확일치 없어도 온톨로지 근접매치를
  0~1 점수로 반영, 없으면 기존 `specialty_score`로 조용히 폴백.
- `main.py` — 매칭 근거(reasons)에 `"온톨로지 근접 특기: ..."` 문구 추가.

배포: 커밋 `835ef03` → `careand-deploy ai`.

## 4. 테스트 & 발견된 버그 (매칭)

`/root/CAREAND-TEST-SCENARIO.md`에 8개 케이스로 정리, 전부 실행 완료. 이 중 실제 운영 버그
2건을 테스트 도중 발견·수정:

**버그 1 — Fuseki 장애 후 캐시 고착 (케이스4에서 발견)**
`moai-fuseki`를 실제로 중지시켜 장애를 재현한 결과, 최초 구현(`functools.lru_cache`)이 다운타임 중 조회 실패(빈 결과)까지 캐시해버려서 **Fuseki가 복구된 뒤에도 프로세스가 재시작되기 전까진 계속 폴백 상태로 굳어있는 버그**를 발견(`뇌졸중`으로 재현). `ontology.py`를
수동 dict 캐시로 바꿔 성공한 조회(빈 결과 포함)만 캐시하고 실패는 캐시하지 않도록 수정 →
복구 즉시 정상화 확인. 커밋 `c973946`.

**캐시 무효화 검증 (케이스5)**: TTL에 실제 콘텐츠(치매→정서지원 특기) 추가 → 재적재 →
`reset_cache()` 전엔 구 데이터 유지, 호출 후 신규 데이터 즉시 반영을 확인. 커밋 `61219fc`.

## 5. `train_l2r.py` 재학습 파이프라인 점검

재학습 표본 확인 결과 43개(양성 5개)로 지난 학습(2026-07-25)과 동일 — 최근 새 매칭 데이터가
안 쌓인 상태. 점검 중 **postpartum 도메인 학습 데이터 오염 버그** 발견:

`build_dataset()`이 모든 도메인을 `senior_id` 기준 `recipient_features()`에 넣고 있었는데,
postpartum 요청은 `senior_id`가 NULL(비시니어 도메인, DB 마이그레이션 `2026_06_12_100001`)이라
`diseases=[]`/`lat=lng=None`인 가짜 행이 섞이던 버그. 게다가 `/matching/postpartum`은 애초에
`l2r.py` 파이프라인을 안 쓰고 별도 룰스코어러(`_score_postpartum_caregiver`)로 서빙되므로,
이 학습 데이터는 실제 쓰이지도 않는 곳에 노이즈만 보태고 있었음.

`l2r.DOMAIN_WEIGHTS`(실제 rule_score가 다루는 도메인)에 없는 도메인은 스킵하도록 수정 —
43→41 샘플(postpartum 2건 제외)로 정리. 커밋 `bdbf99b`. 정리된 데이터로 재학습해
`model.joblib`/`meta.json` 갱신(`models/`는 `.gitignore` 대상이라 커밋 불필요) — 표본이
여전히 임계(200/60) 미달이라 게이트오프 상태 유지, 라이브 매칭 동작엔 영향 없음.

## 6. STT 어휘 확장 — whisper `hotwords` 연동

**동기**: 케어일지 STT(faster-whisper) 인식률을 올리려면, "욕창"·"섬망"·"연하곤란" 같은
케어 전문용어를 사전에 힌트로 넘기는 게 효과적 — 온톨로지의 `CareTerm` 어휘(36개)를 그대로
whisper의 `hotwords` 파라미터로 활용.

- `ontology.py`에 `care_term_vocabulary()` 추가 — 전역 어휘 SPARQL 조회, 프로세스 생애주기
  캐시(입력값 없는 전역 조회라 캐시 키 불필요), Fuseki 미가용 시 빈 결과 폴백.
- `main.py` `/ai/voice/transcribe`가 이 어휘집을 `hotwords`로 whisper에 전달. 빈 어휘여도
  `hotwords=None`으로 기존과 동일하게 동작해 STT 가용성엔 영향 없음.
- 로컬 whisper(`models/small-ct2`)로 라이브러리 레벨 스모크 테스트 + 실제 엔드포인트 호출로
  검증 완료.

배포: 커밋 `1a26127` → `careand-deploy ai`.

**장애 복원력 재확인(테스트 케이스8)**: `moai-fuseki` 중지 + `careand-ai` 재시작(콜드 캐시)
상태에서 STT·매칭 동시 호출 → 둘 다 200(어휘/온톨로지 근거만 빠짐). 복구 후 재시작 없이
바로 정상 어휘 재개 확인(버그1 수정 덕분).

## 7. 환자별 맞춤 STT 어휘 — API 계약 확장

**설계 검토**: 기존 코드 컨벤션(`summarizeCareLog`가 이미 `diseases`를 인라인으로 넘기던 방식,
`/ai/match/recommend`의 `senior: dict` 패턴)을 그대로 따라, ID 기반 조회가 아니라 호출 시점에
이미 로드된 데이터를 그대로 실어 보내는 방식으로 결정. `nursing_patients`도 `seniors`와 동일한
`diseases` 컬럼을 가져서 필드 하나로 두 도메인을 커버.

- `TranscribeRequest.diseases: list[str] = []`(옵션, 하위호환) 추가.
- `ontology.py`에 `associated_term_labels()` 추가 — 질병별 `associatedTerm` 연관 용어 조회,
  **전역 어휘와 합집합**(교체 아님 — 진단명에 없는 증상도 여전히 인식돼야 하므로, 지금
  어휘 규모(36개)에서는 좁혀도 실익이 없고 놓치는 위험만 있음).
- `AiService::transcribe()`에 `diseases` 파라미터 추가.

배포: ai-service 커밋 `72ac820`, backend 커밋 `555ade5` → 양쪽 다 `careand-deploy`.

## 8. 이 과정에서 발견·수정한 별개 버그 2건

계약 확장을 실제로 배선하다가 발견한, 온톨로지와 무관한 기존 버그들:

**버그 A — `ProcessVoiceLogJob`의 nursing/postpartum 처리 실패**
`$session->match->request->senior`를 하드코딩 참조하고 있어서, `senior_id`가 NULL인
비시니어 도메인(nursing/postpartum/housekeeping) 음성일지는 LLM 요약 단계에서 null 속성
접근으로 예외가 나 **항상 `status=failed`로 죽고 있었음**(실제 운영 버그). `MatchRequest::
recipientFeatures()`/`recipientName()`(이미 `GenerateMatchCandidatesJob`이 쓰는 검증된
도메인 추상화)로 교체해 수정. backend 커밋 `555ade5`.

**버그 B — `SeniorController`에만 빠진 삭제 가드**
버그 A를 검증하던 중 `postpartum_clients` id=3이 소프트 삭제됐는데도 `match_request`
id=24(status `matched`, 실제 확정된 매칭 존재)가 계속 그걸 참조하는 고아 데이터를 발견.
조사 결과 앱 코드엔 이 삭제를 유발하는 경로가 없어(컨트롤러 destroy 메서드 자체가 없음)
수동 테스트 데이터 정리 흔적으로 판단, 데이터는 그대로 두기로 함. 대신 같은 문제가
시니어 도메인에서도 날 수 있는지 확인했더니, **`NursingPatientController`/`ServiceAddressController`엔 이미 있는 "진행 중인 매칭 있으면 삭제 차단" 가드가 `SeniorController::destroy()`에만 빠져있었음** — 기존 두 컨트롤러와 동일한 패턴
(`whereIn('status',['open','matching','matched'])->exists()` → `422 HAS_ACTIVE_REQUEST`)으로
통일. backend 커밋 `3e518fd`.
(postpartum/child/mental_care 도메인은 애초에 삭제 API 자체가 없어 지금 막을 대상이 없음 —
나중에 삭제 기능이 생기면 그때 같은 패턴을 넣으면 됨.)

## 9. 배포 이력 요약

**careand-ai-service** (`careand-deploy ai`, 총 6회):
```
835ef03 feat(matching): 온톨로지 기반 특기 매칭 feature(ontology_match) 추가
c973946 fix(ontology): Fuseki 장애 결과를 캐시하지 않도록 수정
61219fc feat(ontology): 치매 requiresSpecialty에 정서지원 특기 추가
bdbf99b fix(l2r): 학습 데이터에서 postpartum 도메인 요청 제외
1a26127 feat(stt): 온톨로지 케어 용어 어휘집을 whisper hotwords로 연동
72ac820 feat(stt): TranscribeRequest에 diseases 필드 추가해 STT 어휘 개인화
```

**careand-backend** (`careand-deploy backend`, 총 2회, 마이그레이션 없음):
```
555ade5 fix(voice-log): 도메인별 대상자 추상화로 nursing/postpartum 음성일지 처리 버그 수정
3e518fd fix(senior): 진행 중인 매칭 요청 있으면 삭제 차단
```

모든 배포 후 health check 통과, 양쪽 레포 워킹트리 깨끗한 상태.

## 10. 남은 작업

- `train_l2r.py` 재학습해도 표본(41/4)이 임계(200/60)에 크게 못 미쳐 `ontology_match`가
  아직 실제 랭킹엔 영향 없음(근거 문구로만 노출) — 표본이 쌓이면 자동으로 활성화됨
- TTL의 질병/특기는 PoC 샘플(4개 질병) 수준 — 실 DB `diseases`/`specialties` 컬럼 값
  전수로 확장 필요
- STT 개인화(`diseases` 필드)·nursing 버그 수정은 아직 `CAREAND-TEST-SCENARIO.md`에
  케이스로 안 남김
- postpartum/child/mental_care 도메인 삭제 API 자체가 없음(별도 기능 개발 필요 시 논의)

## 11. 관련 문서

- 테스트 시나리오 전체(8케이스, 재현 커맨드 포함): `/root/CAREAND-TEST-SCENARIO.md`
- 온톨로지 스키마/배포 스크립트: `careand-ai-service/ontology/`
  (`care-domain.ttl`, `setup-caren-dataset.sh`, 참고용 `docker-compose.yml`/`Dockerfile`)
