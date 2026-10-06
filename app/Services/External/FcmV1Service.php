<?php

namespace App\Services\External;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * FCM HTTP v1 발송기 (CAREN-APP-01 D4, 2026-10-07)
 *
 * 기존 FcmService(구형 fcm/send)는 손대지 않고, FCM_V1_ENABLED=true 일 때만 컨테이너가 이 클래스를 대신 꽂는다
 * (AppServiceProvider). 호출하는 쪽(NotificationService·관리자 메시지)은 그대로 send() 를 부른다.
 *
 *  - 인증: 서비스 계정 JSON 으로 RS256 JWT 를 만들어 액세스 토큰과 교환, 55분 캐시.
 *    이 서버에선 oauth2.googleapis.com 이 막혀 있어 기본 교환 주소를 www.googleapis.com/oauth2/v4/token 으로 둔다(10-07 실측).
 *  - EXTERNAL_STUB 을 보지 않는다 — PG·알림톡 등은 스텁인 채로 푸시만 실발송할 수 있게 자체 플래그로만 켠다.
 *  - FCM_V1_DRY_RUN=true 면 validate_only 로 보내 실제 기기엔 안 뜨고 형식·토큰 유효성만 확인한다.
 *  - 기기에서 지워진 토큰(UNREGISTERED)은 users.fcm_token 을 비워 다음부터 보내지 않는다.
 */
class FcmV1Service extends FcmService
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';
    private const CACHE_KEY = 'fcm-v1:access-token';

    private ?array $credentials = null;

    public function __construct(
        private readonly string $credentialsPath,
        private readonly ?string $projectId = null,
        private readonly string $tokenUrl = 'https://www.googleapis.com/oauth2/v4/token',
        private readonly bool $dryRun = false,
        private readonly string $androidChannel = 'caren_default',
    ) {
        parent::__construct(serverKey: '');
    }

    /**
     * @return array{success: bool, message_id: ?string, error: ?string}
     */
    public function send(string $fcmToken, string $title, string $body, array $data = []): array
    {
        try {
            $projectId = $this->projectId ?: ($this->credentials()['project_id'] ?? null);
            if (!$projectId) {
                return ['success' => false, 'message_id' => null, 'error' => 'FCM v1 project_id not configured'];
            }

            $message = [
                'token' => $fcmToken,
                'notification' => ['title' => $title, 'body' => $body],
                'data' => self::stringMap($data),
                'android' => [
                    'priority' => 'high',
                    'notification' => ['channel_id' => $this->androidChannel, 'sound' => 'default'],
                ],
                'apns' => [
                    'headers' => ['apns-priority' => '10'],
                    'payload' => ['aps' => ['sound' => 'default']],
                ],
            ];
            $payload = ['message' => $message];
            if ($this->dryRun) {
                $payload['validate_only'] = true;
            }

            $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";
            $response = $this->post($url, $payload, $this->accessToken());
            if ($response->status() === 401) {
                // 캐시된 토큰이 먼저 만료·폐기된 경우 한 번만 새로 받아 재시도
                Cache::forget(self::CACHE_KEY);
                $response = $this->post($url, $payload, $this->accessToken());
            }

            if ($response->successful()) {
                return ['success' => true, 'message_id' => $response->json('name'), 'error' => null];
            }

            $code = self::errorCode($response->json());
            if ($code === 'UNREGISTERED') {
                DB::table('users')->where('fcm_token', $fcmToken)->update(['fcm_token' => null]);
            }
            $error = $code ?: ('HTTP ' . $response->status());
            Log::warning('FCM v1 발송 실패', [
                'token' => substr($fcmToken, 0, 20) . '...',
                'status' => $response->status(),
                'error' => $error,
                'message' => $response->json('error.message'),
            ]);
            return ['success' => false, 'message_id' => null, 'error' => $error];
        } catch (\Throwable $e) {
            Log::error('FCM v1 발송 실패', [
                'token' => substr($fcmToken, 0, 20) . '...',
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'message_id' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * v1 에는 다중 발송 API 가 없어 한 건씩 보낸다(지금 호출하는 곳은 없음, 상위 클래스와 같은 모양 유지).
     */
    public function sendMulticast(array $fcmTokens, string $title, string $body, array $data = []): array
    {
        $sent = 0;
        $failed = 0;
        foreach (array_unique(array_filter($fcmTokens)) as $token) {
            $this->send($token, $title, $body, $data)['success'] ? $sent++ : $failed++;
        }
        return ['success' => $failed === 0 || $sent > 0, 'sent' => $sent, 'failed' => $failed];
    }

    /** 서비스 계정으로 액세스 토큰을 받는다(55분 캐시). fcm:v1-check 명령도 이걸로 교환 가능 여부를 본다. */
    public function accessToken(): string
    {
        return Cache::remember(self::CACHE_KEY, 55 * 60, function () {
            $cred = $this->credentials();
            $now = time();
            $header = self::b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $claims = self::b64url(json_encode([
                'iss' => $cred['client_email'],
                'scope' => self::SCOPE,
                // aud 는 서비스 계정 JSON 의 token_uri 그대로(교환 주소를 우회해도 aud 는 원래 값이어야 함)
                'aud' => $cred['token_uri'] ?? 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
            ]));
            $key = openssl_pkey_get_private($cred['private_key']);
            if ($key === false || !openssl_sign("{$header}.{$claims}", $sig, $key, OPENSSL_ALGO_SHA256)) {
                throw new \RuntimeException('FCM v1 서비스 계정 개인키로 서명하지 못했습니다.');
            }
            $jwt = "{$header}.{$claims}." . self::b64url($sig);

            $res = Http::asForm()->timeout(10)->post($this->tokenUrl, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);
            $token = $res->json('access_token');
            if (!$res->successful() || !$token) {
                throw new \RuntimeException('FCM v1 액세스 토큰 교환 실패: HTTP ' . $res->status() . ' ' . ($res->json('error') ?? ''));
            }
            return $token;
        });
    }

    private function post(string $url, array $payload, string $accessToken)
    {
        return Http::timeout(10)->withToken($accessToken)->acceptJson()->post($url, $payload);
    }

    private function credentials(): array
    {
        if ($this->credentials !== null) {
            return $this->credentials;
        }
        if ($this->credentialsPath === '' || !is_readable($this->credentialsPath)) {
            throw new \RuntimeException('FCM v1 서비스 계정 파일을 읽을 수 없습니다(FCM_V1_CREDENTIALS).');
        }
        $cred = json_decode((string) file_get_contents($this->credentialsPath), true);
        if (!is_array($cred) || empty($cred['client_email']) || empty($cred['private_key'])) {
            throw new \RuntimeException('FCM v1 서비스 계정 파일 형식이 올바르지 않습니다.');
        }
        return $this->credentials = $cred;
    }

    /** v1 data 는 문자열→문자열만 허용 — 숫자·불리언은 문자열로, 배열은 JSON 으로. null 은 뺀다. */
    public static function stringMap(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            if ($v === null) {
                continue;
            }
            $out[(string) $k] = match (true) {
                is_bool($v) => $v ? 'true' : 'false',
                is_scalar($v) => (string) $v,
                default => json_encode($v, JSON_UNESCAPED_UNICODE),
            };
        }
        return $out;
    }

    /** 오류 응답의 details[].errorCode(UNREGISTERED 등), 없으면 error.status */
    private static function errorCode(?array $json): ?string
    {
        foreach ($json['error']['details'] ?? [] as $d) {
            if (!empty($d['errorCode'])) {
                return $d['errorCode'];
            }
        }
        return $json['error']['status'] ?? null;
    }

    private static function b64url(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }
}
