<?php

/*
 * 매칭 시간 규칙 — 사업계획서 기능 10·11·18·21(2026-09-28, 구현계획 S5). matching:watch 가 매분 적용한다.
 */
return [
    // 보호자가 후보를 지정한 뒤 돌봄전문가가 수락해야 하는 시간(분). 지나면 자동 거절 → 보호자에게 다른 후보 선택 안내
    'offer_timeout_min' => (int) env('MATCH_OFFER_TIMEOUT_MIN', 5),
    // 요청 후 이 시간(시간)이 지나도 매칭 안 되면 매칭 담당 관리자에게 한 번 알림
    'unmatched_alert_hours' => (int) env('MATCH_UNMATCHED_ALERT_HOURS', 6),
    // 방문 몇 시간 전에 보호자·돌봄전문가에게 리마인더(한 세션에 한 번)
    'remind_before_hours' => (int) env('CARE_REMIND_BEFORE_HOURS', 24),
    // 출퇴근 GPS(기능 12): 도메인 반경(200m·병원 500m) 밖이어도 이 거리(m) 안이면 받아 주고 운영팀에 경고, 넘으면 거부
    // 최소 신청 시각(분) — 지금부터 이 시간 뒤부터만 방문 시작을 고를 수 있다. 돌봄전문가가 수락·이동할 시간(2026-09-29,
    // 16:42 에 16:44 방문을 신청해 수락 전에 일정이 지나던 사례). 긴급은 짧게.
    'min_lead_minutes' => (int) env('MATCH_MIN_LEAD_MINUTES', 120),
    'min_lead_minutes_emergency' => (int) env('MATCH_MIN_LEAD_MINUTES_EMERGENCY', 60),
    // 출근 가능 시각 — 방문 시작 이 시간(분) 전부터. 전날 출근이 받아져 케어 시작·정산 시간이 틀어지던 문제(2026-09-29)
    'checkin_early_minutes' => (int) env('CHECKIN_EARLY_MINUTES', 60),
    'attendance_hard_limit_m' => (int) env('ATTENDANCE_HARD_LIMIT_M', 3000),
    // 보호자 결제 완료 전엔 출근 불가 — 결제 없이 케어가 시작·완료되던 문제(2026-10-04 사용자 결정 「출근 차단」)
    'checkin_requires_payment' => (bool) env('CHECKIN_REQUIRES_PAYMENT', true),
];
