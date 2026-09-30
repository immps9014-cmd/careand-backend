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
        // 발송 횟수 제한 — 스텁 모드에서도 테스트 번호가 아니면 적용(2026-09-28, S2)
        if (!(config('services.external.stub') && $this->isTestNumber($phone))) {
            $key = "otp:rate:{$phone}";
            if (RateLimiter::tooManyAttempts($key, self::RATE_LIMIT_PER_HOUR)) {
                throw new RuntimeException(
                    'OTP 발송 횟수를 초과했습니다. 1시간 후 다시 시도해주세요.'
                );
            }
            RateLimiter::hit($key, 3600);
        }

        // 6자리 랜덤 숫자 생성
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        // Redis에 저장 (3분 TTL)
        Cache::put("otp:code:{$phone}", $code, self::OTP_TTL_SEC);

        // 실제 SMS 발송
        if (config('services.external.stub')) {
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
        // [개발/테스트 전용] 스텁 모드에서 고정 인증번호 123456 은 **테스트 번호에만** 통과.
        // 예전엔 아무 번호나 통과해 남의 번호로 가입할 수 있었다(2026-09-28, 구현계획 S2).
        // 테스트 번호 = OTP_STUB_TEST_PREFIXES(기본 0100000 → 010-0000-xxxx, 실제 발급되지 않는 대역).
        // 실 SMS 전환(EXTERNAL_STUB=false) 시 자동 비활성화.
        if (config('services.external.stub') && $code === '123456' && $this->isTestNumber($phone)) {
            Cache::forget("otp:code:{$phone}");
            return true;
        }

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

    /** 스텁 모드 고정 인증번호를 허용할 테스트 번호인지 — 숫자만 비교 */
    private function isTestNumber(string $phone): bool
    {
        $digits = preg_replace('/\D/', '', $phone);
        $prefixes = array_filter(array_map('trim', explode(',', (string) config('services.otp.stub_test_prefixes', '0100000'))));
        foreach ($prefixes as $p) {
            if ($p !== '' && str_starts_with($digits, $p)) {
                return true;
            }
        }
        return false;
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
     * 토큰 확인만 하고 지우지 않는다 — 아이디 찾기 뒤 비밀번호 재설정으로 이어갈 때.
     */
    public function peekVerifyToken(string $phone, string $token): bool
    {
        $stored = Cache::get("otp:verify_token:{$phone}");
        return $stored !== null && hash_equals((string) $stored, $token);
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
     * 기관 → 간병인 가입 초대 SMS. 스텁 모드에서는 로그만 출력.
     */
    public function sendCaregiverInvite(string $phone, string $orgName, string $link): void
    {
        $msg = "[케어앤] {$orgName} 기관이 간병인으로 초대했어요. 가입하고 활동을 시작하세요: {$link}";

        if (config('services.external.stub')) {
            Log::info("[INVITE-DEV] {$phone} → {$msg}");
            return;
        }

        $provider = config('services.sms.provider', 'aligo');
        if ($provider !== 'aligo') {
            throw new RuntimeException("지원하지 않는 SMS 공급자: {$provider}");
        }

        $response = Http::asForm()->post('https://apis.aligo.in/send/', [
            'key' => config('services.sms.api_key'),
            'user_id' => config('services.sms.user_id'),
            'sender' => config('services.sms.sender'),
            'receiver' => $phone,
            'msg' => $msg,
        ]);

        if (!$response->successful() || ($response->json('result_code') ?? -1) != 1) {
            throw new RuntimeException('초대 SMS 발송 실패: ' . $response->body());
        }
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
