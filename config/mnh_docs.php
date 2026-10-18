<?php

/*
 * 산모신생아 바우처 전자서명 서류(CAREN-MNH-01 3단계, 2026-10-05).
 * 요구사항 원문: 이용자 서류 6종(이용계약서·개인정보활용동의서·본인부담금 영수증·서비스 제공기록지·만족도 모니터링·
 * 이용자 준수사항) + 초기상담 기록지, 인력 근로·프리랜서 계약서. 「모든 항목은 전자서명 필요」.
 *
 * - body: 서식 본문 초안. 처음 쓸 때 mnh_doc_templates 에 v1 로 들어가고, 이후엔 관리자 화면에서 고친 판이 쓰인다.
 *   **문안은 케어앤 운영사(법무) 검토 전 초안이다** — 계약 조건을 새로 만들지 않고 계약 데이터를 끼워 넣는 틀만 둔다.
 *   문법: 빈 줄로 문단, "## " 소제목, "- " 목록, {{변수}} 치환(값은 이스케이프).
 * - signer: 서명하는 사람(client=산모, caregiver=돌봄전문가). provision_record 는 관리사 화면에서 산모가 직접 서명한다.
 * - fields: 서명 전에 채우는 칸. filled_by=client|caregiver|admin. type=text|textarea|date|select|checks|number
 *   (number 는 min·max·step·unit). section 이 있으면 그 칸 앞에 소제목. select 는 필수(optional=true 면 선택).
 * - before_start: true 면 「서비스 개시 전 서명」 대상(MNH_DOCS_ENFORCE=true 일 때 출근 차단).
 *
 * 2026-10-18 v2(CAREN-MNH-02 A단계): 실제 제공기관 서식 사진(doc/요구사항분석/각종서식)을 보고 이용계약서·개인정보 동의서·
 * 준수사항·근로계약서 본문을 보강했다. 다른 기관 서식의 금액·단가는 옮기지 않는다(추가 요금은 mnh_addon_items 운영자 입력).
 * 이미 DB 에 v1 이 있는 사이트는 마이그레이션 2026_10_18_000001 이 v2 를 새 판으로 올린다(관리자가 고친 판은 건드리지 않음).
 */
$consent = ['agree' => '동의함', 'disagree' => '동의하지 않음'];
$agree = ['5' => '매우 그렇다', '4' => '그렇다', '3' => '보통이다', '2' => '그렇지 않다', '1' => '전혀 그렇지 않다'];
$good = ['5' => '매우 좋음', '4' => '좋음', '3' => '보통', '2' => '나쁨', '1' => '매우 나쁨'];
$done = ['done' => '실시', 'skip' => '미실시'];

return [
    // 출근 차단 — 기본 꺼짐(경고만). 운영 개시 때 켠다.
    'enforce' => (bool) env('MNH_DOCS_ENFORCE', false),

    // 제공기관 표시 — 값이 없으면 서류에 「(미등록)」으로 나온다(임의 생성 금지)
    'provider' => [
        'name' => env('MNH_PROVIDER_NAME', '케어앤(Care&)'),
        'biz_no' => env('MNH_PROVIDER_BIZ_NO', ''),
        'ceo' => env('MNH_PROVIDER_CEO', ''),
        'address' => env('MNH_PROVIDER_ADDRESS', ''),
        'phone' => env('MNH_PROVIDER_PHONE', ''),
    ],

    // 계약 해지 조건 — 해지 통지 기한(일)과 미통지 위약금 비율(%, 서비스 총 요금 기준).
    // 위약금은 제공기관이 정할 값이라 기본은 비움 → 계약서에 「(미등록)」(임의 생성 금지).
    'terms' => [
        'cancel_notice_days' => (int) env('MNH_CANCEL_NOTICE_DAYS', 7),
        'cancel_penalty_pct' => env('MNH_CANCEL_PENALTY_PCT') !== null && env('MNH_CANCEL_PENALTY_PCT') !== '' ? (float) env('MNH_CANCEL_PENALTY_PCT') : null,
    ],

    'types' => [
        'service_contract' => [
            'label' => '서비스 이용계약서', 'signer' => 'client', 'before_start' => true, 'auto' => 'assigned',
            'body' => <<<'TXT'
## 계약 당사자
- 제공기관: {{provider_name}} (사업자등록번호 {{provider_biz_no}}, 대표 {{provider_ceo}})
- 이용자(산모): {{client_name}} (생년월일 {{client_birth}}, 성별 {{client_gender}})
- 이용자 비상연락처: {{client_emergency}}
- 서비스 장소: {{client_address}}

## 서비스 내용
- 서비스: 산모신생아 건강관리 서비스(보건복지부 바우처)
- 지원 유형: {{support_label}}
- 이용 기간: {{start_date}} ~ {{end_date}} 중 {{days}}일 (제공일 {{weekdays}}, 하루 {{daily_time}})
- 서비스 시간에는 점심을 겸한 휴게시간 1시간이 들어 있으며, 건강관리사의 하루 보수는 8시간 기준이다.
- 담당 건강관리사: {{caregiver_name}}

## 이용 요금
- 서비스 가격 {{total_price}} 중 정부지원금 {{gov_support}}은 바우처로 결제하고, 본인부담금 {{self_pay}}은 서비스 시작 전에 제공기관에 납부한다.
- 납부 방법: {{payment_method}}
- 추가 서비스·대여용품을 신청하면 계약할 때 정한 금액을 따로 낸다.

## 이용자가 지킬 일
- 건강관리사의 휴게시간(하루 1시간)을 보장한다. 보장하지 않으면 추가 요금이 생길 수 있다.
- 건강관리사의 점심 식사를 제공한다. 제공하지 않으면 식사비를 따로 낸다.
- 산모·신생아 밖의 가족 식사·돌봄·가사는 표준 서비스가 아니며, 따로 신청한 경우에만 추가 요금을 내고 받는다.

## 계약의 효력과 변경
- 계약은 이용 기간 동안 효력이 있다. 산모가 병원에 입원하면 바우처 유효기간 안에서 서비스를 멈추고, 멈춘 날만큼 종료일 뒤로 미룬다.
- 공휴일·이용자 사정 등으로 제공하지 않는 날은 연기하여 종료일 뒤로 보충한다.
- 계약의 중요한 내용은 양쪽이 합의해 바꾸고, 바뀐 내용을 이 계약서나 따로 문서로 남긴다.
- 서비스가 계약 내용과 크게 다르면 이용자는 바로잡아 달라고 하거나 건강관리사 교체를 요청할 수 있고, 제공기관은 정당한 사유가 없으면 이에 따른다.
- 배정된 건강관리사가 일할 수 없게 되면 제공기관은 이용자의 동의를 받아 비슷한 수준의 건강관리사로 지정일부터 교체한다. 그 전까지의 제공 기록은 유지된다.

## 계약 해지
- 해지하려는 쪽은 해지 희망일 {{cancel_notice_days}}일 전까지 이유와 함께 상대방에게 알린다.
- 중도에 해지하면 이용자는 이미 받은 서비스의 본인부담금을 내고, 미리 낸 본인부담금 중 쓰지 않은 기간만큼은 정산해 돌려받는다.
- {{cancel_notice_days}}일 전에 알리지 않고 해지하면 위약금으로 {{cancel_penalty}}을 낸다.
- 이용자나 가족이 건강관리사에게 폭언·폭행·성희롱 등 부당한 행위를 하면 제공기관은 관할 시·군·구에 보고하고 계약을 해지할 수 있다.
- 산모의 바우처 자격이 끝나면 계약도 끝난다.

## 개인정보와 비밀 유지
- 제공기관과 건강관리사는 이용자와 가족의 개인정보, 가정에서 알게 된 사실을 서비스 목적 밖으로 쓰거나 알리지 않는다.

## 손해배상과 분쟁
- 건강관리사의 잘못으로 이용자가 손해를 입으면 제공기관이 배상한다(제공기관은 이를 위해 배상책임보험에 가입한다). 천재지변이나 이용자·제3자의 잘못으로 생긴 손해는 예외다.
- 이용자가 제공기관이나 건강관리사에게 손해를 끼친 경우에도 배상한다.
- 분쟁은 먼저 서로 협의해 풀고, 협의가 안 되면 관계 법령과 사업 지침에 따른다.

## 기타
이 계약에서 정하지 않은 사항은 「산모신생아 건강관리 지원사업」 안내 지침과 관계 법령에 따른다.

계약번호 {{contract_no}} · 작성일 {{today}}
TXT,
        ],
        'contract_annex' => [
            // 계약서 별지 — 계약금액 산출내역(각종서식 사진, CAREN-MNH-02 C 2026-10-18). ①합계 ②기본(바우처) ③추가 서비스 ④물품 대여.
            // 단가는 계약에 남긴 스냅샷(mnh_contracts.addons)만 쓴다 — 다른 기관 서식의 단가는 옮기지 않는다.
            'label' => '계약서 별지(계약금액 산출내역)', 'signer' => 'client', 'before_start' => true, 'auto' => 'assigned',
            'body' => <<<'TXT'
## ① 서비스 가격(② + ③ + ④)
| 구분 | 합계 | ② 기본 서비스 | ③ 추가 서비스 | ④ 물품 대여료 |
| 합계 | {{grand_total}} | {{total_price}} | {{extra_total}} | {{rental_total}} |
| 정부지원금 | {{gov_support}} | {{gov_support}} | - | - |
| 본인부담금 | {{grand_self_pay}} | {{self_pay}} | {{extra_total}} | {{rental_total}} |

## ② 기본 서비스(정부 바우처)
- 서비스 유형: {{support_label}}
- 총가격 {{total_price}} · 본인부담금 {{self_pay}}
- 산모: 영양관리, 신체관리, 회음부 관리, 정서·심리적 지원, 위생관리, 세탁물 관리 등
- 신생아: 건강관리와 정보 제공(목욕, 제대 관리, 수유 지도, 예방접종 안내, 아기 돌보기) 등
- 서비스는 산모 가정의 상황에 따라 바꿔 적용할 수 있다.

## ③ 추가 계약 부가서비스
| 항목 | 단가 | 수량 | 금액 |
{{extra_rows}}
- 추가 계약 서비스 가격 총액: {{extra_total}}
- 서비스 일수·시간을 더 늘리려면 제공기관과 협의한다.

## ④ 물품 대여
| 품목 | 단가 | 수량 | 금액 |
{{rental_rows}}
- 대여 물품 대여료 총액: {{rental_total}}

추가 서비스와 물품 대여료는 정부지원 없이 이용자가 모두 부담한다. 금액은 계약할 때 정한 것이며, 뒤에 제공기관 가격표가 바뀌어도 이 계약에는 그대로 적용한다.

이용자 {{client_name}} · 계약번호 {{contract_no}} · 작성일 {{today}}
TXT,
        ],
        'privacy_consent' => [
            'label' => '개인정보 수집·이용 동의서', 'signer' => 'client', 'before_start' => true, 'auto' => 'assigned',
            'fields' => [
                ['key' => 'unique_id_consent', 'label' => '고유식별정보(주민등록번호) 수집·이용', 'type' => 'select', 'filled_by' => 'client', 'options' => $consent],
                ['key' => 'third_party_consent', 'label' => '개인정보 제3자 제공', 'type' => 'select', 'filled_by' => 'client', 'options' => $consent],
            ],
            'body' => <<<'TXT'
{{provider_name}}은 「사회서비스 이용 및 이용권 관리에 관한 법률」과 「개인정보 보호법」에 따라 산모신생아 건강관리 서비스 제공을 위해 아래와 같이 개인정보를 수집·이용합니다.

## 수집 항목
- 산모: 성명, 생년월일, 주소, 연락처, 비상연락처, 출산 정보, 건강 관련 정보(서비스 제공에 필요한 범위)
- 신생아: 성명, 성별, 출생일, 출생 체중
- 바우처 자격 정보: 지원 유형(소득 구간), 본인부담금 납부·환급 계좌
- 고유식별정보: 주민등록번호(외국인은 외국인등록번호) — 본인 확인과 바우처 결제에 필요한 경우에만 쓰며, 아래에서 따로 동의받습니다.

## 이용 목적
- 본인 확인, 서비스 계약·일정 관리, 건강관리사 배정
- 바우처 결제와 본인부담금 납부·환급, 정산
- 서비스 제공기록 보관, 안내 발송, 만족도 조사
- 중복 수혜·부정 수급 확인 등 바우처 사업 운영

## 제3자 제공
- 받는 곳: 보건복지부·지방자치단체 등 사업 관계기관, 바우처 카드 제작·배송기관, 만족도 조사기관
- 제공 항목: 위 수집 항목 가운데 받는 곳의 업무(자격 확인·바우처 운영·만족도 조사)에 필요한 항목

## 보유 기간
- 바우처 자격이 끝난 뒤 5년(사회서비스 이용권 사업 지침). 기간이 지나면 지체 없이 파기합니다.

## 동의 거부 권리
동의를 거부할 수 있으며 거부해도 다른 불이익은 없습니다. 다만 동의하지 않으면 바우처 서비스를 받을 수 없습니다.

이용자 {{client_name}} · 작성일 {{today}}
TXT,
        ],
        'user_rules' => [
            'label' => '서비스 이용자 준수사항', 'signer' => 'client', 'before_start' => true, 'auto' => 'assigned',
            'body' => <<<'TXT'
이용자는 보건복지부 서비스 표준에 따라 서비스 기간 동안 아래 사항을 지킵니다.

## 지킬 일
- 건강관리사의 인격과 전문성을 존중하고, 「관리사님」「선생님」처럼 공식 호칭을 씁니다.
- 서비스 표준과 계약 범위 안에서 필요한 일을 분명하고 정중하게 요청합니다.
- 욕설·폭언·폭행을 하지 않습니다. 폭력이 있으면 서비스가 중지되고 민·형사 책임을 질 수 있습니다.
- 성희롱·성폭력(불필요한 신체 접촉, 지나친 노출, 성적인 농담 포함)을 하지 않습니다. 이런 일이 있으면 건강관리사는 서비스를 거부할 수 있고, 이용자는 이용 자격을 잃거나 처벌받을 수 있습니다.
- 시작·종료 시각을 지키고, 점심을 겸한 휴게시간 하루 1시간을 보장합니다. 휴게는 따로 떨어진 공간에서 하며, 아기 곁에서 잠깐 쉬는 것은 휴게로 보지 않습니다. 휴게를 보장하지 않으면 추가 요금이 생깁니다.
- 건강관리사의 점심 식사를 제공합니다. 제공하지 않으면 식사비를 따로 냅니다.
- CCTV를 설치·운영하는 경우 건강관리사에게 미리 알립니다.
- 일정 변경이나 불편 사항은 제공기관({{provider_phone}})에 알립니다.

## 표준 서비스에 들어가지 않는 일
- 산모·신생아 공간 밖의 청소(다른 가족 방·화장실·공용 공간, 대청소, 창틀·수납장·가전 청소, 서랍 정리 등)
- 산모·신생아 것이 아닌 빨래(배우자·가족 빨래, 고가 의류, 대형 빨래)와 손빨래
- 가족·친지 식사, 손님 접대, 김치·장류 같은 저장 식품, 잔치·제사 음식
- 무거운 가구 옮기기, 분리수거·쓰레기 배출(음식물 쓰레기는 근무시간 안에 정리), 운전, 반려동물 돌보기, 서비스 시간 밖의 청소·설거지
- 큰아이 돌봄과 재택근무 가족의 식사 준비는 추가 요금을 내고 따로 신청한 경우에만 합니다.

이용자 {{client_name}} · 계약번호 {{contract_no}} · 작성일 {{today}}
TXT,
        ],
        'initial_consult' => [
            'label' => '초기상담 기록지', 'signer' => 'client', 'before_start' => true, 'auto' => null,
            'fields' => [
                ['key' => 'consult_date', 'label' => '상담일', 'type' => 'date', 'filled_by' => 'admin'],
                ['key' => 'consultant', 'label' => '상담자', 'type' => 'text', 'filled_by' => 'admin'],
                ['key' => 'mother_health', 'label' => '산모 건강 상태', 'type' => 'textarea', 'filled_by' => 'admin'],
                ['key' => 'newborn_health', 'label' => '신생아 상태', 'type' => 'textarea', 'filled_by' => 'admin'],
                ['key' => 'household', 'label' => '가정 환경·요청 사항', 'type' => 'textarea', 'filled_by' => 'admin'],
                ['key' => 'plan', 'label' => '서비스 계획', 'type' => 'textarea', 'filled_by' => 'admin'],
            ],
            'body' => <<<'TXT'
이용자 {{client_name}}님과 서비스 시작 전에 상담한 내용입니다. 내용을 확인하고 서명해 주세요.

계약번호 {{contract_no}} · 서비스 기간 {{start_date}} ~ {{end_date}}
TXT,
        ],
        'service_plan' => [
            // 사회서비스 제공 계획서(각종서식 사진, CAREN-MNH-02 D 2026-10-18) — 관리자가 서비스 내용·목표·유의 사항을 채워 발행,
            // 이용자가 확인 서명. 초기상담 기록지(상담 내용)와 따로 둔다. 주민번호는 넣지 않는다(생년월일까지).
            'label' => '사회서비스 제공 계획서', 'signer' => 'client', 'before_start' => true, 'auto' => null,
            'fields' => [
                ['key' => 'services', 'label' => '서비스 내용', 'type' => 'checks', 'filled_by' => 'admin', 'options' => [
                    '산모 영양관리(산모 식사)', '산모 신체관리(부종·유방 관리)', '산모 회음부 관리(좌욕)', '산모 정서·심리 지원',
                    '위생관리(산모·신생아 공간 청소)', '세탁물 관리(산모·신생아 세탁물)', '신생아 건강관리·정보 제공(목욕·제대·수유·예방접종 안내·아기 돌보기)',
                ]],
                ['key' => 'goals', 'label' => '서비스 목표', 'type' => 'textarea', 'filled_by' => 'admin'],
                ['key' => 'cautions', 'label' => '유의 사항', 'type' => 'textarea', 'filled_by' => 'admin'],
            ],
            'body' => <<<'TXT'
## 이용자
- 이름: {{client_name}} (생년월일 {{client_birth}})
- 주소: {{client_address}}
- 분만 이력: {{birth_order_label}} · 분만일 {{delivery_date}} · 분만 형태 {{delivery_type_label}}
- 신생아: {{newborn_birth}} · 희망 수유 방법 {{feeding_label}}

## 계약 금액
- 서비스 유형: {{support_label}}
- 서비스 가격 {{total_price}} = 정부지원금 {{gov_support}} + 본인부담금 {{self_pay}}
- 추가 서비스 금액: {{addon_total}}

## 제공 기간과 일정
- 제공 기간: {{start_date}} ~ {{end_date}} ({{days}}일간)
- 제공 일정: {{weekdays}}, 하루 {{daily_time}}(점심을 겸한 휴게시간 1시간 포함)
- 제공일: {{service_dates}}
- 건강관리사의 점심 식사는 이용자가 제공한다. 제공하지 않으면 식사비를 따로 낸다.
- 담당 건강관리사: {{caregiver_name}}

서비스 내용·목표·유의 사항은 아래 표와 같습니다.

제공기관 {{provider_name}} · 계약번호 {{contract_no}} · 작성일 {{today}}
TXT,
        ],
        'receipt' => [
            'label' => '본인부담금 영수증', 'signer' => 'client', 'before_start' => false, 'auto' => 'prepaid',
            'body' => <<<'TXT'
## 영수 내역
- 받은 금액: {{prepaid_amount}}
- 납부일: {{prepaid_date}}
- 납부 방법: {{payment_method}}
- 영수증 번호: {{receipt_no}}

위 금액을 산모신생아 건강관리 서비스(계약번호 {{contract_no}}) 본인부담금으로 받았습니다.

제공기관 {{provider_name}} · 이용자 {{client_name}}
TXT,
        ],
        'provision_record' => [
            // 실제 제공기록지 ①~⑫(각종서식 사진, CAREN-MNH-02 B 2026-10-18) — 관리사가 날마다 채우고 그 기기에서 산모가 서명
            'label' => '서비스 제공기록지', 'signer' => 'client', 'before_start' => false, 'auto' => 'session',
            'fields' => [
                ['key' => 'perineum', 'section' => '산모', 'label' => '① 회음절개 부위(또는 수술 부위)', 'type' => 'checks', 'filled_by' => 'caregiver',
                    'options' => ['열상', '혈종', '불편감', '이상 없음']],
                ['key' => 'breast', 'label' => '② 유방 상태', 'type' => 'checks', 'filled_by' => 'caregiver', 'options' => ['울혈', '통증', '이상 없음']],
                ['key' => 'excretion', 'label' => '③ 배뇨·배변', 'type' => 'checks', 'filled_by' => 'caregiver', 'options' => ['불편감', '이상 없음']],
                ['key' => 'sitz_bath', 'label' => '④ 좌욕', 'type' => 'select', 'filled_by' => 'caregiver', 'options' => $done],
                ['key' => 'massage', 'label' => '⑤ 유방·복부·부종 관리', 'type' => 'checks', 'filled_by' => 'caregiver', 'options' => ['유방', '복부', '부종', '미실시']],
                ['key' => 'massage_area', 'label' => '⑤ 관리 부위', 'type' => 'text', 'filled_by' => 'caregiver'],
                ['key' => 'meals', 'label' => '⑥ 식사', 'type' => 'number', 'filled_by' => 'caregiver', 'min' => 0, 'max' => 10, 'step' => 1, 'unit' => '회'],
                ['key' => 'snacks', 'label' => '⑥ 간식', 'type' => 'number', 'filled_by' => 'caregiver', 'min' => 0, 'max' => 10, 'step' => 1, 'unit' => '회'],
                ['key' => 'temp', 'section' => '신생아', 'label' => '⑦ 체온', 'type' => 'number', 'filled_by' => 'caregiver', 'min' => 34, 'max' => 42, 'step' => 0.1, 'unit' => '°C'],
                ['key' => 'sleep', 'label' => '⑧ 수면', 'type' => 'select', 'filled_by' => 'caregiver', 'options' => ['good' => '잘 잠', 'poor' => '잘 못 잠']],
                ['key' => 'breastfeed', 'label' => '⑨ 모유 수유', 'type' => 'number', 'filled_by' => 'caregiver', 'min' => 0, 'max' => 30, 'step' => 1, 'unit' => '회'],
                ['key' => 'formula', 'label' => '⑩ 분유 수유', 'type' => 'number', 'filled_by' => 'caregiver', 'min' => 0, 'max' => 30, 'step' => 1, 'unit' => '회'],
                ['key' => 'formula_ml', 'label' => '⑩ 분유 회당 양', 'type' => 'number', 'filled_by' => 'caregiver', 'min' => 0, 'max' => 300, 'step' => 5, 'unit' => 'ml'],
                ['key' => 'stool', 'label' => '⑪ 배변', 'type' => 'select', 'filled_by' => 'caregiver', 'options' => ['normal' => '정상변', 'abnormal' => '이상변']],
                ['key' => 'stool_note', 'label' => '⑪ 색깔 등', 'type' => 'text', 'filled_by' => 'caregiver'],
                ['key' => 'bath', 'label' => '⑫ 목욕·제대 관리', 'type' => 'select', 'filled_by' => 'caregiver', 'options' => $done],
                ['key' => 'weight', 'label' => '현재 몸무게', 'type' => 'number', 'filled_by' => 'caregiver', 'min' => 500, 'max' => 9000, 'step' => 10, 'unit' => 'g'],
                ['key' => 'other_service', 'section' => '기타', 'label' => '기타 서비스', 'type' => 'textarea', 'filled_by' => 'caregiver'],
                ['key' => 'note', 'label' => '특이사항(산모·신생아 상태, 계약 관련)', 'type' => 'textarea', 'filled_by' => 'caregiver'],
            ],
            'body' => <<<'TXT'
{{session_date}} {{caregiver_name}} 건강관리사가 제공한 서비스입니다. 실제 시간: {{session_time}}

- 산모: {{client_name}} · 분만 형태 {{delivery_type_label}}
- 신생아: {{newborn_birth}}

이용자(산모)가 내용을 확인하고 서명합니다. 계약번호 {{contract_no}} · {{session_seq}}일차
TXT,
        ],
        'satisfaction' => [
            // 사회서비스 모니터링 조사(산모신생아 B형) + 건강증진 사항(각종서식 사진, CAREN-MNH-02 B 2026-10-18).
            // 이용 기간·태아 유형·출산 순위는 계약에 있어 묻지 않는다(본문에 표시).
            'label' => '서비스 만족도 모니터링', 'signer' => 'client', 'before_start' => false, 'auto' => 'completed',
            'fields' => [
                ['key' => 'age_band', 'section' => '일반 사항', 'label' => '이용자 연령대', 'type' => 'select', 'filled_by' => 'client',
                    'options' => ['u20' => '20대 미만', '20s' => '20대', '30s' => '30대', '40s' => '40대', '50p' => '50대 이상']],
                ['key' => 'respondent', 'label' => '응답하는 분', 'type' => 'select', 'filled_by' => 'client', 'options' => ['self' => '본인', 'guardian' => '보호자']],
                ['key' => 'found_via', 'section' => '서비스 선택', 'label' => '이 기관을 알게 된 경로', 'type' => 'select', 'filled_by' => 'client',
                    'options' => ['web' => '인터넷·SNS', 'promo' => '기관 홍보물', 'referral' => '이웃·지인·다른 기관 소개', 'community' => '맘카페 등 경험자 추천', 'gov' => '지자체 안내', 'etc' => '기타']],
                ['key' => 'chose_for', 'label' => '이 기관을 고른 가장 큰 이유', 'type' => 'select', 'filled_by' => 'client',
                    'options' => ['size' => '규모·인지도', 'reviews' => '경험자 평가·후기', 'kind' => '상담 등 친절', 'distance' => '거리', 'cost' => '비용', 'etc' => '기타']],
                ['key' => 'q_explain', 'section' => '서비스 평가', 'label' => '표준·부가 서비스, 계약, 본인부담금을 충분히 설명받았다', 'type' => 'select', 'filled_by' => 'client', 'options' => $agree],
                ['key' => 'q_fit', 'label' => '필요한 서비스를 적절히 받았다', 'type' => 'select', 'filled_by' => 'client', 'options' => $agree],
                ['key' => 'q_expert', 'label' => '건강관리사가 전문성을 갖추었다', 'type' => 'select', 'filled_by' => 'client', 'options' => $agree],
                ['key' => 'q_kind', 'label' => '건강관리사가 친절했다', 'type' => 'select', 'filled_by' => 'client', 'options' => $agree],
                ['key' => 'q_punctual', 'label' => '약속한 날짜와 시간을 지켰다', 'type' => 'select', 'filled_by' => 'client', 'options' => $agree],
                ['key' => 'q_hygiene', 'label' => '위생 관리를 잘했다', 'type' => 'select', 'filled_by' => 'client', 'options' => $agree],
                ['key' => 'q_resolve', 'label' => '불편한 점을 해결하려고 노력했다', 'type' => 'select', 'filled_by' => 'client', 'options' => $agree + ['none' => '불편한 점이 없었다']],
                ['key' => 'q_helpful', 'label' => '서비스가 도움이 되었다', 'type' => 'select', 'filled_by' => 'client', 'options' => $agree],
                ['key' => 'q_recommend', 'label' => '다른 사람에게 추천하겠다', 'type' => 'select', 'filled_by' => 'client', 'options' => $agree],
                ['key' => 'q_overall', 'label' => '전반적으로 만족한다', 'type' => 'select', 'filled_by' => 'client', 'options' => $agree],
                ['key' => 'h_body', 'section' => '건강 증진', 'label' => '유방·부종 관리, 좌욕, 위생, 산후 체조가 건강 회복에 도움이 되었다', 'type' => 'select', 'filled_by' => 'client', 'options' => $good],
                ['key' => 'h_meal', 'label' => '영양 관리(5대 기초식품군 식단)가 도움이 되었다', 'type' => 'select', 'filled_by' => 'client', 'options' => $good],
                ['key' => 'h_house', 'label' => '식사 준비·생활공간 청소·세탁 지원이 도움이 되었다', 'type' => 'select', 'filled_by' => 'client', 'options' => $good],
                ['key' => 'h_info', 'label' => '산후 회복·신생아 돌봄·모유 수유·응급·감염 예방 안내가 도움이 되었다', 'type' => 'select', 'filled_by' => 'client', 'options' => $good],
                ['key' => 'h_baby', 'label' => '목욕·제대·수유·감염 예방 관리로 아기 건강 상태가 좋았다', 'type' => 'select', 'filled_by' => 'client', 'options' => $good],
                ['key' => 'best_worst', 'section' => '의견', 'label' => '가장 만족한 서비스와 아쉬웠던 서비스', 'type' => 'textarea', 'filled_by' => 'client'],
                ['key' => 'more_needed', 'label' => '더 있었으면 하는 서비스(예: 가사 지원, 베이비시터, 산후 신체 회복)', 'type' => 'textarea', 'filled_by' => 'client'],
            ],
            'body' => <<<'TXT'
{{client_name}}님, {{start_date}} ~ {{end_date}} 서비스는 어떠셨나요? 응답은 서비스 개선과 사업 모니터링에만 씁니다.

- 이용 기간: {{days}}일 · 태아 유형 {{fetus_type_label}} · 출산 순위 {{birth_order_label}}

계약번호 {{contract_no}} · 담당 {{caregiver_name}}
TXT,
        ],
        'employment_contract' => [
            'label' => '근로·프리랜서 계약서', 'signer' => 'caregiver', 'before_start' => false, 'auto' => null,
            'fields' => [
                ['key' => 'kind', 'label' => '계약 형태', 'type' => 'select', 'filled_by' => 'admin',
                    'options' => ['employee' => '근로계약', 'freelance' => '프리랜서(위탁) 계약']],
                ['key' => 'period', 'label' => '계약 기간', 'type' => 'text', 'filled_by' => 'admin'],
                ['key' => 'pay', 'label' => '보수', 'type' => 'text', 'filled_by' => 'admin'],
                ['key' => 'work', 'label' => '업무·근무 조건', 'type' => 'textarea', 'filled_by' => 'admin'],
            ],
            'body' => <<<'TXT'
## 계약 당사자
- 제공기관: {{provider_name}} (대표 {{provider_ceo}})
- 건강관리사: {{caregiver_name}}

## 근무 장소와 업무
- 근무 장소: 제공기관이 배정한 이용자 가정
- 업무: 제공기관이 정한 산후관리 프로그램에 따른 산모신생아 건강관리 서비스
- 제공기관은 건강관리사의 의견을 들어 근무 장소와 업무를 바꿀 수 있다.
- 계약 형태·기간·보수·근무 조건은 아래 표와 같다.

## 근로 조건(근로계약인 경우)
- 근로시간: 평일 09:00~18:00(휴게 1시간 포함). 서로 합의하면 주 12시간 한도에서 연장할 수 있다.
- 근로일과 휴일: 월~금요일 근무, 토·일요일 주휴. 그 밖에는 근로기준법에 따른다.
- 휴가: 연차휴가와 생리휴가는 근로기준법에 따른다.
- 임금: 기본급과 법정수당(연장·야간·휴일)으로 구성하며 금액은 아래 「보수」와 같다. 서비스가 끝난 뒤 7일 안에 건강관리사 본인 명의 계좌로 지급한다.
- 수습: 입사 후 3개월은 수습 기간이며, 업무에 적합하지 않으면 정식 채용하지 않을 수 있다.
- 사회보험: 4대보험에 가입한다(산재보험은 의무, 고용보험은 월 60시간 이상, 건강보험·국민연금은 1개월 이상 계속 근무하고 월 60시간 이상일 때).
- 배상책임보험: 이용자 피해에 대비해 제공기관이 배상책임보험에 가입한다.

## 프리랜서(위탁) 계약인 경우
- 근로시간·임금·사회보험 조항 대신 아래 표의 「업무·근무 조건」과 「보수」에 적은 조건에 따른다.

## 준수 사항
- 이용자 개인정보와 가정에서 알게 된 사실을 외부에 알리지 않는다.
- 제공기관이 요구하는 자격 서류(건강진단서·교육 이수증 등)를 기한 안에 갱신해 제출한다.
- 매 방문마다 근무일지와 서비스 제공기록지를 작성한다.

이 계약에서 정하지 않은 사항은 근로기준법 등 관계 법령에 따른다.

작성일 {{today}}
TXT,
        ],
    ],
];
