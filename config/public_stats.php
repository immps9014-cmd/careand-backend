<?php

/**
 * 공개 웹 신뢰지표(PublicController::stats) 공개 기준 — 실제 실적이 적을 땐 숫자 대신 「집계 중」(2026-10-18).
 */
return [
    // 누적 완료 돌봄이 이 건수 이상일 때만 숫자를 공개한다
    'completed_sessions_min' => (int) env('PUBLIC_SESSIONS_MIN', 100),
];
