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
    'attendance_hard_limit_m' => (int) env('ATTENDANCE_HARD_LIMIT_M', 3000),
];
