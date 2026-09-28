<?php

namespace App\Services\External;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 알리고 — 카카오 알림톡 + SMS/LMS (사업계획서 기능 6·32, 2026-09-28 구현계획 S4)
 *
 * - 알림톡: https://kakaoapi.aligo.in/akv10/alimtalk/send/ (failover=Y 면 카카오 실패 시 알리고가 SMS/LMS 로 대체 발송)
 * - SMS/LMS: https://apis.aligo.in/send/
 * - 실제 발송은 ALIMTALK_LIVE=true + 키 전부 있을 때만. 아니면 [ALIMTALK-DEV] 로그만 남기고 status=stub.
 *   (EXTERNAL_STUB 과 별개 — PG_LIVE 와 같은 방식. 인증번호 SMS 는 기존 OtpService 가 EXTERNAL_STUB 로 따로 제어)
 * - ⚠ 알림톡 규격은 알리고 akv10 공개 규격 기준으로 작성했다. 계약 후 실제 키로 1회 발송해 응답(code=0)을 확인할 것.
 */
class AligoService
{
    private const ALIMTALK_URL = 'https://kakaoapi.aligo.in/akv10/alimtalk/send/';
    private const TOKEN_URL = 'https://kakaoapi.aligo.in/akv10/token/create/30/d/';
    private const SMS_URL = 'https://apis.aligo.in/send/';

    public function alimtalkLive(): bool
    {
        return (bool) config('services.alimtalk.live')
            && config('services.sms.api_key') && config('services.sms.user_id')
            && config('services.sms.sender') && config('services.alimtalk.sender_key');
    }

    /**
     * 알림톡 1건. $tplCode 는 심사 통과 후 알리고가 준 코드(TA_xxxx 등).
     * $message 는 승인된 템플릿 문안과 변수만 다르고 글자까지 같아야 한다(다르면 카카오가 거절 → 대체 SMS).
     *
     * @return array{status:string, code:?string, message:?string}
     */
    public function sendAlimtalk(string $phone, string $tplCode, string $subject, string $message,
                                 ?array $button, string $fallbackText, array $log = []): array
    {
        if (!$this->alimtalkLive() || $tplCode === '') {
            Log::info('[ALIMTALK-DEV] ' . self::maskPhone($phone) . " {$subject} — " . str_replace("\n", ' / ', $message));
            return $this->record($log + ['channel' => 'alimtalk'], $phone, 'stub', null, $tplCode === '' ? '템플릿 코드 미등록' : '실발송 꺼짐');
        }

        $params = [
            'apikey' => config('services.sms.api_key'),
            'userid' => config('services.sms.user_id'),
            'senderkey' => config('services.alimtalk.sender_key'),
            'tpl_code' => $tplCode,
            'sender' => config('services.sms.sender'),
            'receiver_1' => preg_replace('/\D/', '', $phone),
            'subject_1' => mb_substr($subject, 0, 40),
            'message_1' => $message,
            'failover' => 'Y',
            'fsubject_1' => mb_substr($subject, 0, 40),
            'fmessage_1' => $fallbackText,
        ];
        if ($token = $this->token()) {
            $params['token'] = $token;
        }
        if ($button) {
            $params['button_1'] = json_encode(['button' => [[
                'name' => $button['name'], 'linkType' => 'WL', 'linkTypeName' => '웹링크',
                'linkM' => $button['url'], 'linkP' => $button['url'],
            ]]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        try {
            $res = Http::asForm()->timeout(10)->post(self::ALIMTALK_URL, $params);
            $code = (string) ($res->json('code') ?? '');
            $ok = $res->successful() && $code === '0';
            return $this->record($log + ['channel' => 'alimtalk'], $phone, $ok ? 'sent' : 'failed', $code, (string) ($res->json('message') ?? $res->body()));
        } catch (\Throwable $e) {
            return $this->record($log + ['channel' => 'alimtalk'], $phone, 'failed', null, $e->getMessage());
        }
    }

    /**
     * SMS(90바이트 이하) / LMS(초과) 1건 — 알림톡 요청 자체가 실패했을 때 대체 발송용.
     *
     * @return array{status:string, code:?string, message:?string}
     */
    public function sendText(string $phone, string $text, string $title = '[케어앤]', array $log = []): array
    {
        $channel = strlen((string) @iconv('UTF-8', 'EUC-KR//IGNORE', $text)) > 90 ? 'lms' : 'sms';
        if (!$this->alimtalkLive()) {
            Log::info('[SMS-DEV] ' . self::maskPhone($phone) . ' ' . str_replace("\n", ' / ', $text));
            return $this->record($log + ['channel' => $channel], $phone, 'stub', null, '실발송 꺼짐');
        }
        $params = [
            'key' => config('services.sms.api_key'),
            'user_id' => config('services.sms.user_id'),
            'sender' => config('services.sms.sender'),
            'receiver' => preg_replace('/\D/', '', $phone),
            'msg' => $text,
            'msg_type' => strtoupper($channel),
        ];
        if ($channel === 'lms') {
            $params['title'] = mb_substr($title, 0, 40);
        }
        try {
            $res = Http::asForm()->timeout(10)->post(self::SMS_URL, $params);
            $code = (string) ($res->json('result_code') ?? '');
            $ok = $res->successful() && $code === '1';
            return $this->record($log + ['channel' => $channel], $phone, $ok ? 'sent' : 'failed', $code, (string) ($res->json('message') ?? $res->body()));
        } catch (\Throwable $e) {
            return $this->record($log + ['channel' => $channel], $phone, 'failed', null, $e->getMessage());
        }
    }

    /** 알림톡 토큰(유효 30일) — ALIMTALK_TOKEN 이 있으면 그대로, 없으면 발급해 25일 캐시. 실패해도 발송은 시도 */
    private function token(): ?string
    {
        if ($t = config('services.alimtalk.token')) {
            return $t;
        }
        return Cache::remember('aligo:alimtalk:token', now()->addDays(25), function () {
            try {
                $res = Http::asForm()->timeout(10)->post(self::TOKEN_URL, [
                    'apikey' => config('services.sms.api_key'),
                    'userid' => config('services.sms.user_id'),
                ]);
                return $res->json('token') ?: null;
            } catch (\Throwable $e) {
                Log::warning('알리고 토큰 발급 실패: ' . $e->getMessage());
                return null;
            }
        });
    }

    private function record(array $log, string $phone, string $status, ?string $code, ?string $message): array
    {
        try {
            DB::table('message_logs')->insert([
                'user_id' => $log['user_id'] ?? null,
                'notification_id' => $log['notification_id'] ?? null,
                'template' => mb_substr($log['template'] ?? '-', 0, 40),
                'channel' => $log['channel'] ?? 'alimtalk',
                'status' => $status,
                'phone_masked' => self::maskPhone($phone),
                'result_code' => $code === null ? null : mb_substr($code, 0, 20),
                'result_message' => $message === null ? null : mb_substr($message, 0, 255),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('발송 기록 저장 실패: ' . $e->getMessage());
        }
        if ($status === 'failed') {
            Log::warning('알리고 발송 실패', ['template' => $log['template'] ?? null, 'code' => $code, 'message' => $message]);
        }
        return ['status' => $status, 'code' => $code, 'message' => $message];
    }

    public static function maskPhone(string $phone): string
    {
        $d = preg_replace('/\D/', '', $phone);
        return strlen($d) >= 8 ? substr($d, 0, 3) . '-****-' . substr($d, -4) : '****';
    }
}
