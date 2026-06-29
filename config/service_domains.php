<?php

/**
 * 서비스 도메인 레지스트리 (SSOT) — Care& 멀티도메인 통합 (Phase 0).
 * BE/FE가 공유하는 도메인 메타데이터의 단일 진실원천.
 *
 *  - 도메인 추가/오픈은 여기(+ service_categories 시드)만 수정하면 된다.
 *  - is_active=false 또는 활성 카테고리가 없는 도메인은 /service-domains API에서 잠복(미노출).
 *  - hidden_for_roles: 해당 역할에게 도메인 자체를 숨김 (예: nursing은 보호자에게 숨김 — 기관 발주 전용).
 *  - picker: FE가 어떤 대상 선택기/엔티티 FK를 쓸지 결정.
 *  - order: API 응답/카드 정렬 순서.
 *
 * 설계서: /root/CAREAND-DOMAIN-INTEGRATION.md
 *
 * Phase 0 기준: senior/nursing/housekeeping 만 활성(현행 동작 동일). 나머지는 잠복.
 * Phase 1에서 housekeeping → living_support 전환 예정(토큰 리네임).
 */
return [

    'senior' => [
        'order'            => 10,
        'label'            => '요양보호',
        'desc'             => '어르신 방문',
        'icon'             => 'heart-pulse',
        'is_active'        => true,
        'hidden_for_roles' => [],
        'picker'           => ['type' => 'senior', 'fk' => 'senior_id'],
        'domain_label'     => '시니어 돌봄',
    ],

    'nursing' => [
        'order'            => 20,
        'label'            => '병원 간병',
        'desc'             => '입원 환자',
        'icon'             => 'stethoscope',
        'is_active'        => true,
        'hidden_for_roles' => ['guardian'], // 기관 발주 전용 — 보호자에게 도메인 숨김
        'picker'           => ['type' => 'patient', 'fk' => 'nursing_patient_id'],
        'domain_label'     => '간병',
    ],

    // Phase 1 전환 완료: housekeeping 데이터는 living_support로 이전됨. 토큰 비활성(잔존).
    'housekeeping' => [
        'order'            => 30,
        'label'            => '가사 서비스',
        'desc'             => '청소·정리',
        'icon'             => 'sparkles',
        'is_active'        => false,
        'hidden_for_roles' => [],
        'picker'           => ['type' => 'address', 'fk' => 'service_address_id'],
        'domain_label'     => '가사',
    ],

    // Phase 1: housekeeping에서 전환 + 동행·정리수납 편입 (활성).
    'living_support' => [
        'order'            => 30,
        'label'            => '생활지원서비스',
        'desc'             => '청소·정리·동행',
        'icon'             => 'sparkles',
        'is_active'        => true,
        'hidden_for_roles' => [],
        'picker'           => ['type' => 'address', 'fk' => 'service_address_id'],
        'domain_label'     => '생활지원',
    ],

    // ── 아래는 잠복(Phase 2~4에서 오픈) ─────────────────────────────────

    // Phase 2: 산모·산후관리 오픈 (통합 generic 흐름 편입). subject=postpartum_clients(user_id 스코프).
    'postpartum' => [
        'order'            => 40,
        'label'            => '산모·산후관리',
        'desc'             => '산모·신생아',
        'icon'             => 'baby',
        'is_active'        => true,
        'hidden_for_roles' => [],
        'picker'           => ['type' => 'postpartum', 'fk' => 'postpartum_client_id'],
        'domain_label'     => '산후 케어',
    ],

    // Phase 3: 아이돌봄 오픈 (children 엔티티 + generic 흐름 편입).
    'childcare' => [
        'order'            => 50,
        'label'            => '아이돌봄',
        'desc'             => '등하원·놀이돌봄',
        'icon'             => 'backpack',
        'is_active'        => true,
        'hidden_for_roles' => [],
        'picker'           => ['type' => 'child', 'fk' => 'childcare_child_id'],
        'domain_label'     => '아이돌봄',
    ],

    // Phase 4: 신규 엔티티(mental_care_clients) 필요.
    'mental_care' => [
        'order'            => 60,
        'label'            => '마음돌봄',
        'desc'             => '정서지원·상담동행',
        'icon'             => 'heart-handshake',
        'is_active'        => false,
        'hidden_for_roles' => [],
        'picker'           => ['type' => 'mental_client', 'fk' => 'mental_care_client_id'],
        'domain_label'     => '마음돌봄',
    ],

];
