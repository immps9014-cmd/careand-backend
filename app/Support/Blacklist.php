<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/** 블랙리스트 번호 판정(기능 19) — 휴대폰 번호는 숫자만 뽑아 APP_KEY 로 HMAC */
class Blacklist
{
    public static function hash(string $phone): string
    {
        return hash_hmac('sha256', preg_replace('/\D/', '', $phone), (string) config('app.key'));
    }

    public static function blocked(string $phone): bool
    {
        return DB::table('member_blacklist')->where('phone_hash', self::hash($phone))->whereNull('released_at')->exists();
    }
}
