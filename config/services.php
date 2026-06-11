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

    // 보건복지부 (요양보호사 자격 진위확인)
    'mohw' => [
        'url' => env('MOHW_API_URL'),
        'api_key' => env('MOHW_API_KEY'),
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
    // 지오코딩 (주소→좌표). 키 없으면 Nominatim(OSM)로 폴백.
    'geocoding' => [
        'provider' => env('GEOCODING_PROVIDER', 'nominatim'),
        'kakao_key' => env('KAKAO_REST_API_KEY', ''),
        'vworld_key' => env('VWORLD_API_KEY', ''),
        'nominatim_ua' => env('GEOCODING_UA', 'CareAnd-Geocoder/1.0 (admin@aiclaude.kr)'),
    ],
];
