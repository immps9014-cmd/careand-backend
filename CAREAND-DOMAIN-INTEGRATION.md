# Care& 멀티도메인 통합 설계서

> 작성 2026-06-29 · 대상: careand-backend / careand-member-web / careand-admin-web / careand-ai-service
> 운영 런북: `/root/CAREAND-OPS.md` · 도메인: caren.aiclaude.kr
> 상태: **설계 확정 대기 → 구현 전 단계** (Phase별 `is_active` 토글로 무중단 롤아웃)

## 0. 목표 (요청 요약)
1. 도메인별 서비스를 **하나의 앱**에서 처리하도록 통합.
2. **가사관리(housekeeping) → 생활지원서비스(`living_support`)** 명칭 변경. 생활지원에 **동행·정리수납** 포함.
3. 신규 도메인 추가: **아이돌봄(`childcare`) · 산모산후관리(`postpartum`) · 마음돌봄(`mental_care`)**.

### 확정된 의사결정
- **동행(COMPANION)**: senior → living_support 로 **이동** (한 곳에서만 제공).
- **생활지원 토큰**: `housekeeping` → **`living_support` 로 리네임** (append + 데이터 이전).
- **산모산후관리**: 기존 `postpartum` enum/`postpartum_clients` 테이블 **재사용**(이미 배선됨, 오픈만).
- **착수 방식**: 본 설계서 합의 후 Phase 0부터 구현.

---

## 1. 현행 구조 진단 (코드 기준)

이미 **단일 스키마 + `service_domain` 디스크리미네이터** 구조이며, 도메인별 확장 패턴이 부분적으로 존재한다.

- **enum/SET 컬럼 4곳** (도메인 토큰 저장):
  - `match_requests.service_domain` ENUM(`senior,postpartum,nursing,housekeeping`)
  - `caregivers.service_domains` SET(동일 4값)
  - `mock_interviews.target_domain` ENUM(동일 4값)
  - `self_introduction_interviews.extracted_target_domain` ENUM(+`both`)
  - ※ `service_categories.domain` 은 varchar(20) → 자유 확장(ALTER 불필요)
- **대상 엔티티(subject) 패턴** — `match_requests`에 도메인별 nullable FK + 가변 `requirements` JSON:
  - `senior_id` → `seniors`
  - `nursing_patient_id` → `nursing_patients`
  - `service_address_id` → `service_addresses` (생활지원의 "우리집")
  - `postpartum_client_id` → `postpartum_clients` *(이미 존재, 미오픈)*
  - `requirements` JSON → 도메인별 가변 필드(교대형태/평수/사진요구 등)
- **현행 카테고리**(`service_categories`, 전부 is_active=1):
  - senior: VISIT_CARE(방문요양) / COMPANION(동행 16,000) / NIGHT_CARE(야간케어) / SHORT_STAY(단기보호) / BATH(방문목욕)
  - nursing: NURSING_HOSPITAL(병원간병)
  - housekeeping: HK_CLEANING(청소) / HK_REPAIR(수리) / HK_ORGANIZING(정리수납)
- **가격**: `pricing_rules.category_id` 기반 → **도메인 무관**. 신규 카테고리 시드만 추가하면 동작.

### 핵심 문제 — 단일 진실원천(SSOT) 부재
도메인 정의가 분산되어, 도메인 추가 시 여러 곳을 동시 수정해야 한다.
- FE `member-web/app/(member)/request/new/page.tsx`: `type Domain`, `DOMAINS[]`, `domain==="nursing"|"housekeeping"` 분기 30+곳(엔티티 바인딩·최대시간·페이로드·동적 필드).
- BE `CaregiverController`의 인라인 `$domLabel` / 카테고리 라벨 맵.
- 위 enum 4곳 + 시드.

→ **통합의 본질 = 도메인 메타데이터를 한 곳(레지스트리)에 모으고 BE/FE가 그것을 소비하게 만드는 것.**

---

## 2. 목표 도메인 택소노미 (최종 6종)

| token | 라벨 | 대상(subject) FK / 테이블 | 카테고리(코드) | 발주 역할 | 비고 |
|---|---|---|---|---|---|
| `senior` | 시니어 돌봄 | `senior_id` / seniors | VISIT_CARE·NIGHT_CARE·SHORT_STAY·BATH | guardian·self | COMPANION 제거됨 |
| `living_support` | **생활지원서비스** | `service_address_id` / service_addresses | HK_CLEANING·HK_REPAIR·HK_ORGANIZING·**LS_COMPANION** | self | ←housekeeping 리네임 |
| `nursing` | 간병 | `nursing_patient_id` / nursing_patients | NURSING_HOSPITAL | org 전용 | 기존 |
| `postpartum` | **산모·산후관리** | `postpartum_client_id` / postpartum_clients | PP_CARE(신규) | self | 배선됨→오픈 |
| `childcare` | **아이돌봄** | `childcare_child_id` / **children(신규)** | CC_PICKUP·CC_PLAY·CC_INFANT(신규) | guardian | enum append |
| `mental_care` | **마음돌봄** | `mental_care_client_id` / **mental_care_clients(신규)** | MC_SUPPORT·MC_COMPANION(신규) | self·family | enum append |

> **동행 이동**: 기존 `COMPANION`(senior) 행을 폐기하지 않고 `LS_COMPANION`(living_support, 16,000) 으로 **재등록**하고 senior의 COMPANION 은 `is_active=0`. (요청 이력 0건이라 데이터 영향 없음. 과거 행 무결성 보존을 위해 row 삭제 대신 비활성.)

---

## 3. 통합 핵심 — 도메인 레지스트리 (SSOT)

### 3.1 정의 위치
1차안 **`config/service_domains.php`** (PHP 배열, 배포 시 고정) — 단순·캐시 친화.
대안 DB 테이블 `service_domains` — 무중단 토글이 필요하면. → **`is_active`만 DB(`service_categories`)로 토글**하고 구조 메타는 config 로 두는 하이브리드를 권장.

### 3.2 스키마(예시)
```php
// config/service_domains.php
return [
  'living_support' => [
    'label'       => '생활지원서비스',
    'subject'     => ['table'=>'service_addresses','fk'=>'service_address_id','picker'=>'address'],
    'visibility'  => ['self'],                 // 노출 역할(guardian/self/org/family)
    'categories'  => ['HK_CLEANING','HK_REPAIR','HK_ORGANIZING','LS_COMPANION'],
    'request'     => ['duration_max'=>720, 'requirements'=>['area_pyeong','photo_required']],
    'matching'    => ['weights'=>['distance'=>0.5,'skill'=>0.3,'rating'=>0.2]],
  ],
  'childcare' => [
    'label'=>'아이돌봄',
    'subject'=>['table'=>'children','fk'=>'childcare_child_id','picker'=>'child'],
    'visibility'=>['guardian'],
    'categories'=>['CC_PICKUP','CC_PLAY','CC_INFANT'],
    'request'=>['duration_max'=>720,'requirements'=>['child_age','pickup_location']],
    'matching'=>['weights'=>['distance'=>0.4,'skill'=>0.4,'rating'=>0.2]],
  ],
  // senior / nursing / postpartum / mental_care ...
];
```

### 3.3 신규 API
`GET /api/v1/service-domains` →
```json
[{ "token":"living_support","label":"생활지원서비스","picker":"address",
   "visibility":["self"],
   "categories":[{"code":"LS_COMPANION","name":"동행","base_rate":16000}, ...],
   "request":{"duration_max":720,"requirements":["area_pyeong","photo_required"]} }]
```
- `is_active=1` 카테고리만, 호출 역할(role)의 `visibility` 통과 도메인만 반환.
- FE는 이 응답으로 **도메인 카드·대상 선택기·동적 필드**를 렌더 → 신규 도메인 = config 1항목 + 시드.

### 3.4 리팩터 범위
- **FE** `request/new/page.tsx`: `domain==="X"` 분기 전면 제거 → `{picker, requirements[]}` 메타데이터 구동. picker 컴포넌트 3종으로 일반화: `SeniorPicker`/`AddressPicker`/`PatientPicker` + 신규 `ChildPicker`/`PostpartumPicker`/`MentalClientPicker`. 공통 `<DomainRequestForm domain={meta}/>`.
- **BE** `CaregiverController`·기타: 인라인 `$domLabel`/카테고리 맵 → `ServiceDomains::label($token)` 등 레지스트리 헬퍼로 일원화.

---

## 4. 데이터 모델 변경 (append-only · ALGORITHM=INSTANT · 기존 안전패턴)

> 규칙: enum/SET은 **말미 append만**(정수 인코딩 보존). 신규 카테고리는 `is_active=0` 잠복 투입 후 토글 오픈.

### 4.1 enum/SET 확장 (4곳 동일)
```sql
-- childcare, mental_care, living_support 추가 (postpartum은 이미 존재)
ALTER TABLE match_requests
  MODIFY service_domain ENUM('senior','postpartum','nursing','housekeeping',
    'living_support','childcare','mental_care') NOT NULL DEFAULT 'senior', ALGORITHM=INSTANT;
ALTER TABLE caregivers
  MODIFY service_domains SET('senior','postpartum','nursing','housekeeping',
    'living_support','childcare','mental_care') NOT NULL DEFAULT 'senior', ALGORITHM=INSTANT;
-- mock_interviews.target_domain / self_introduction_interviews.extracted_target_domain 동일 append
```

### 4.2 housekeeping → living_support 데이터 이전
```sql
UPDATE match_requests  SET service_domain='living_support' WHERE service_domain='housekeeping'; -- 현재 0건
UPDATE service_categories SET domain='living_support' WHERE domain='housekeeping';               -- 3건
-- caregivers.service_domains(SET) 내 'housekeeping' 토큰 치환 (6건)
UPDATE caregivers SET service_domains =
  TRIM(BOTH ',' FROM REPLACE(CONCAT(',',service_domains,','), ',housekeeping,', ',living_support,'))
  WHERE FIND_IN_SET('housekeeping', service_domains);
```
- 이전 완료 후 `housekeeping` 토큰은 **미사용**으로 잔존(enum/SET에서 즉시 제거는 리빌드 필요 → 다음 정리 마이그레이션에서 축소). 신규 코드/레지스트리는 `housekeeping`을 노출하지 않음.

### 4.3 신규 대상 엔티티 + FK (기존 패턴 계승)
```sql
CREATE TABLE children (...);            -- 보호자 child 프로필(이름·생년월·특이사항)
CREATE TABLE mental_care_clients (...); -- 마음돌봄 대상(본인/가족 관계·상태)
ALTER TABLE match_requests
  ADD childcare_child_id     BIGINT UNSIGNED NULL AFTER service_address_id,
  ADD mental_care_client_id  BIGINT UNSIGNED NULL AFTER childcare_child_id,
  ADD CONSTRAINT fk_mr_child  FOREIGN KEY(childcare_child_id)    REFERENCES children(id),
  ADD CONSTRAINT fk_mr_mental FOREIGN KEY(mental_care_client_id) REFERENCES mental_care_clients(id);
```

### 4.4 카테고리 + 가격 시드 (is_active=0 잠복)
| domain | code | name | base_rate | 비고 |
|---|---|---|---|---|
| living_support | LS_COMPANION | 동행 | 16000 | senior COMPANION 이전 |
| postpartum | PP_CARE | 산후관리 | (정책) | |
| childcare | CC_PICKUP / CC_PLAY / CC_INFANT | 등하원동행/놀이돌봄/영아돌봄 | (정책) | |
| mental_care | MC_SUPPORT / MC_COMPANION | 정서지원/심리상담동행 | (정책) | |
- 각 신규 category마다 `pricing_rules`(region_index·night/holiday/emergency mult·min_hourly) 시드.
- senior `COMPANION` → `is_active=0`.

---

## 5. 횡단 영향
- **가격/정산**: category 기반 → 시드만 추가, 로직 변경 없음.
- **AI 매칭(rule-v1, careand-ai-service)**: 도메인 가중치를 레지스트리 `matching`으로 외부화 → AI 서비스가 `/service-domains` 또는 동기화된 config 참조.
- **교육(mock_interviews/self_intro)**: enum append만(4.1).
- **알림·일지·관리자웹**: 라벨을 레지스트리 헬퍼로 통일 → 자동 정합.
- **admin-web**: 도메인/카테고리 토글(is_active) 관리 UI에 신규 도메인 자동 노출(레지스트리 구동).

---

## 6. 롤아웃 (Phase별 · `is_active` 토글 = 즉시 롤백)

| Phase | 내용 | 완료 기준 |
|---|---|---|
| **0** | 레지스트리 + `/service-domains` API + FE/BE 리팩터 | 기존 3도메인(senior/nursing/생활지원) **동작 동일성 회귀** 통과, 신규는 잠복 |
| **1** | `living_support` 전환(명칭·동행 이전 4.2/4.4) | 생활지원 카드·동행·정리수납 노출, 구 가사 동작 유지 |
| **2** | `postpartum`(산모·산후관리) 오픈 | PP_CARE is_active=1, 산모 picker |
| **3** | `childcare` 오픈 (+children 엔티티) | child picker·CC_* |
| **4** | `mental_care` 오픈 (+mental_care_clients) | MC_* |

각 단계 `careand-deploy backend|admin|member` + 카테고리 `is_active` 토글. 롤백 = 토글 off / `git reset` + 재배포.

---

## 6.5 구현 현황

### ✅ Phase 0 — 완료 (2026-06-29)
SSOT 레지스트리 + API + FE/BE 리팩터. 기존 3도메인(senior/nursing/housekeeping) **동작 동일성 회귀 통과**, 신규 4도메인 잠복(is_active=false).
- BE `config/service_domains.php` — 도메인 레지스트리(6종, order/label/icon/picker/hidden_for_roles/is_active).
- BE `app/Support/ServiceDomains.php` — 헬퍼(all/label/specialtyLabel/activeForRole). `CaregiverController::specLabel`의 인라인 $domLabel/맵 제거→헬퍼 일원화.
- BE `GET /v1/matching/service-domains` (`MatchRequestController@serviceDomains`) — 역할별 활성 도메인+활성 카테고리. 검증: guardian=senior+housekeeping(nursing 숨김), caregiver=3종 전체.
- FE `lib/serviceDomains.ts` — `useServiceDomains()` 훅 + 아이콘맵 + 정적 폴백. `request/new/page.tsx`의 하드코딩 `DOMAINS`/`type Domain`/가시성필터 → 레지스트리 구동.
- 배포: careand-deploy backend(스냅샷 8202ff2)·member(22951b9), config/route 캐시 복원.
- ⚠️ 도메인별 **폼 본문**(picker·nursing 일수·housekeeping 사진 등)은 아직 `domain==="X"` 분기 유지 — 빅뱅 리라이트 위험 회피. 각 신규 도메인 picker는 해당 Phase에서 메타데이터 구동으로 일반화.

### ✅ Phase 1 — 완료 (2026-06-29)
가사(housekeeping) → 생활지원서비스(living_support) 토큰 전환 + 동행 편입. 라이브 검증 통과.
- 마이그레이션 `2026_06_29_000001_rename_housekeeping_to_living_support.php`: enum/SET 4곳 `living_support` append(INSTANT) → 데이터 이전(match_requests·service_categories·caregivers SET) → 동행 편입(senior COMPANION is_active=0, LS_COMPANION 신설 16,000, pricing_rules 18행 복제). DB 백업: `/root/careand-phase1-dbbak-20260629-225102.sql`.
- 레지스트리 토글: housekeeping `is_active=false`, living_support `is_active=true`.
- BE 코드 토큰 전환(service_domain만, intent='housekeeping'·라우트 `/v1/housekeeping/addresses`·courses.category는 유지): MatchRequest 모델 match 분기, GenerateMatchCandidatesJob, MatchRequestController, StoreMatchRequestRequest, RegisterCaregiverRequest, DashboardController, OperationsController(updateCaregiver allowed).
- FE 토큰 전환: member(serviceDomains 폴백·request/new·caregiverType·home 퀵메뉴/라벨·caregivers/browse·logs·signup 리다이렉트), admin(matching·reports·dashboard·caregiver-approval·contracts·care-logs·members CG옵션·caregiverType). admin 가사요청자(intent) 통계는 보존.
- 배포: backend(c2f49f1)·member·admin. 검증: guardian=요양보호+생활지원서비스(가사청소·수리·정리수납·동행), senior 동행 제거, LS_COMPANION 가격견적 정상(16,000).
- ⚠️ `housekeeping` enum 값/라우트 경로/`intent`/`courses.category`/PHP `app/Domains/Housekeeping` 네임스페이스는 의도적으로 유지(잔존, 무해).

### ✅ Phase 2 — 완료 (2026-06-29)
산모·산후관리(postpartum)를 **통합 generic 흐름에 편입**. 라이브 e2e 통과(산모 등록→산후 요청 201).
- **진단**: postpartum은 단순 토글이 아니었음 — 전용 `app/Domains/Postpartum/`(바우처·신생아·EPDS) + `PostpartumMatchingController`가 **현 스키마와 불일치(service_category_id/mode='visit'/status='recruiting')인 미완 스캐폴드**, member FE 전무. subject는 `guardian_id`가 아닌 `user_id` 소유, 좌표 컬럼 없음.
- **방침(사용자 선택)**: generic match_requests 흐름에 편입(다른 도메인과 동일 UX). 바우처/신생아/EPDS 고급 서브시스템은 후속(미사용 스캐폴드 그대로 둠).
- 마이그레이션 `2026_06_29_000002_seed_postpartum_categories.php`: PP_CARE(산후관리,15,000)·PP_NIGHT(산후 야간케어,18,000) is_active=1 + pricing_rules 복제(COMPANION 템플릿). **요율 잠정**.
- BE generic 편입: MatchRequest(postpartumClient 관계+recipient arm+**$fillable에 postpartum_client_id 추가**←초기 누락 버그 수정), GenerateMatchCandidatesJob·MatchRequestController(store nulling·recipientColumn·resolveTargetName·rawAddr) postpartum arm, StoreMatchRequestRequest(in+required_if+**user_id 소유권**), pricingEstimate in. 좌표 없음 → 거리랭킹은 모델 null-가드로 graceful degrade.
- **신규 소비자용 산모 엔드포인트**(staff 서브시스템과 분리, user_id 스코프): `GET/POST /v1/matching/postpartum-clients`(MatchRequestController). 타인 산모 노출 방지.
- 레지스트리: postpartum `is_active=true`(picker='postpartum').
- FE(member): memberApi.postpartumClients/createPostpartumClient + 타입, `request/new` 산모 picker·payload·검증, 신규 `app/(member)/postpartum-clients/new` 산모 간이등록 폼(이름·연락처·생년월일·주소·시도·출산일·출산유형·초산여부).
- 배포: backend(0d49450+fillable fix)·member(68a6e81). 검증: guardian /service-domains에 산모·산후관리(산후관리·산후 야간케어), 산모 등록→요청 201·postpartum_client_id 영속, PP_CARE 견적 15,000. 테스트 데이터 정리 완료.
- ⚠️ 고급 산후 기능(바우처 자격·신생아 로그·EPDS·전용 매칭)은 **미연동** — 별도 후속 과제. admin은 기존 postpartum 라벨/색상 맵 보유(추가 작업 불요).

### ✅ Phase 3 — 완료 (2026-06-29)
아이돌봄(childcare)을 generic 흐름에 편입. 라이브 e2e 통과(아이 등록→돌봄 요청 201, 주소 자동 지오코딩).
- 마이그레이션 `2026_06_29_000003_create_children_and_open_childcare.php`: **children 테이블**(보호자 소유, seniors 패턴+home_lat/lng) + enum/SET append `childcare` + `match_requests.childcare_child_id` FK + 카테고리 CC_PICKUP(등하원동행)·CC_PLAY(놀이돌봄)·CC_INFANT(영아돌봄) is_active=1 + pricing 복제. **요율 잠정**.
- children이 좌표를 보유 → MatchRequest **default arm 재사용**(recipient()에 childcare arm만 추가, features/location/name은 home_* 컬럼으로 자동). postpartum과 달리 거리 매칭 완전 동작.
- BE: `App\Models\Child`(SoftDeletes), MatchRequest(관계·recipient arm·**fillable에 childcare_child_id**), Job·Controller arms, StoreMatchRequestRequest(in+required_if+guardian_id 소유권). 소비자 엔드포인트 `GET/POST /v1/matching/children`(보호자 스코프, **주소→좌표 GeocodingService 자동보정**). 레지스트리 toggle(icon='backpack').
- FE(member): memberApi.children/createChild+타입, serviceDomains ICONS에 backpack, `request/new` 아이 picker·payload·검증, 신규 `app/(member)/children/new`. admin: childcare 라벨/색상 맵 + caregiverType + CG_DOMAIN_OPTIONS.
- 배포: backend(cc2438a)·member(c434d74)·admin(8baf3c1). 검증: 4도메인 활성(요양보호|생활지원서비스|산모·산후관리|아이돌봄), 아이 등록(강남/마포 지오코딩)→요청 201. 테스트 데이터 정리.

### ✅ Phase 4 — 완료 (2026-06-30)
마음돌봄(mental_care)을 generic 흐름에 편입. 라이브 e2e 통과(대상 등록→요청 201, 지오코딩). **멀티도메인 통합 프로젝트 완료**.
- 마이그레이션 `2026_06_29_000004_create_mental_care_clients_and_open.php`: **mental_care_clients 테이블**(보호자 소유, children 패턴+home_lat/lng+relation) + enum/SET append `mental_care` + `match_requests.mental_care_client_id` FK + 카테고리 MC_SUPPORT(정서지원)·MC_COMPANION(심리상담 동행) + pricing 복제. **요율 잠정**.
- 자격: 상담 도메인은 무자격 등록 → **관리자 수동 승인**(기존 caregiver register 흐름 그대로, 추가 코드 없음).
- BE: `App\Models\MentalCareClient`, MatchRequest(관계·recipient arm·fillable), Job·Controller arms, StoreMatchRequestRequest(in+required_if+guardian_id 소유권), 소비자 엔드포인트 `GET/POST /v1/matching/mental-care-clients`(지오코딩), 레지스트리 toggle(icon='heart-handshake').
- FE(member): memberApi+타입, `request/new` 대상 picker, 신규 `app/(member)/mental-care-clients/new`. admin: mental_care 맵+caregiverType+CG옵션.
- 배포: backend·member·admin. 검증: **5 도메인 활성**(요양보호|생활지원서비스|산모·산후관리|아이돌봄|마음돌봄), 대상 등록→요청 201. 테스트 데이터 정리.

---

## 9. 최종 상태 (2026-06-30)

**소비자 노출 도메인 5종**(보호자 기준): 요양보호 / 생활지원서비스 / 산모·산후관리 / 아이돌봄 / 마음돌봄. (간병=nursing은 기관 발주 전용, 보호자 숨김 — 총 6 도메인 레지스트리)

**아키텍처**: 도메인 메타데이터 SSOT(`config/service_domains.php` + `GET /v1/matching/service-domains`)가 BE/FE를 구동. 신규 도메인 = 레지스트리 1항목 + 카테고리 시드 + (필요시 subject 테이블/FK/picker). 모든 도메인이 단일 `match_requests` + generic 매칭/가격/정산 공유. subject별 nullable FK + `requirements` JSON + `recipient()` match 분기.

**요율 확정 완료 (2026-06-30, 마이그레이션 `2026_06_30_000001_finalize_domain_rates.php`)**:
| code | 카테고리 | 기준 시급 |
|---|---|---|
| LS_COMPANION | 동행 | 14,000 |
| HK_CLEANING | 가사 청소(기존) | 20,000 → 18,000 |
| HK_ORGANIZING | 정리수납(기존) | 25,000 → 22,000 |
| HK_REPAIR | 가사 수리(기존) | 40,000 → 35,000 |
| PP_CARE | 산후관리 | 13,000 |
| PP_NIGHT | 산후 야간케어 | 16,000 |
| CC_PICKUP | 등하원 동행 | 12,000 |
| CC_PLAY | 놀이돌봄 | 13,000 |
| CC_INFANT | 영아돌봄 | 15,000 |
| MC_SUPPORT | 정서지원 | 15,000 |
| MC_COMPANION | 심리상담 동행 | 16,000 |
(앵커: 방문요양 18,000·병원간병 15,000·가사청소 20,000·야간케어 22,000·방문목욕 25,000. base_rate는 region_index·야간/휴일/긴급 배수와 결합, min_hourly 10,030 하한)

**남은 과제(별도)**:
- postpartum 고급 서브시스템(바우처·신생아·EPDS) generic 흐름과 미연동.
- 상담(mental_care) 전문 자격 검증 고도화(현재 관리자 수동 승인).

## 7. 회귀·검증 체크리스트
- [ ] 기존 senior/간병/(구)가사 요청 생성·매칭·정산 무변동 (Phase 0)
- [ ] `caregivers.service_domains` SET 이전 후 매칭 후보 정상
- [ ] `/service-domains` 가 role별 visibility 정확히 필터(예: nursing은 guardian 비노출)
- [ ] 신규 도메인 잠복 시 FE 카드 미노출, 토글 후 노출
- [ ] enum append가 `ALGORITHM=INSTANT`로 즉시 완료(잠금 없음)
- [ ] 가격 견적 API가 신규 category에 대해 pricing_rule 적용
- [ ] mock_interview/self_intro 교육 플로우 신규 도메인 선택 가능

## 8. 미해결/정책 입력 필요
- 신규 카테고리 `base_rate`·pricing_rule 수치(야간/휴일/긴급 배수, 최저시급) 확정.
- `children`/`mental_care_clients` 필드 상세(개인정보 범위·보호자 관계·민감정보 라벨링 정책 — Gmail 민감 라벨 패턴 참고).
- 마음돌봄 자격/면허 검증 정책(상담 도메인 특수성).
- `housekeeping` enum 토큰 최종 제거 시점(별도 정리 마이그레이션).
