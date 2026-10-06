<?php

/**
 * 케어앤에듀 인증 돌봄전문가(2026-10-07 사용자 요청).
 * 「케어앤에듀가 인정하는 활동 5회 이상 + 평점 일정 수준 이상」이면 자격을 주고 계정에 인증 마크를 붙인다.
 * 활동·평점은 저장 칸(caregivers.completed_sessions·rating_avg — 데모 시드 섞임)이 아니라 실제 기록에서 센다:
 *   활동 = 이 플랫폼에서 완료된 돌봄(care_sessions.status=completed), 평점 = 보호자 후기(reviews, reviewer_role=guardian) 평균.
 * 매일 caregivers:certify 가 기준을 넘은 사람에게 자동 부여. 관리자는 직접 부여·취소(사유 필수). 취소된 사람은 자동 재부여 안 함.
 * 부여 뒤 평점이 떨어져도 자동으로 빼지 않는다(자격증 성격) — 필요하면 관리자가 취소.
 */
return [
    'program' => 'careand_certified',
    'name' => '케어앤에듀 인증 돌봄전문가',
    'issuer' => '케어앤에듀',
    'number_prefix' => 'CAE',
    'min_sessions' => (int) env('CERT_MIN_SESSIONS', 5),
    'min_rating' => (float) env('CERT_MIN_RATING', 4.5),
    'min_reviews' => (int) env('CERT_MIN_REVIEWS', 3),   // 후기 1건 평균에 휘둘리지 않게
    'auto_grant' => (bool) env('CERT_AUTO_GRANT', true),
];
