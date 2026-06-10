<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use RuntimeException;

class OtpService
{
    private const OTP_TTL_SEC = 180;          // 3분
    private const VERIFY_TOKEN_TTL_SEC = 600; // 10분
    private const RATE_LIMIT_PER_HOUR = 5;    // 시간당 OTP 발송 횟수 제한

    /**
     * OTP 발송 (Aligo SMS 등)
     */
    public function send(string $phone): void
    {
        $key = "otp:rate:{$phone}";
        if (RateLimiter::tooManyAttempts($key, self::RATE_LIMIT_PER_HOUR)) {
            throw new RuntimeException(
                'OTP 발송 횟수를 초과했습니다. 1시간 후 다시 시도해주세요.'
            );
        }
        RateLimiter::hit($key, 3600);

        // 6자리 랜덤 숫자 생성
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        // Redis에 저장 (3분 TTL)
        Cache::put("otp:code:{$phone}", $code, self::OTP_TTL_SEC);

        // 실제 SMS 발송
        if (app()->environment('local', 'testing')) {
            // 로컬에서는 로그로 출력
            Log::info("[OTP-DEV] {$phone} → {$code}");
            return;
        }

        $this->sendSms($phone, $code);
    }

    /**
     * OTP 검증
     */
    public function verify(string $phone, string $code): bool
    {
        $stored = Cache::get("otp:code:{$phone}");
        if ($stored === null) {
            return false;
        }

        if (!hash_equals((string) $stored, $code)) {
            return false;
        }

        // 검증 성공 → OTP 삭제 (1회용)
        Cache::forget("otp:code:{$phone}");
        return true;
    }

    /**
     * 인증 성공 후 회원가입용 임시 토큰 발급
     */
    public function issueVerifyToken(string $phone): string
    {
        $token = Str::random(40);
        Cache::put("otp:verify_token:{$phone}", $token, self::VERIFY_TOKEN_TTL_SEC);
        return $token;
    }

    /**
     * 회원가입 시 토큰 검증
     */
    public function validateVerifyToken(string $phone, string $token): bool
    {
        $stored = Cache::get("otp:verify_token:{$phone}");
        if ($stored === null) {
            return false;
        }

        if (!hash_equals((string) $stored, $token)) {
            return false;
        }

        // 검증 후 토큰 삭제 (1회용)
        Cache::forget("otp:verify_token:{$phone}");
        return true;
    }

    /**
     * 실제 SMS 발송 (Aligo 예시)
     */
    private function sendSms(string $phone, string $code): void
    {
        $provider = config('services.sms.provider', 'aligo');

        if ($provider === 'aligo') {
            $response = Http::asForm()->post('https://apis.aligo.in/send/', [
                'key' => config('services.sms.api_key'),
                'user_id' => config('services.sms.user_id'),
                'sender' => config('services.sms.sender'),
                'receiver' => $phone,
                'msg' => "[케어앤] 인증번호 [{$code}] 입니다. 3분 내에 입력해주세요.",
            ]);

            if (!$response->successful() || ($response->json('result_code') ?? -1) != 1) {
                throw new RuntimeException('SMS 발송 실패: ' . $response->body());
            }
        } else {
            throw new RuntimeException("지원하지 않는 SMS 공급자: {$provider}");
        }
    }
}
