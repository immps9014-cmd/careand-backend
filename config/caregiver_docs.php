<?php

/*
 * 돌봄전문가 제출 서류 — 사업계획서 기능 9·20 「신분증·통장사본·범죄경력 서류 업로드와 검증」(2026-09-28, 구현계획 S5).
 * 파일은 MEDICAL_DATA_KEY 로 암호화해 storage/app/caregiver-docs(웹 비공개)에 둔다. 관리자 열람은 사유 입력 + 감사로그.
 * 자격증 사본은 기존 caregivers.license_image_url 을 그대로 쓴다(여기 없음).
 * enforce_on_approve=true 면 필수 서류가 전부 「확인」이어야 자격 승인 가능. 기본 false(경고만) — 시범운영 전 도입기업과 정해 켤 것.
 */
return [
    'types' => [
        'id_card'         => ['label' => '신분증 사본',          'required' => true,  'hint' => '주민등록번호 뒷자리는 가리고 올려 주세요.'],
        'bankbook'        => ['label' => '통장 사본',            'required' => true,  'hint' => '정산금을 받을 본인 명의 통장 첫 장'],
        'criminal_record' => ['label' => '범죄경력 회보서',       'required' => true,  'hint' => '성범죄·아동학대 경력 조회 회보서(발급 1년 이내)', 'valid_days' => 365],
        'health_cert'     => ['label' => '건강진단서(보건증)',    'required' => false, 'hint' => '산후·아이돌봄은 제출 권장', 'valid_days' => 365],
    ],
    'max_kb' => 10240,
    'mimes' => ['jpg', 'jpeg', 'png', 'pdf', 'heic', 'webp'],
    'enforce_on_approve' => (bool) env('CAREGIVER_DOCS_ENFORCE', false),
];
