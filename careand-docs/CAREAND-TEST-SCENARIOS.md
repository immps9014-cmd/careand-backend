# Care& 도메인별 테스트 시나리오 (v1.0, 2026-06-13)

> 운영서버(103.55.191.157) 기준. 서버 안에서 실행 시 헤어핀 NAT 때문에 반드시
> `curl -sk --resolve careand.aiclaude.kr:443:127.0.0.1 https://careand.aiclaude.kr/...` 형태 사용.
> 외부에서는 `https://careand.aiclaude.kr` 그대로.

## 0. 공통 준비

| 항목 | 값 |
|---|---|
| 관리자 | `admin@careand.co.kr` / `test1234` (06-11 하드닝 비번은 `/root/careand-hardening-snap-20260611/new_admin_pw.txt`) |
| 보호자(데모) | `guardian1@demo.careand.kr` / `Demo1234!` (guardian0~9) |
| 요양보호사(데모) | `caregiver1@demo.careand.kr` / `Demo1234!` (caregiver0~19) |
| AI 서비스 | `http://127.0.0.1:8001` + `Authorization: Bearer $AI_SERVICE_TOKEN` (backend .env) |
| 큐 워커 | `careand-queue` (매칭 후보 생성은 비동기 — 후보 조회 전 1~3초 대기) |

```bash
BASE="https://careand.aiclaude.kr/api/v1"
CURL="curl -sk --resolve careand.aiclaude.kr:443:127.0.0.1"

# 토큰 발급 (역할별로 각각)
TOKEN=$($CURL -X POST $BASE/auth/login -H "Content-Type: application/json" \
  -d '{"email":"guardian1@demo.careand.kr","password":"Demo1234!"}' | python3 -c "import json,sys;print(json.load(sys.stdin)['access_token'])")
AUTH="Authorization: Bearer $TOKEN"
```

**사전 헬스체크** — 전부 통과해야 시나리오 시작:

| # | 확인 | 기대 |
|---|---|---|
| 0-1 | `GET /api/v1/health` | 200 `{"status":"ok","checks":{"db":"ok","redis":"ok"}}` |
| 0-2 | `GET http://127.0.0.1:8001/health` | 200, models 필드에 matching=rule-v2, stt=faster-whisper |
| 0-3 | `systemctl is-active careand-queue` | active (후보 생성 비동기 처리) |
| 0-4 | 미인증 `GET $BASE/seniors` (Accept 헤더 없이) | **401 JSON** `{"message":"Unauthenticated."}` — 2026-06-13 수정 회귀 확인 |

---

## 1. 시니어 돌봄 (service_domain=senior)

> 카테고리: 1 방문요양 / 2 동행 / 3 야간케어 / 4 단기보호 / 5 방문목욕

### S-1 정상 플로우: 등록 → 매칭 → 세션 → AI일지 → 결제

| 단계 | 호출 (역할) | 기대 |
|---|---|---|
| 1 | `POST /seniors` (guardian) — 어르신 등록 | 201, id 반환 |
| 2 | `POST /matching/requests` (guardian) ↓페이로드 | 201, status=pending |
| 3 | 2초 대기 → `GET /matching/requests/{id}/candidates` | 후보 ≤5명, score 내림차순, reasons 포함 |
| 4 | `POST /matching/candidates/{candidateId}/accept` (caregiver) | 200, 매칭 confirmed |
| 5 | `POST /care-sessions/{id}/checkin` (caregiver) | 200, status=in_progress |
| 6 | `POST /care-sessions/{id}/activities` (caregiver) | 201 |
| 7 | `POST /care-sessions/{id}/voice-log` (caregiver, audio_url) | 200, stt_text 실전사 (STT 검증 2026-06-13 완료: 25s wav → 3.3s, conf 0.77) |
| 8 | `POST /care-sessions/{id}/checkout` (caregiver) | 200, status=completed |
| 9 | `GET /care-sessions/{id}/ai-summary` (guardian) | 200 — ANTHROPIC_API_KEY 미충전 시 model=*-fallback 인지 확인 |
| 10 | `POST /payments/calculate` → `POST /payments/approve` | 금액 = base_rate × duration/60 (야간케어는 할증) |

매칭요청 페이로드 (2번):
```json
{"service_domain":"senior","senior_id":<S-1.1의 id>,"category_id":1,
 "mode":"normal","scheduled_start":"2026-06-20T10:00:00+09:00","duration_min":120}
```

### S-2 검증 경계

| 케이스 | 기대 |
|---|---|
| `duration_min: 721` | 422 (senior는 60~720) |
| `scheduled_start` 과거 | 422 `after:now` |
| `senior_id` 누락 (domain=senior) | 422 `required_if` |
| `category_id: 6` (간병 카테고리를 senior로) | 도메인-카테고리 교차 검증 — 422 또는 후보 0 (실동작 기록할 것) |
| `mode:"recurring"` + `recurrence_rule` 누락 | 422 |

### S-3 바이탈/이상징후

| 단계 | 호출 | 기대 |
|---|---|---|
| 1 | `POST /seniors/{id}/vitals` — 정상범위 바이탈 N건 | 201 |
| 2 | `POST /seniors/{id}/vitals` — 이상치 (수축기 190 등) | 201 |
| 3 | `POST /seniors/{id}/anomaly-alerts/scan` | 알림 생성 (AI rule-v1) |
| 4 | `GET /seniors/{id}/health-timeseries` | 시계열 반환 |
| 5 | `POST /anomaly-alerts/{id}/acknowledge` → `resolve` | 상태 전이 정상 |

---

## 2. 간병 (service_domain=nursing) — 2026-06-12 신규

> 카테고리: 6 병원간병 (base 15,000). 핵심 차별점: **24시간 상주(1440분) 허용**, 환자(병원) 단위.

### N-1 정상 플로우

| 단계 | 호출 (역할) | 기대 |
|---|---|---|
| 1 | `POST /nursing/patients` (guardian) ↓페이로드 | 201 |
| 2 | `GET /nursing/patients` | 본인 환자만 목록 |
| 3 | `POST /matching/requests` — domain=nursing, `nursing_patient_id`, category_id=6, `duration_min:1440` | 201 — **24시간 상주 허용 확인** |
| 4 | 후보 조회 | 후보의 거리 기준이 **병원 좌표**(hospital_lat/lng) |
| 5 | 수락 → 세션 → 체크아웃 | senior와 동일 플로우 통과 |
| 6 | `PATCH /nursing/patients/{id}` (mobility 변경 등) | 200 |
| 7 | `DELETE /nursing/patients/{id}` (활성 매칭 없는 환자) | 200/204 |

환자 페이로드 (1번):
```json
{"name":"김환자","birth_date":"1948-03-15","gender":"M",
 "hospital_name":"화성중앙병원","hospital_address":"경기 화성시 향남읍 ...",
 "hospital_lat":37.072,"hospital_lng":126.917,"ward_room":"302호",
 "mobility":"bedridden","diseases":["뇌졸중","당뇨"]}
```

### N-2 경계/권한

| 케이스 | 기대 |
|---|---|
| `duration_min: 1441` | 422 (nursing 상한 1440) |
| `birth_date` 미래 | 422 |
| `mobility:"flying"` | 422 enum |
| guardian2 토큰으로 guardian1의 환자 `GET /nursing/patients/{id}` | **403** (NursingPatientPolicy) |
| 활성 매칭 있는 환자 DELETE | 거부(409/422) 또는 정책 확인 — 실동작 기록 |

---

## 3. 가사 (service_domain=housekeeping) — 2026-06-12 신규

> 카테고리: 7 청소(20,000) / 8 수리(40,000) / 9 정리수납(25,000).
> 핵심 차별점: **주소 단위**, 자격증 불필요(caregivers.license nullable), 매칭 가중치 **거리 0.4 우선**, required_skills 하드 필터.

### H-1 정상 플로우

| 단계 | 호출 (역할) | 기대 |
|---|---|---|
| 1 | `POST /housekeeping/addresses` (guardian) ↓페이로드 | 201 |
| 2 | `POST /matching/requests` — domain=housekeeping, `service_address_id`, category_id=7, duration 180 | 201 |
| 3 | 후보 조회 | 후보 정렬이 **근접 우선** 경향 (가중치 0.3/0.4/0.2/0.1) |
| 4 | 수락 → 세션 → 결제 | 통과 |

주소 페이로드 (1번):
```json
{"label":"본가","address":"경기 오산시 ...","lat":37.146,"lng":127.069,
 "dwelling_type":"apartment","size_m2":84,"has_pets":true,"entry_note":"공동현관 #1234"}
```

### H-2 required_skills 하드 필터 (AI rule-v2 핵심 검증)

| 케이스 | 기대 |
|---|---|
| category_id=8(수리) + `requirements.required_skills:["수리"]` | 수리 스킬 보유 인력만 후보 (청소 전문 인력 **하드 제외**) |
| 보유 인력 0명인 스킬 요구 | 후보 0건 + 매칭요청 상태 적절 처리 (빈 후보 응답 확인) |

AI 서비스 직접 검증(백엔드 우회, 이중 방어 각각 확인):
```bash
curl -s -X POST http://127.0.0.1:8001/ai/matching/recommend -H "Authorization: Bearer $AI_TOKEN" \
  -d '{"service_domain":"housekeeping","required_skills":["수리"],"senior":{...},"caregivers":[...]}'
# → 후보 전원 specialties에 "수리" 포함, scoring_method=rule-v2
```

### H-3 경계

| 케이스 | 기대 |
|---|---|
| `duration_min: 721` | 422 (housekeeping은 720 상한 — nursing 1440과 구분 확인) |
| `size_m2: 0` / `3001` | 422 |
| `dwelling_type:"castle"` | 422 enum |
| guardian2가 guardian1 주소 PATCH | 403 (ServiceAddressPolicy) |

---

## 4. 산후 (postpartum) — 별도 라우트 트리 `/api/v1/postpartum/*`

> 공용 매칭이 아닌 전용 `POST /postpartum/match-requests` 사용. 바우처·EPDS·신생아 로그가 차별점.

### P-1 정상 플로우

| 단계 | 호출 | 기대 |
|---|---|---|
| 1 | `POST /postpartum/clients` — 산모 등록 (name/phone/birth_date/address/region_code/delivery_date/delivery_type 필수) | 201 |
| 2 | `POST /postpartum/clients/{id}/newborns` — 신생아 (birth_weight_g 500~7000) | 201 |
| 3 | `GET /postpartum/clients/{id}/voucher/inquire` | 바우처 잔액 (SBA 연동 — SBA_API_KEY 미설정 시 폴백/오류 응답 형태 기록) |
| 4 | `POST /postpartum/match-requests` | 201, AI 산후매칭(rule-v1) 후보 |
| 5 | `POST /postpartum/voucher-transactions/use` | 잔액 차감 |

### P-2 EPDS (산후우울 선별) — 위험 등급 분기

| 케이스 (10문항 합계) | 기대 |
|---|---|
| 총점 0~9 (저위험) | 평가 저장, risk=low |
| 총점 10~12 (중위험) | risk=moderate, 재평가 권고 |
| 총점 ≥13 또는 10번 문항(자해) ≥1 | **risk=high + 알림/개입 트리거** 확인 |
| `GET /postpartum/epds/clients/{id}/history` | 이력 누적 |

### P-3 신생아 일일로그 + 이상징후

| 단계 | 호출 | 기대 |
|---|---|---|
| 1 | `POST /newborns/{id}/daily-logs` — feeding (volume 0~300ml) | 201 |
| 2 | `POST .../daily-logs` — temperature 38.5℃ | 201 + 이상징후 알림 생성 여부 |
| 3 | `GET /newborns/{id}/anomaly-alerts` → `POST .../{alert}/resolve` | 상태 전이 |
| 4 | `feeding_volume_ml: 301` | 422 |
| 5 | `log_type:"play"` | 422 enum |

### P-4 산후 챗봇

`POST /postpartum/chatbot/sessions` → `POST .../{id}/messages` — 키 미충전 시 model=kb-fallback 응답이라도 200 유지.

---

## 5. AI 서비스 단독 (:8001) — 백엔드 우회 직접 검증

| # | 엔드포인트 | 시나리오 | 기대 |
|---|---|---|---|
| A-1 | `/health` | 모델 상태 | matching=rule-v2, stt 라벨, llm/rag는 키 충전 전 fallback |
| A-2 | `/ai/matching/recommend` | 도메인별 가중치 | 동일 입력에서 domain=housekeeping이 근거리 인력을 senior보다 상위 랭크 |
| A-3 | `/ai/voice/transcribe` | wav/mp3 전사 | ✅ 2026-06-13 검증완료 (한국어 정확, 깨진 파일 5xx — 가짜텍스트 금지) |
| A-4 | `/ai/voice/transcribe` | 없는 파일 | 422 |
| A-5 | 인증 없는 호출 | 전 엔드포인트 | 401 |
| A-6 | 챗봇/일지요약 | 키 미설정 | 200 + model=*-fallback (5xx 아님) |
| A-7 | 수요예측 | 지점별 | seasonal-naive-v1, DB 이력 기반 숫자 |

---

## 6. 관리자 시나리오 (admin 토큰)

| # | 시나리오 | 기대 |
|---|---|---|
| M-1 | `GET /admin/dashboard/kpi` | **도메인별(간병·가사 포함) 분리 집계** (06-12 추가분) |
| M-2 | 인력 승인: `POST /admin/caregivers/{id}/approve` / `reject` | 상태 전이 + 당사자 알림 |
| M-3 | 매칭 수동 배정: `POST /admin/matching/requests/{id}/manual-assign` | AI 후보 무시하고 배정 |
| M-4 | 일지 승인/반려: `/admin/care-logs/{id}/approve\|reject` | 반려 시 보호자에게 미노출 |
| M-5 | 정산: `POST /admin/settlements/run` → `{id}/confirm` → `file-tax` | 주간 정산 금액 = Σ(세션 결제) − 수수료. 홈택스 연동은 2027 이월 — 스텁 응답 기록 |
| M-6 | guardian 토큰으로 `/admin/*` 접근 | **403** |
| M-7 | AI 모델 거버넌스: `/admin/ai-models/{id}/promote` → `rollback` | 버전 전이 이력 |

---

## 7. 공통 보안/안정성

| # | 시나리오 | 기대 |
|---|---|---|
| C-1 | Accept 헤더 없는 미인증 요청 (모든 도메인 라우트 1개씩) | 401 JSON — `Route [login]` 500 재발 금지 (06-13 수정) |
| C-2 | 만료 토큰 → `POST /auth/refresh` | 새 토큰, 구 토큰 무효 |
| C-3 | 로그인 5회 연속 실패 | throttle 429 (Redis 의존 — redis 다운 시 500 났던 이력 있음) |
| C-4 | 타 역할 리소스 접근 (caregiver→guardian 데이터 등) | 403 |
| C-5 | 존재하지 않는 id (각 도메인 show) | 404 JSON |
| C-6 | `POST /webhooks/pg` 위조 서명 | 거부 |
| C-7 | 남의 candidate 수락 (caregiver A가 B의 candidate accept) | **차단** — 404(accept 컨트롤러가 인증 caregiver 소유로 스코프 조회 → 타인 후보는 존재 은닉, 403보다 보수적). 2026-06-14 실측 |

---

## 8. 회귀 체크리스트 (배포마다 최소 실행)

```
□ 0-1~0-4 헬스 + 401 JSON
□ S-1 시니어 매칭→세션→일지 1바퀴
□ N-1.3 간병 1440분 허용 / H-3 가사 721분 거부 (도메인별 duration 분기)
□ H-2 required_skills 하드 필터
□ P-2 EPDS 고위험 분기
□ A-3 STT wav 1건
□ M-1 대시보드 도메인별 집계
```

> 실행 결과는 이 파일 하단에 날짜별로 기록 권장.
> 데이터 정리: 테스트 생성물은 demo 계정 소유라 DemoSeeder 재실행으로 초기화 가능
> (`php artisan db:seed --class=DemoSeeder` — 단, 운영 실데이터와 섞이지 않게 주의).

## 주의 — 후보 0건의 흔한 원인 (2026-06-13 실측)

- **거리 감쇠 10km 컷**: AI distance_score = max(0, 1−km/10). 테스트 주소는 인력 풀 좌표 부근으로.
  ✅2026-06-13 시드 좌표 정리: 데모 인력 25명·시니어 15명 전원 화성·오산 6권역(병점/향남/동탄/오산중앙/세교/봉담)
  재배치 + DemoSeeder AREAS 동일 갱신. 화성·오산 주소로 매칭 검증 완료. 권역 간(예: 오산↔봉담 ~20km)은 여전히 컷.
- **콜드스타트**: 평점 0·경력 0 신규 인력은 스킬이 맞아도 0.4를 넘기 어려움 (가사 가중치 기준 최대 0.15+0.4d).
- 산모 등록은 정책상 운영측(admin) 전용 — guardian 토큰은 403.
- EPDS는 1산모 1일 1평가 (재응시 422 EPDS_ALREADY_TODAY).

## 실행 기록

- 2026-06-13: 0-1~0-4, A-3(STT wav/mp3), C-1 검증 통과 (이 문서 작성 시점).
- 2026-06-13: **회귀 체크리스트 7항목 전체 PASS** (S-1 풀루프 senior#17/req#41/session#25, N 1440↔1441, H 721,
  H-2 스킬필터 cat7→cg22·24/cat8→cg23, P-2 low/medium/high/critical 4분기, M-1 by_domain).
  도중 발견·수정 4건(전부 배포): ① 헤어핀 NAT로 AI서비스가 도메인 URL 페치 불가 → /etc/hosts 127.0.0.1 매핑
  (음성일지 STT 파이프라인 운영 불능이었음) ② User::hasAnyRole 부재 → 산후 정책 전 라우트 500 (레거시 역할 shim 추가)
  ③ postpartum_clients.user_id FK 500 → 등록자 기본값 ④ EPDS 당일 중복 500 → 422 가드.
  테스트 오디오: /var/www/careand-backend/public/regression-test-audio.wav (재사용 가능).
- 2026-06-14: **운영검증 6단계 전체 PASS** — 서비스헬스(systemd 8유닛 active·api/v1/health ok·FastAPI rule-v2) → member-web(BUILD_ID 정상·(member)10라우트·빌드 API base=public, localhost 미참조) → 로그인(JWT login/me/logout 200, 위조토큰·오답 401) → S-1 매칭 풀루프(req#49→AI후보5 rule-v2→candidate#48 수락→match#30→session#26 checkin/checkout=completed) → 권한경계(미인증401 JSON·guardian→admin 403·역할간/소유권 senior 403·없는id 404 JSON) → 결제/정산 권한(결제=guardian+매칭소유자 이중인가, 정산조회=caregiver 본인스코프, 정산실행=admin 전용 role 게이트, 비인가 전부 403). mutating 최소화(calculate 읽기전용·비인가 settlements/run 선차단). 생성 데모데이터: req#49/match#30/session#26.
