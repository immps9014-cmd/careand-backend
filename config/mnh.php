<?php

/*
 * 산모신생아 건강관리 바우처 기간형 계약(CAREN-MNH-01 2단계, 2026-10-05).
 * 제공기관 = 케어앤 운영사(사용자 결정 10-05). 지원유형 금액은 config 가 아니라 관리자 화면(mnh_support_types)에서
 * 고시 원문을 보고 입력한다 — 여기엔 화면 라벨과 일정 기본값만 둔다.
 */
return [
    // 바우처 자료 보존 — 개인정보 동의서 「전자바우처 이용 자격 종료 후 5년」(2026-10-18). 탈퇴해도 마지막 계약이 끝난 날로부터
    // 이 기간이 지나야 산모 정보·계약 서류·이용일지·산후우울 검사를 파기한다(privacy:purge ④). 매출·정산 금액은 남긴다.
    'retention_years' => (int) env('MNH_RETENTION_YEARS', 5),
    'min_days' => 5,
    'max_days' => 40,

    // 기본 제공 요일(평일). 토요일 제공 등 계약별 조정은 관리자 상세에서.
    'weekdays' => [1, 2, 3, 4, 5],
    'daily_start' => '09:00',
    'daily_minutes' => 480,

    // 계약 신청 시 개시일 하한(오늘 기준 일수) — 출산 예정일 60일 전부터 신청 가능하므로 개시일은 미래면 된다
    'min_lead_days' => 1,

    // 출산 전 신청은 출산 예정일 이 일수 전부터(사업 지침 「예정일 60일 전부터 신청」, CAREN-MNH-02 E 2026-10-18)
    'apply_before_due_days' => 60,

    'fetus_types' => [
        'single' => '단태아',
        'twins' => '쌍태아',
        'triplets_plus' => '삼태아',
        'quadruplets_plus' => '사태아 이상',
    ],
    'birth_orders' => [
        'first' => '첫째아',
        'second' => '둘째아',
        'third_plus' => '셋째아 이상',
        'any' => '출산순위 무관',
    ],
    'periods' => [
        'short' => '단축',
        'standard' => '표준',
        'extended' => '연장',
    ],
    'addon_kinds' => [
        'extra' => '추가 서비스',
        'rental' => '대여용품',
    ],
    'payment_methods' => [
        'cash' => '현금',
        'card' => '카드',
        'local_currency' => '지역화폐',
    ],
    'statuses' => [
        'applied' => '신청',
        'confirmed' => '배정 완료',
        'active' => '서비스 중',
        'completed' => '종료',
        'cancelled' => '취소',
    ],
];
