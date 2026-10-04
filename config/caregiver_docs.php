<?php

/*
 * 돌봄전문가 제출 서류 — 사업계획서 기능 9·20 「신분증·통장사본·범죄경력 서류 업로드와 검증」(2026-09-28, 구현계획 S5).
 * 파일은 MEDICAL_DATA_KEY 로 암호화해 storage/app/caregiver-docs(웹 비공개)에 둔다. 관리자 열람은 사유 입력 + 감사로그.
 * 자격증 사본은 기존 caregivers.license_image_url 을 그대로 쓴다(여기 없음).
 * 산모신생아 건강관리 구비서류(domains=['postpartum'])는 그 직군 돌봄전문가에게만 보인다(2026-10-05).
 * enforce_on_approve=true 면 필수 서류가 전부 「확인」이어야 자격 승인 가능. 기본 false(경고만) — 시범운영 전 도입기업과 정해 켤 것.
 */
return [
    'types' => [
        'id_card'         => ['label' => '신분증 사본',          'required' => true,  'hint' => '주민등록번호 뒷자리는 가리고 올려 주세요.'],
        'bankbook'        => ['label' => '통장 사본',            'required' => true,  'hint' => '정산금을 받을 본인 명의 통장 첫 장'],
        'criminal_record' => ['label' => '범죄경력 회보서',       'required' => true,  'hint' => '성범죄·아동학대 경력 조회 회보서(발급 1년 이내)', 'valid_days' => 365],
        'health_cert'     => ['label' => '건강진단서(보건증)',    'required' => false, 'required_domains' => ['postpartum'], 'public' => true, 'hint' => '매년 갱신 — 산모신생아 건강관리는 필수, 아이돌봄은 제출 권장', 'valid_days' => 365],

        // ── 산모신생아 건강관리 제공인력 인정 구비서류 (요구사항분석 PDF 1장, 2026-10-05) ──
        // domains: 이 직군(service_domains)에 포함된 돌봄전문가에게만 보인다. required 는 그 범위 안에서의 필수.
        // public: 이용자가 정보공개를 원하는 항목(PDF 붉은색) — 확인 완료 시 이용자에게 「확인됨·유효기간」만 보인다(파일은 비공개).
        'employment_contract' => ['label' => '근로계약서 또는 프리랜서 계약서', 'domains' => ['postpartum'], 'required' => true, 'hint' => '제공기관과 맺은 계약서'],
        'training_cert'       => ['label' => '교육수료증',                   'domains' => ['postpartum'], 'required' => true, 'public' => true, 'hint' => '산모신생아 건강관리사 양성교육 수료증'],
        'privacy_consent'     => ['label' => '개인정보활용 동의서',           'domains' => ['postpartum'], 'required' => true],
        'mental_drug_test'    => ['label' => '정신질환 및 약물중독 검사',      'domains' => ['postpartum'], 'required' => true, 'public' => true, 'hint' => '매년 갱신', 'valid_days' => 365],
        'guardianship_absence'=> ['label' => '성년후견인 부존재 확인서',       'domains' => ['postpartum'], 'required' => true],
        'child_abuse_edu'     => ['label' => '아동학대 법정의무교육 이수증',   'domains' => ['postpartum'], 'required' => true, 'public' => true, 'hint' => '매년 이수', 'valid_days' => 365],
        'refresher_edu'       => ['label' => '보수교육 수료증',               'domains' => ['postpartum'], 'required' => true, 'public' => true, 'hint' => '매년 이수', 'valid_days' => 365],
        'child_abuse_consent' => ['label' => '아동학대범죄이력조회 동의서',    'domains' => ['postpartum'], 'required' => true],
        'vaccination'         => ['label' => '예방접종 확인서',               'domains' => ['postpartum'], 'required' => true, 'public' => true, 'hint' => '백일해(Tdap)·인플루엔자 등'],
        'other_license'       => ['label' => '기타 유관 자격증',              'domains' => ['postpartum'], 'required' => false, 'public' => true, 'hint' => '간호사·간호조무사·모유수유 전문가 등(선택)'],
    ],
    'max_kb' => 10240,
    'mimes' => ['jpg', 'jpeg', 'png', 'pdf', 'heic', 'webp'],
    'enforce_on_approve' => (bool) env('CAREGIVER_DOCS_ENFORCE', false),
];
