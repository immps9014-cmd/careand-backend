---
name: domain-target-check
description: 케어앤 신규 서비스 도메인 추가 또는 대리형/본인형 분기 변경 시 수정해야 할 모든 지점을 점검한다. "새 도메인 추가", "도메인 활성화", "대리형/본인형", "guardians.intent" 관련 작업 시 사용한다.
---

케어앤은 도메인 메타데이터가 **backend SSOT 1곳 + 별도 동기화가 필요한 3곳**으로 나뉘어 있다.
하나만 고치면 화면·매칭·온톨로지 중 일부가 어긋난다. 신규 도메인 추가/변경 시 아래를 전부 확인한다.

## 1. Backend SSOT: `config/service_domains.php` + `app/Support/ServiceDomains.php`

이게 진짜 단일 진실원천이다. 신규 도메인은 여기 배열에 키 하나 추가:

```php
'new_domain' => [
    'order' => 70, 'label' => '표시명', 'desc' => '짧은 설명', 'icon' => 'lucide-아이콘명',
    'is_active' => true,
    'hidden_for_roles' => [],              // 예: nursing처럼 기관 전용이면 ['guardian']
    'picker' => ['type' => '...', 'fk' => '...'],
    'relations' => [
        'hasSubject' => ['table' => '...', 'fk' => '...'],
        'requestedBy' => ['guardian'],     // 'guardian'(대리)|'self'|'family'|'organization' — 문서화용, 강제 로직 아님
    ],
    'domain_label' => '전체 표시용 라벨',
    'qualification' => [
        'license_required' => true|false,
        'license_label' => '...',
        'verify' => 'auto'|'manual'|'none',
        'accepted_types' => ['...'],
    ],
],
```

- [ ] `is_active: true`로만 끝내지 말 것 — **`service_categories` 테이블에 해당 domain 코드로 활성
  카테고리(`is_active=1`)가 최소 1개 있어야** `ServiceDomains::activeForRole()`이 노출한다. 카테고리
  없이 도메인만 추가하면 `/api/v1/matching/service-domains`에서 조용히 잠복(미노출)됨 — 흔한 실수.
- [ ] `hidden_for_roles`로 발주 주체 제한이 실제 접근 제어(`guardians.intent`와 함께)를 담당한다는 점
  주의 — `relations.requestedBy`는 문서화용일 뿐 별도 강제 로직이 없다.

## 2. 대리형 vs 본인형 — 온톨로지에도 반영 (별도 SSOT, 자동 동기화 안 됨)

`config/service_domains.php`에 명시적인 "대리형/본인형" 필드는 없고, `requestedBy`(guardian=대리,
self=본인)로 문서화만 되어 있다. **실제 온톨로지 상의 formal 정의는 `careand-ai-service/ontology/care-domain.ttl`의
`care:hasRequestMode`(`care:ProxyRequest` | `care:SelfRequest`)** — 이 둘은 서로 다른 파일이라 신규
도메인 추가 시 **둘 다** 갱신해야 한다.

- [ ] `care-domain.ttl`에 `care:ServiceDomain` 개체 추가 + `care:hasRequestMode`로 Proxy/Self 지정 +
  `care:code "new_domain"`으로 DB 코드와 연결
- [ ] `careand-ai-service/ontology/load.sh --schema-only` 로 재적재
  (2026-09-20 r2.0 부터 `caren` 데이터셋은 named graph 두 개다 — 수동 POST 로 밀어넣으면
  그래프가 어긋난다. 업무객체까지 다시 뽑으려면 인자 없이 `load.sh`)
- [ ] `check.py` 의 **'어휘 미등록 서비스 도메인 = 0'** 확인 — `caregivers.service_domains`,
  `match_requests.service_domain`, `service_categories.domain` 세 곳의 실제 값을 전부 본다.
  도메인을 DB 에만 넣고 TTL 에 안 넣으면 여기서 FAIL 난다
- [ ] `careand-deploy ai`(또는 `ontology.reset_cache()`)로 프로세스 캐시 무효화
  (상세는 [[matching-ontology]])

## 3. Frontend 별도 SSOT: `careand-member-web/lib/caregiverType.ts`

이 파일 상단에 "SSOT"라고 주석이 달려 있지만 **실제로는 backend `config/service_domains.php`와 무관한
독립 하드코딩**이다 (`/service-domains` API로 fetch하지 않고 `DOMAIN_LABEL` 상수를 직접 씀 —
돌봄전문가 본인 직군 라벨링에 사용). admin-web에도 동일 패턴이 있는지 확인.

- [ ] `DOMAIN_LABEL` 레코드에 신규 도메인 코드→한글 라벨 추가 (안 하면 라벨이 코드 그대로 노출됨,
  `domainLabel()`의 폴백이 `code` 원본 반환이라 조용히 깨짐 — 에러가 안 나서 리뷰에서 놓치기 쉬움)
- [ ] admin-web/www에 동일한 도메인 라벨 하드코딩이 있는지 `grep -rl "DOMAIN_LABEL\|domainLabel"`로 확인

## 4. 본인형(self-request) 도메인이면: `guardians.intent` 확장

- [ ] `guardians.intent` DB enum/컬럼 값에 신규 코드 추가 (마이그레이션)
- [ ] frontend `User.guardian.intent` 타입 (`lib/auth/store.ts`)에 신규 값 추가 — 안 하면 TypeScript
  타입은 안 맞아도 런타임은 통과하지만(문자열이라) 자동완성/타입체크 이점을 잃음
- [ ] 홈 서비스 허브의 featured 카드 개인화 로직이 `guardian.intent` 기준이므로, 신규 intent가 어떤
  카드를 우선 노출해야 하는지 기획 확인

## 5. 최종 확인

- [ ] `GET /api/v1/matching/service-domains` 응답에 신규 도메인이 뜨는지 (role별로 다르게 응답되므로
  guardian/organization 등 여러 역할로 확인)
- [ ] 매칭 요청 생성 → `GenerateMatchCandidatesJob` → AI 서비스 `/ai/match/recommend`까지 엔드투엔드
  한 번 통과시켜서 `required_skills`/`service_domain` 필터가 신규 도메인에서도 정상 동작하는지 확인
- [ ] 돌봄전문가(공급자) 등록 플로우에서 `qualification.verify`가 `'auto'`(보건복지부 등 자동 진위조회)면
  실제 연동이 되어 있는지, 아니면 `EXTERNAL_STUB` 스텁으로 동작하는지 확인 ([[backend-engineer]] 참조)
