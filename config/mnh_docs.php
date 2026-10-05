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
 * - fields: 서명 전에 채우는 칸. filled_by=client|caregiver|admin.
 * - before_start: true 면 「서비스 개시 전 서명」 대상(MNH_DOCS_ENFORCE=true 일 때 출근 차단).
 */
$ratings = ['5' => '매우 만족', '4' => '만족', '3' => '보통', '2' => '불만족', '1' => '매우 불만족'];

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

    'types' => [
        'service_contract' => [
            'label' => '서비스 이용계약서', 'signer' => 'client', 'before_start' => true, 'auto' => 'assigned',
            'body' => <<<'TXT'
## 계약 당사자
- 제공기관: {{provider_name}} (사업자등록번호 {{provider_biz_no}}, 대표 {{provider_ceo}})
- 이용자(산모): {{client_name}} (생년월일 {{client_birth}})
- 서비스 장소: {{client_address}}

## 서비스 내용
- 서비스: 산모신생아 건강관리 서비스(보건복지부 바우처)
- 지원 유형: {{support_label}}
- 이용 기간: {{start_date}} ~ {{end_date}} 중 {{days}}일 (제공일 {{weekdays}}, 하루 {{daily_time}})
- 담당 건강관리사: {{caregiver_name}}

## 이용 요금
- 서비스 가격 {{total_price}} 중 정부지원금 {{gov_support}}은 바우처로 결제하고, 본인부담금 {{self_pay}}은 서비스 시작 전에 제공기관에 납부한다.
- 납부 방법: {{payment_method}}

## 일정 변경과 교체
- 공휴일·이용자 사정 등으로 제공하지 않는 날은 연기하여 종료일 뒤로 보충한다.
- 건강관리사 교체가 필요하면 제공기관과 협의하여 지정일부터 교체한다. 그 전까지의 제공 기록은 유지된다.

## 기타
이 계약에서 정하지 않은 사항은 「산모신생아 건강관리 지원사업」 안내 지침과 관계 법령에 따른다.

계약번호 {{contract_no}} · 작성일 {{today}}
TXT,
        ],
        'privacy_consent' => [
            'label' => '개인정보 수집·이용 동의서', 'signer' => 'client', 'before_start' => true, 'auto' => 'assigned',
            'body' => <<<'TXT'
{{provider_name}}은 산모신생아 건강관리 서비스 제공을 위해 아래와 같이 개인정보를 수집·이용합니다.

## 수집 항목
- 산모: 성명, 생년월일, 연락처, 주소, 출산 정보, 건강 관련 정보(서비스 제공에 필요한 범위)
- 신생아: 성명, 성별, 출생일, 출생 체중

## 이용 목적
- 서비스 계약·일정 관리, 건강관리사 배정, 바우처 결제·정산, 서비스 제공기록 보관

## 보유 기간
- 서비스 종료 후 관계 법령과 사업 지침이 정한 기간

## 동의 거부 권리
동의를 거부할 수 있으나, 거부하면 서비스 제공이 어려울 수 있습니다.

이용자 {{client_name}} · 작성일 {{today}}
TXT,
        ],
        'user_rules' => [
            'label' => '서비스 이용자 준수사항', 'signer' => 'client', 'before_start' => true, 'auto' => 'assigned',
            'body' => <<<'TXT'
이용자는 서비스 기간 동안 아래 사항을 지킵니다.

- 건강관리사의 업무는 산모·신생아 건강관리와 이에 딸린 가사 지원으로 한정되며, 그 밖의 업무를 요구하지 않습니다.
- 정해진 휴게시간을 보장합니다.
- 건강관리사에게 폭언·폭행·성희롱 등 인권을 침해하는 행위를 하지 않습니다.
- 일정 변경이나 불편 사항은 제공기관({{provider_phone}})에 알립니다.
- CCTV를 설치·운영하는 경우 건강관리사에게 미리 알립니다.

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
            'label' => '서비스 제공기록지', 'signer' => 'client', 'before_start' => false, 'auto' => 'session',
            'fields' => [
                ['key' => 'mother_care', 'label' => '산모 케어', 'type' => 'checks', 'filled_by' => 'caregiver',
                    'options' => ['체온·혈압 확인', '유방 관리·수유 도움', '좌욕·회음부 관리', '산후 체조', '산모 식사 준비']],
                ['key' => 'newborn_care', 'label' => '신생아 케어', 'type' => 'checks', 'filled_by' => 'caregiver',
                    'options' => ['목욕', '수유·트림', '기저귀·배꼽 관리', '체온·황달 확인', '수면 돌봄']],
                ['key' => 'household', 'label' => '가사 지원', 'type' => 'checks', 'filled_by' => 'caregiver',
                    'options' => ['산모·신생아 세탁', '주변 청소', '식사 설거지']],
                ['key' => 'note', 'label' => '특이사항', 'type' => 'textarea', 'filled_by' => 'caregiver'],
            ],
            'body' => <<<'TXT'
{{session_date}} {{caregiver_name}} 건강관리사가 제공한 서비스입니다. 실제 시간: {{session_time}}

이용자(산모)가 내용을 확인하고 서명합니다. 계약번호 {{contract_no}} · {{session_seq}}일차
TXT,
        ],
        'satisfaction' => [
            'label' => '서비스 만족도 모니터링', 'signer' => 'client', 'before_start' => false, 'auto' => 'completed',
            'fields' => [
                ['key' => 'overall', 'label' => '전체 만족도', 'type' => 'select', 'filled_by' => 'client', 'options' => $ratings],
                ['key' => 'caregiver', 'label' => '건강관리사 서비스', 'type' => 'select', 'filled_by' => 'client', 'options' => $ratings],
                ['key' => 'provider', 'label' => '제공기관 안내·응대', 'type' => 'select', 'filled_by' => 'client', 'options' => $ratings],
                ['key' => 'reuse', 'label' => '다시 이용하거나 추천할 생각', 'type' => 'select', 'filled_by' => 'client',
                    'options' => ['yes' => '있다', 'maybe' => '잘 모르겠다', 'no' => '없다']],
                ['key' => 'comment', 'label' => '하고 싶은 말', 'type' => 'textarea', 'filled_by' => 'client'],
            ],
            'body' => <<<'TXT'
{{client_name}}님, {{start_date}} ~ {{end_date}} 서비스는 어떠셨나요? 응답은 서비스 개선에만 씁니다.

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

## 업무
제공기관이 배정한 이용자 가정에서 산모신생아 건강관리 서비스를 제공한다. 계약 형태·기간·보수·근무 조건은 아래 표와 같다.

## 준수 사항
- 이용자 개인정보와 가정에서 알게 된 사실을 외부에 알리지 않는다.
- 제공기관이 요구하는 자격 서류(건강진단서·교육 이수증 등)를 기한 안에 갱신해 제출한다.
- 매 방문마다 근무일지와 서비스 제공기록지를 작성한다.

작성일 {{today}}
TXT,
        ],
    ],
];
