<?php

namespace App\Support;

use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * 의료정보 암·복호화 — AES-256-GCM, 전용 키 MEDICAL_DATA_KEY (2026-09-28, 구현계획 S2-4).
 * 모델은 App\Casts\MedicalText / MedicalJson 캐스트로, 원시 SQL 로 읽는 곳은 decrypt() 로 쓴다.
 * 이관 전 평문이 섞여 있어도 화면이 깨지지 않게 복호화 실패 시 원문을 돌려준다(경고 로그).
 */
class MedicalCrypto
{
    private static ?Encrypter $enc = null;

    public static function encrypter(): Encrypter
    {
        if (self::$enc === null) {
            $key = (string) config('medical.key');
            if (str_starts_with($key, 'base64:')) {
                $key = base64_decode(substr($key, 7));
            }
            if (strlen($key) !== 32) {
                throw new RuntimeException('MEDICAL_DATA_KEY 가 없거나 32바이트가 아닙니다.');
            }
            self::$enc = new Encrypter($key, config('medical.cipher', 'aes-256-gcm'));
        }
        return self::$enc;
    }

    public static function encrypt(?string $plain): ?string
    {
        return $plain === null ? null : self::encrypter()->encryptString($plain);
    }

    public static function decrypt(?string $stored): ?string
    {
        if ($stored === null || $stored === '') {
            return $stored;
        }
        try {
            return self::encrypter()->decryptString($stored);
        } catch (\Throwable) {
            Log::warning('의료정보 복호화 실패 — 평문(이관 전) 값으로 처리');
            return $stored;
        }
    }

    public static function isEncrypted(?string $stored): bool
    {
        if ($stored === null || $stored === '') {
            return false;
        }
        try {
            self::encrypter()->decryptString($stored);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
