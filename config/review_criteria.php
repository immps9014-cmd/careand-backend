<?php

/*
 * 도메인별 후기 평가 항목 — 사업계획서 기능 7 「도메인별 평가항목」(2026-09-28, 구현계획 S5).
 * 항목마다 1~5점. 전체 별점(rating)은 따로 받는다(평균 평점·정렬은 전체 별점 기준 그대로).
 * 항목 키는 저장값(reviews.scores)이라 바꾸지 말 것 — 라벨만 바꿀 수 있다. 없는 도메인은 default.
 */
$common = ['punctual' => '시간 약속', 'attitude' => '친절·태도', 'communication' => '소통·보고'];

return [
    'senior'         => $common + ['care_skill' => '돌봄 전문성', 'safety' => '안전 관리'],
    'nursing'        => $common + ['care_skill' => '간병 전문성', 'hygiene' => '위생·청결'],
    'housekeeping'   => $common + ['quality' => '작업 완성도', 'tidiness' => '정리·마무리'],
    'living_support' => $common + ['quality' => '작업 완성도', 'tidiness' => '정리·마무리'],
    'postpartum'     => $common + ['newborn_care' => '신생아 돌봄', 'mother_care' => '산모 회복 도움'],
    'childcare'      => $common + ['child_bond' => '아이와의 교감', 'safety' => '안전 관리'],
    'mental_care'    => $common + ['empathy' => '공감·경청', 'privacy' => '비밀 유지'],
    'default'        => $common,
];
