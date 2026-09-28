<?php

/*
 * 사업자 정보 — 영수증·처리방침 표기용(2026-09-28 S5). 처리방침(/www/privacy)과 같은 값. 바뀌면 여기만 고친다.
 */
return [
    'name' => env('COMPANY_NAME', '케어앤에듀'),
    'ceo' => env('COMPANY_CEO', '전혜린'),
    'biz_no' => env('COMPANY_BIZ_NO', '106-23-91832'),
    'address' => env('COMPANY_ADDRESS', '경기도 화성시 병점3로 12 2층'),
    'tel' => env('COMPANY_TEL', ''),   // 대표 번호 미확인 — 받으면 .env 로
];
