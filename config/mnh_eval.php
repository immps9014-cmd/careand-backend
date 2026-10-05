<?php

/*
 * 산모신생아 양방향 평가와 종합평가 육각형(CAREN-MNH-01 4단계, 2026-10-05).
 * 원문: 이용자 평가서(→인력, 1단계 reviews.scores 6항목) · 제공인력 평가서(→이용자) · 기관 평가서(→인력),
 *       「근무일지 + 이용자 평가 + 기관 평가 = 분석 후 육각형 모형」. 모두 수시/종료 시.
 * 항목 키는 저장값(mnh_evaluations.scores)이라 바꾸지 말 것 — 라벨만 바꿀 수 있다. 점수는 1~5.
 *
 * 육각형 축(axes) 은 여러 출처의 평균(동일 가중). 출처:
 *   review:<키>  = 이용자 후기(reviews, reviewer_role=guardian, 산모신생아 요청) 항목 평균
 *   org:<키>     = 기관 평가 항목 평균
 *   log:punctual = 출근 정시율(예정 시각+grace 분 안에 출근한 완료 방문 비율) → 1+4×비율
 *   log:journal  = 기록 성실도(근무일지 칩·메모 또는 산모 서명 제공기록지가 있는 완료 방문 비율) → 1+4×비율
 * 축 정의는 2026-10-05 사용자 확정(동일 가중·6축 그대로). 바꿀 땐 운영 판단으로.
 */
return [
    'caregiver_to_client' => [
        'manners' => '기본 예절',
        'scope' => '업무범위 준수',
        'rest' => '휴게시간 준수',
        'cooperation' => '업무 협조·공조',
    ],
    'org_to_caregiver' => [
        'attendance' => '근태',
        'skill' => '업무 숙련도',
        'mind' => '서비스 마인드',
    ],
    'timings' => ['interim' => '수시', 'final' => '종료'],

    'punctual_grace_minutes' => 10,
    // 축 점수를 보여 줄 최소 근거 수(이보다 적으면 「자료 부족」 표시, 점수는 계산)
    'min_samples' => 3,

    'axes' => [
        'newborn' => ['label' => '신생아 케어', 'sources' => ['review:newborn_care']],
        'mother' => ['label' => '산모 케어', 'sources' => ['review:mother_care', 'review:hygiene']],
        'mind' => ['label' => '소통·서비스 마인드', 'sources' => ['review:communication', 'review:privacy', 'org:mind']],
        'skill' => ['label' => '업무 숙련도', 'sources' => ['review:work_sense', 'org:skill']],
        'attendance' => ['label' => '근태', 'sources' => ['org:attendance', 'log:punctual']],
        'record' => ['label' => '기록 성실도', 'sources' => ['log:journal']],
    ],
];
