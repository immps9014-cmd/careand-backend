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
 *
 * 2026-10-18 v3(운영사 지시 「베이비노트 서식을 케어앤 산모신생아 브랜드로 적용」): 이용계약서(제1~7조, 위약금 10%·동거 가족 어른 식사 1일 5천원)·
 * 이용자 준수사항(①~⑦·표준 서비스 밖 항목·안내 받음 동의 칸)·개인정보 동의서(법 조항 근거·목적 7가지·법정대리인)를
 * 제공기관 서식 문구 그대로 쓰고 이름만 케어앤으로 바꿨다. 케어앤에만 있는 것(공휴일 보충, 신생아·건강 정보 수집)은 덧붙였다.
 * DB 반영은 마이그레이션 2026_10_18_000006(관리자가 고친 판은 건드리지 않음).
 * v4(같은 날): 개인정보 동의서에 민감정보(건강정보) 별도 동의(개인정보 보호법 제23조) 절과 칸 — 마이그레이션 2026_10_18_000007.
 * v5(같은 날): 개인정보 동의서 「(아래 표에 표시)」(종이 서식 표현) → 「(아래 동의 칸에서 고르세요)」 — 마이그레이션 2026_10_18_000008.
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
- 서비스 이용자: {{client_name}} (생년월일 {{client_birth}}) · 주소 {{client_address}} · 비상연락처 {{client_emergency}}
- 서비스 제공자: {{provider_name}} · 주소 {{provider_address}} · 전화 {{provider_phone}}

위 계약 당사자(이하 「이용자」, 「제공자」라 함) 또는 대리인은 다음 계약 내용에 따라 「산모·신생아 건강관리 서비스 이용계약서」를 작성하고 서명한 뒤 각 1통씩 보관한다.

## 1. 계약 내용
※ 제공인력의 일당은 1일 8시간으로 책정되어 있습니다. 휴게시간을 보장하여 주지 않으실 경우 추가요금이 발생할 수 있습니다.
- 계약 기간: {{start_date}} ~ {{end_date}} ({{days}}일)
- 서비스 시간: {{weekdays}}, 하루 {{daily_time}}(점심시간 겸 휴게시간 1시간 포함, 제공인력 식사는 이용자가 제공 — 미제공 시 식사비 별도 청구)
- 서비스 유형: {{support_label}}
- 계약 금액: {{total_price}} (정부지원금 {{gov_support}} · 본인부담금 {{self_pay}}) · 추가금 {{addon_total}}
- 본인부담금 납부 방법: {{payment_method}}
- 서비스 제공인력: {{caregiver_name}}
- 서비스 내용: 계약서 별지(계약금액 산출내역)와 같음
- 비고: 동거 가족 어른 식사를 따로 제공하면 1일 5,000원이 발생한다.

## 2. 서비스 제공 및 이용 관련 기타 합의사항

## 제1조(계약의 효력)
① 이 계약의 효력은 계약 기간 동안 발생한다. 단, 이용자의 병원 입원 등의 경우에는 정부 바우처 유효기간 범위 내에서 효력을 일시 정지시킬 수 있다.
② 공휴일 또는 이용자의 사정으로 서비스를 제공하지 않은 날은 종료일 뒤로 보충하여 제공한다.

## 제2조(계약의 변경)
① 계약 기간, 서비스 시간, 서비스 내용 등 계약 내용에 있어 중대한 변경이 있는 경우, 이용자와 제공자 간 합의에 의해 계약 내용을 변경할 수 있다. 단, 변경된 내용은 계약서 또는 별도의 문서에 명시하여야 한다.
② 이용자는 제공자의 서비스 내용 또는 품질이 계약 내용과 현저히 다르다고 판단될 경우, 제공자에게 서비스의 시정 또는 제공인력의 변경을 요청할 수 있고 제공자는 정당한 사유가 없는 한 이에 응하여야 한다.
③ 제공자의 불가피한 사유로 지정된 제공인력이 서비스를 수행하기 어려운 경우, 제공자는 이용자의 동의를 얻어 유사 수준의 제공인력을 서비스에 변경 투입할 수 있다.

## 제3조(이용자의 인권보장 및 자기결정권)
① 제공자는 이용자에게 서비스 이용에 필요한 정보를 제공하여야 하며, 이용자의 욕구와 특성에 맞는 형태로 정보를 제공한다.
② 이용자는 최초 안내받은 서비스 내용과 현저히 다른 서비스를 제공받은 경우, 제공자에게 서비스 이용 중단을 요청할 수 있다.

## 제4조(개인정보보호 및 이용자 비밀보장)
① 제공자와 제공인력은 계약 및 서비스 과정에서 알게 된 이용자 또는 이용자 가족의 개인정보를 서비스 목적 외에 활용하거나 유출해서는 안 된다.
② 제공자는 서비스를 위해 수집한 이용자의 개인정보에 대해 관련 법규 및 규정에 따라 적절한 보호조치를 취하여야 한다.

## 제5조(계약의 해지·종료)
① 이용자와 제공자는 계약을 해지하고자 할 경우 해지의사와 해지사유를 적어도 해지 희망일 {{cancel_notice_days}}일 전까지 상대방에게 통지하여야 한다.
② 서비스 제공 도중에 계약을 해지하게 된 경우 제공자는 이용자의 서비스 연속성 보장을 위해 적절한 조치를 취하여야 한다. 다만, 이용자의 요청 또는 귀책사유로 인해 계약이 해지된 경우에는 그렇지 아니하다.
③ 이용자 또는 이용자의 가족에 의한 폭언·폭행, 성폭력·성희롱, 이와 유사한 위법 부당행위로 인해 정상적인 서비스 제공이 어려운 경우 제공자는 등록지 관할 시·군·구에 관련 사실을 보고하고 이용자에게 계약 해지를 통보할 수 있다.
④ 서비스 기간 중도에 계약을 해지할 경우 이용자는 이미 제공된 서비스에 대한 본인부담금을 납부하여야 하고, 이미 납부한 본인부담금이 있을 경우 제공자는 미사용 기간에 대한 본인부담금을 정산하여 이용자에게 환급한다. (단, {{cancel_notice_days}}일 이전 고지하여야 하며 그러지 아니할 때는 {{cancel_penalty}}의 위약금이 발생한다)
⑤ 이 계약은 이용자 또는 제공자의 해약 통지나 사망, 정부 바우처 자격의 종료로 종료된다.

## 제6조(손해배상책임)
① 제공자가 파견한 제공인력의 귀책사유로 인하여 발생한 이용자의 손해에 대해서는 제공인력이 배상한다. 다만, 천재지변, 이용자 또는 제3자의 귀책사유로 인한 손해에 대해서는 배상 책임을 지지 아니한다.
② 이용자가 제공자 또는 제공자가 파견한 제공인력에게 손해를 끼친 경우에도 또한 같다.

## 제7조(분쟁의 해결)
본 계약의 이행과 해석에 있어 발생하는 분쟁은 당사자 간 원만한 합의에 의해 처리하며, 당사자 간 합의에 도달하지 못한 경우에는 관련 법규 또는 관례에 따른다.

계약 당사자 — 서비스 이용자 {{client_name}} · 서비스 제공자 {{provider_name}} · 계약번호 {{contract_no}} · 작성일 {{today}}
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
                // 민감정보(건강정보) 별도 동의 — 개인정보 보호법 제23조(2026-10-18 v4). 동의하지 않으면 계약 이력에 남겨 담당 관리자가 알 수 있게 한다
                ['key' => 'sensitive_consent', 'label' => '민감정보(건강정보) 수집·이용', 'type' => 'select', 'filled_by' => 'client', 'options' => $consent],
                ['key' => 'third_party_consent', 'label' => '개인정보 제3자 제공', 'type' => 'select', 'filled_by' => 'client', 'options' => $consent],
            ],
            'body' => <<<'TXT'
구분: 이용자

본 기관({{provider_name}})은 「사회서비스 이용 및 이용권 관리에 관한 법률 시행규칙」 제6조(서비스 이용권의 기재사항) 및 「개인정보보호법」 제15조(개인정보의 수집·이용)에 의거하여 개인정보를 수집합니다.

## 개인정보 수집 항목
- 고유식별정보: 주민등록번호, 외국인등록번호
- 개인정보: 성명, 생년월일, 주소, 연락처, 예금계좌, 자격판정 정보(가구, 금융, 소득, 장애 정보 등)
- 서비스 제공 정보: 비상연락처, 출산·신생아 정보(성별, 출생일, 출생 체중)
- 민감정보(건강정보): 아래에서 따로 동의받습니다.

## 개인정보 수집 및 이용 목적
- 사회서비스 전자바우처 제도 관련 본인 확인
- 서비스 제공을 위한 바우처 카드 제작 및 배송
- 바우처의 생성 및 본인부담금 납부·환급 업무에 활용
- 사회서비스 관련 각종 안내문 발송 또는 전송
- 사회서비스 관련 만족도 조사
- 사회복지서비스 중복수혜 및 부정수급 확인
- 기타 사회서비스 전자바우처 제도 운영에 필요한 자료로 활용

## 개인정보 보유 및 이용기간
- 상기 개인정보는 전자바우처 이용 자격 종료 후 5년 동안 보유 및 이용됩니다.

## 동의를 거부할 권리 및 거부 시 불이익
상기 내용은 사회서비스 전자바우처 업무와 지원을 위해 필요한 최소한의 정보에 해당하며 그 내용에 관하여 개인정보 제공 동의를 거부할 권리가 있으며, 동의 거부에 따른 불이익은 없음. 다만, 전자바우처 서비스를 제공받을 수 있는 대상자가 될 수 없음을 알려 드립니다.

## 고유식별정보 수집 및 이용에 관한 별도 동의(「개인정보 보호법」 제24조 제1항 관련)
- 고유식별정보: 주민등록번호, 외국인등록번호 — 위 고유식별정보 수집에 동의하십니까? (아래 동의 칸에서 고르세요)

## 민감정보(건강정보) 수집 및 이용에 관한 별도 동의(「개인정보 보호법」 제23조 제1항 관련)
산모신생아 건강관리 서비스를 제공하기 위해 아래 건강정보를 수집·이용합니다.
- 수집 항목: 분만 형태·임신 합병증·산후 건강 상태·복용 약, 서비스 제공기록지의 산모·신생아 관찰 기록(회음부·유방·배변·체온·수유·몸무게 등), 이용일지의 건강 기록, 산후우울 검사(EPDS) 응답과 결과
- 이용 목적: 산모·신생아 맞춤 건강관리 서비스 제공, 이상 징후 확인과 담당자 알림, 산후우울 고위험 시 상담·심리지원 연계
- 보유 기간: 위 개인정보와 같이 전자바우처 이용 자격 종료 후 5년
- 동의를 거부할 수 있습니다. 다만 거부하면 건강 상태에 맞춘 관리·이상 징후 알림·산후우울 검사 연계를 제공하기 어렵습니다.
위 민감정보 수집·이용에 동의하십니까? (아래 동의 칸에서 고르세요)

## 개인정보의 제3자 제공에 관한 별도 동의(「개인정보 보호법」 제18조 제2항 관련)
상기 목적을 위하여 수집한 개인정보를 아래와 같이 제3자에게 제공할 수 있습니다.
- 사회서비스 관련 만족도 조사 기관
- 사회서비스 바우처 카드 제작, 배송 기관
- 중복수혜, 부정수급 확인 등과 관련한 유관 정부기관 또는 공공기관
- 정부 재정일자리 사업 관련기관
위와 같이 개인정보의 제3자 제공에 동의하십니까? (아래 동의 칸에서 고르세요)

개인정보 보호법에 명기된 법률상의 개인정보처리자가 준수하여야 할 개인정보보호 규정을 준수하고, 관련 법령에 의거하여 대상자의 권익보호에 최선을 다하며 허가된 이용 목적 외에는 사용하지 않을 것을 약속드립니다.
※ 만 14세 미만 아동인 경우 반드시 법정대리인의 동의가 필요합니다.

신청인 {{client_name}} · 작성일 {{today}}
TXT,
        ],
        'user_rules' => [
            'label' => '서비스 이용자 준수사항', 'signer' => 'client', 'before_start' => true, 'auto' => 'assigned',
            // 원본(제공기관 서식)과 같이 안내를 받았는지 동의함·동의하지 않음으로 표시(v3)
            'fields' => [
                ['key' => 'rules_agree', 'label' => '이용자 준수사항 및 서비스 항목에 관한 안내를 받았음', 'type' => 'select', 'filled_by' => 'client', 'options' => $consent],
            ],
            'body' => <<<'TXT'
## 케어앤 이용자 준수사항(보건복지부 표준지침)
① 이용자는 제공인력의 인격과 전문성을 존중해야 합니다. — 「산모·신생아 건강관리사」입니다.
② 이용자는 서비스 표준 및 계약 내용의 범위를 벗어나지 않는 범위 내에서 서비스를 요청하셔야 합니다.
③ 이용자가 제공인력에게 서비스를 요청할 때에는 명확하고 구체적이며 정중하게 요청하셔야 합니다.
④ 제공인력에게는 공식적인 호칭(관리사님, 선생님 등)을 사용하시기 바랍니다.
⑤ 욕설, 신체적 폭력 등 인격을 무시하는 발언이나 행동을 하여서는 안 됩니다.
⑥ 제공인력에게 성적 수치심을 주는 성희롱이나 성폭력에 해당하는 행위를 하여서는 안 됩니다. 특히 불필요한 신체적 접촉이나 과도한 노출, 성적 농담으로 불쾌감을 주는 행위도 성희롱에 속합니다.
⑦ 계약에서 정한 바에 따라 시작시각과 종료시각을 반드시 지켜 주시고, 제공인력이 업무에서 벗어나 자유롭게 쉴 수 있는 점심시간 포함 휴게시간(1일 1시간)을 반드시 보장하여 주시기 바랍니다. (제공인력의 근무수당은 1일 8시간으로 책정되어 있습니다 — 점심시간 및 휴게시간을 보장하여 주지 않으실 경우 추가요금이 발생할 수 있습니다.)

- 휴게시간은 별도의 독립된 공간에서 가져야 하며, 아기가 자고 있을 때 옆에서 잠시 쉬는 것은 휴게시간이 아닌 업무 중 대기시간으로 볼 수 있습니다. (제공인력 점심식사는 이용자가 제공, 식사 미제공 시 식사비 별도 청구 예정)
- 지금 산모님 댁에 가신 산모·신생아 건강관리사도 「누군가의 소중한 가족이자 누군가의 어머니」입니다.
- 산모 및 신생아 외 다른 가족에 대한 돌봄이나 가사활동 지원 등은 표준서비스에 포함되지 않습니다.
- 구타 등 신체적 폭력을 행할 경우 서비스 이용 중지는 물론, 민·형사상 처벌 대상이 될 수 있습니다.
- 이용자 및 그 가족의 성희롱 행위가 밝혀지는 경우 해당 제공인력은 서비스 제공을 거부할 수 있으며, 정도에 따라 서비스 이용 자격 박탈, 「성폭력범죄의 처벌 등에 관한 특례법」 등에 의해 처벌받을 수 있습니다.

## 케어앤 표준 서비스에 들어가지 않는 항목
산모·신생아 주생활 공간 이외 청소
- 다른 가족 방, 화장실, 공용공간(현관, 서재, 드레스룸, 베란다, 창고, 유리창, 마당 등), 서랍정리, 창틀, 수납공간(싱크대, 냉장고, 장롱, 찬장, 신발장 등), 무릎 꿇고 하는 걸레질, 기타 비일상적인 청소 및 대청소(가전제품 청소, 묵은 김치통 세척 등)

산모·신생아 의류 등 이외 세탁
- 배우자 및 기타 가족 빨래, 고가 의류, 대형 빨래(침구, 커튼, 신발류, 가방류, 부피 큰 패딩류, 타계절 옷 등)
- 세탁 시 손빨래는 하지 않습니다.

산모·신생아 이외 가족·친지 식사 준비, 자택 외 다른 장소에서의 식사 준비, 저장식품 요리(김치, 장류, 장아찌 등), 잔치음식 준비, 제사음식 준비, 차 접대 수준을 넘는 손님 접대
- 남편 재택 근무 시 식사 1일 1식 이상 별도로 제공할 경우 추가금이 발생합니다(1일 5,000원).

기타
- 부피가 크거나 무거운 가구·물건 옮기기는 하지 않습니다.
- 분리수거 배출 및 쓰레기 배출(가정 내 생활쓰레기 등)은 하지 않습니다.
- 큰아이 또는 다른 가족 돌보기는 표준서비스가 아니므로 동거하고 계실 경우 추가요금이 발생할 수 있습니다.
- 운전 대행은 하지 않습니다.
- 반려동물 돌보기는 하지 않습니다(먹이주기, 목욕시키기, 용변 치우기 등).
- 서비스 시간 외 발생한 청소 및 설거지 등은 하지 않습니다(예: 저녁식사 후 설거지·청소, 주말 동안 설거지·청소).
- 음식물쓰레기 수거는 근무시간 내에 이루어집니다.

케어앤 이용자 준수사항 및 서비스 항목에 관한 안내를 받았음. — 서비스 대상자 {{client_name}} · 계약번호 {{contract_no}} · 작성일 {{today}}
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
