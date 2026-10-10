<?php

/*
 * 산모신생아 건강관리 바우처 기간형 계약(CAREN-MNH-01 2단계, 2026-10-05).
 * 제공기관 = 케어앤 운영사(사용자 결정 10-05). 지원유형 금액은 config 가 아니라 관리자 화면(mnh_support_types)에서
 * 고시 원문을 보고 입력한다 — 여기엔 화면 라벨과 일정 기본값만 둔다.
 */
return [
    'min_days' => 5,
    'max_days' => 40,

    // 기본 제공 요일(평일). 토요일 제공 등 계약별 조정은 관리자 상세에서.
    'weekdays' => [1, 2, 3, 4, 5],
    'daily_start' => '09:00',
    'daily_minutes' => 480,

    // 계약 신청 시 개시일 하한(오늘 기준 일수) — 출산 예정일 60일 전부터 신청 가능하므로 개시일은 미래면 된다
    'min_lead_days' => 1,

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
