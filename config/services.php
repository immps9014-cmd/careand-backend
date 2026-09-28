<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    */

    // 외부 연동(SMS/PG/FCM/NHIS/홈택스/복지부)을 실제 호출 대신 스텁 응답으로 처리.
    // 실 자격증명·연동 준비 전까지 true. APP_ENV(production 전환)와 무관하게 제어하기 위함.
    'external' => [
        'stub' => env('EXTERNAL_STUB', true),
    ],

    // 스텁 모드에서 고정 인증번호(123456)를 허용할 테스트 번호 접두어(숫자만, 쉼표 구분) — 2026-09-28 S2
    // 카카오·구글 로그인(S4) — client_id 가 비어 있으면 해당 버튼·API 비활성
    'oauth' => [
        'redirect_base' => env('OAUTH_REDIRECT_BASE', 'https://caren.aiclaude.kr/app/auth/callback'),
        // 로그인 전용 키 — 지오코딩용 KAKAO_REST_API_KEY 와 분리(그 앱에 카카오 로그인이 켜져 있다는 보장이 없음)
        'kakao' => ['client_id' => env('KAKAO_LOGIN_CLIENT_ID', ''), 'client_secret' => env('KAKAO_LOGIN_CLIENT_SECRET', '')],
        'google' => ['client_id' => env('GOOGLE_CLIENT_ID', ''), 'client_secret' => env('GOOGLE_CLIENT_SECRET', '')],
    ],

    'otp' => [
        'stub_test_prefixes' => env('OTP_STUB_TEST_PREFIXES', '0100000'),
    ],

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'ap-northeast-2'),
    ],

    // SMS (OTP)
    'sms' => [
        'provider' => env('SMS_PROVIDER', 'aligo'),
        'api_key' => env('SMS_API_KEY'),
        'user_id' => env('SMS_USER_ID'),
        'sender' => env('SMS_SENDER'),
    ],

    // 카카오 알림톡(알리고) — 기능 6·32(2026-09-28 S4). 키는 위 sms 와 공용(알리고 계정 하나).
    // live=false 거나 키가 비면 발송하지 않고 message_logs 에 stub 으로만 남긴다(PG_LIVE 와 같은 방식).
    'alimtalk' => [
        'live' => env('ALIMTALK_LIVE', false),
        'sender_key' => env('ALIMTALK_SENDER_KEY', ''),   // 발신 프로필 키(카카오 채널 연결 후 알리고가 발급)
        'token' => env('ALIMTALK_TOKEN', ''),             // 비우면 자동 발급(30일)
        'templates' => env('ALIMTALK_TEMPLATES', ''),     // "CAREN_MATCH_OK=TA_1234,CAREN_SAFETY=TA_5678"
    ],

    // 보건복지부 (요양보호사·간호조무사 자격 진위확인)
    'mohw' => [
        'url' => env('MOHW_API_URL'),
        'api_key' => env('MOHW_API_KEY'),
    ],

    // 한국보건의료인국가시험원(국시원) — 간호사 면허 진위확인
    'kuksiwon' => [
        'url' => env('KUKSIWON_API_URL', ''),
        'api_key' => env('KUKSIWON_API_KEY', ''),
    ],

    // 민간자격정보서비스(한국직업능력연구원, pqi.or.kr) — 산후관리사·간병사 등 민간자격 진위확인
    'pqi' => [
        'url' => env('PQI_API_URL', ''),
        'api_key' => env('PQI_API_KEY', ''),
    ],

    // 국민건강보험공단 (장기요양 한도 조회)
    'nhis' => [
        'url' => env('NHIS_API_URL'),
        'api_key' => env('NHIS_API_KEY'),
    ],

    // PG사
    'pg' => [
        'provider' => env('PG_PROVIDER', 'kg_inicis'),
        'mid' => env('PG_MID'),
        'api_key' => env('PG_API_KEY'),
        'sign_key' => env('PG_SIGN_KEY'),
        // 토스페이먼츠(S4) — 테스트 키(test_)는 샌드박스. PG_LIVE=true 면 EXTERNAL_STUB 과 별개로 실제 호출
        'live' => env('PG_LIVE', false),
        'toss_client_key' => env('TOSS_CLIENT_KEY'),
        'toss_secret_key' => env('TOSS_SECRET_KEY'),
    ],

    // 국세청 홈택스 (원천징수)
    'hometax' => [
        'url' => env('HOMETAX_API_URL', ''),
        'biz_no' => env('HOMETAX_BIZ_NO', ''),
        'api_key' => env('HOMETAX_API_KEY', ''),
    ],

    // FCM
    'fcm' => [
        'server_key' => env('FCM_SERVER_KEY'),
        'sender_id' => env('FCM_SENDER_ID'),
    ],

    // AI 마이크로서비스 (Python FastAPI)
    'ai' => [
        'url' => env('AI_SERVICE_URL', 'http://localhost:8001'),
        'base_url' => env('AI_SERVICE_URL', 'http://localhost:8001'),
        'token' => env('AI_SERVICE_TOKEN'),
        'timeout' => 30,
    ],

    // SBA 사회서비스 전자바우처 (Phase 2 산후)
    'sba' => [
        'base_url' => env('SBA_BASE_URL', 'https://api.socialservice.go.kr/v1'),
        'api_key'  => env('SBA_API_KEY', ''),
    ],
    // 가격 레이어 (적정 간병비 산출 / 역경매)
    'pricing' => [
        'min_hourly' => (float) env('PRICING_MIN_HOURLY', 10030), // 법정 최저시급 하한
        // 공휴일(YYYY-MM-DD) 목록 — 지정 시 holiday_mult 적용(일요일은 자동)
        'holidays' => array_filter(explode(',', env('PRICING_HOLIDAYS', ''))),
        // 역경매: 입찰가 있는 후보를 보호자가 선택하면 즉시 확정(입찰=확약)
        'auction_enabled' => (bool) env('PRICING_AUCTION_ENABLED', true),
    ],

    // 지오코딩 (주소→좌표). 키 없으면 Nominatim(OSM)로 폴백.
    'geocoding' => [
        'provider' => env('GEOCODING_PROVIDER', 'nominatim'),
        'kakao_key' => env('KAKAO_REST_API_KEY', ''),
        'vworld_key' => env('VWORLD_API_KEY', ''),
        'nominatim_ua' => env('GEOCODING_UA', 'CareAnd-Geocoder/1.0 (admin@aiclaude.kr)'),
    ],
];
