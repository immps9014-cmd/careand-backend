<?php

/*
 * 관리자 권한 5단계(RBAC) — 사업계획서 3.3 (2026-09-28, 구현계획 S2-3).
 * 영역 = /api/v1/admin/{영역}/... 의 첫 조각(관리자 웹 메뉴와 1:1). read = GET 조회, write = 그 외 변경.
 * 표에 없는 영역은 default(슈퍼관리자만) — 새 관리자 API 를 만들면 여기에 영역을 추가해야 다른 등급이 쓸 수 있다.
 * 개인정보 CSV 다운로드는 슈퍼관리자만(사유 입력·감사로그 필수) — S2-5.
 */
$ops = ['super', 'branch', 'cs'];
$all = ['super', 'branch', 'cs', 'analyst', 'developer'];

return [
    'levels' => [
        'super' => '슈퍼관리자',
        'branch' => '지점장',
        'cs' => 'CS 담당자',
        'analyst' => '데이터 분석가',
        'developer' => '개발자',
    ],

    'areas' => [
        'dashboard'     => ['label' => '대시보드',            'read' => $all,                                   'write' => ['super']],
        'insights'      => ['label' => 'AI 인사이트 검색',     'read' => ['super', 'branch', 'analyst'],          'write' => ['super']],
        'members'       => ['label' => '회원 관리',            'read' => $ops,                                   'write' => ['super', 'branch']],
        'blacklist'     => ['label' => '블랙리스트',            'read' => $ops,                                   'write' => ['super', 'branch']],
        'organizations' => ['label' => '기관 관리',            'read' => $ops,                                   'write' => ['super', 'branch']],
        'caregivers'    => ['label' => '돌봄전문가 자격검증',   'read' => $ops,                                   'write' => ['super', 'branch']],
        'matching'      => ['label' => '매칭 관리',            'read' => $ops,                                   'write' => ['super', 'branch']],
        'contracts'     => ['label' => '계약·일정',            'read' => $ops,                                   'write' => ['super', 'branch']],
        'care-sessions' => ['label' => '케어 진행 현황',        'read' => $ops,                                   'write' => ['super', 'branch']],
        'care-logs'     => ['label' => 'AI 일지 검수',          'read' => $ops,                                   'write' => $ops],
        'monitoring'    => ['label' => '케어 모니터링',         'read' => $ops,                                   'write' => $ops],
        'cs'            => ['label' => 'CS / 분쟁',            'read' => $ops,                                   'write' => $ops],
        'announcements' => ['label' => '공지·푸시',            'read' => $ops,                                   'write' => ['super', 'branch']],
        'settlements'   => ['label' => '정산',                 'read' => ['super', 'branch'],                    'write' => ['super', 'branch']],
        'ai-models'     => ['label' => 'AI 모델',              'read' => ['super', 'analyst', 'developer'],      'write' => ['super', 'developer']],
        'ontology'      => ['label' => '온톨로지 분석',         'read' => ['super', 'analyst', 'developer'],      'write' => ['super', 'developer']],
        'reports'       => ['label' => '리포트',               'read' => ['super', 'branch', 'analyst'],          'write' => ['super']],
        'admins'        => ['label' => '관리자 계정·권한',      'read' => ['super'],                              'write' => ['super']],
        'exports'       => ['label' => '개인정보 다운로드',     'read' => ['super'],                              'write' => ['super']],
    ],

    'default' => ['read' => ['super'], 'write' => ['super']],
];
