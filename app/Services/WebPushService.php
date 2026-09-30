<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 웹 푸시 발송 (PWA 1단계, CAREN-PWA-01).
 *
 * 서버가 packagist 에 나갈 수 없어(egress 화이트리스트) minishlink/web-push 대신 표준을 직접 구현한다.
 * PHP 기본 openssl 만 쓴다(gmp·bcmath 불필요).
 *  - VAPID(RFC 8292): ES256 JWT 로 서버 신원 증명 → Authorization: vapid t=…, k=…
 *  - 본문 암호화(RFC 8291 + RFC 8188 aes128gcm): ECDH(P-256) + HKDF-SHA256 + AES-128-GCM
 *
 * 푸시 서비스(FCM·Apple·Mozilla)는 404/410 이면 구독이 죽은 것 — 행을 지운다.
 */
class WebPushService
{
    /** P-256 공개키 raw(65바이트) 앞에 붙이는 SubjectPublicKeyInfo DER 머리 */
    private const P256_SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    public function enabled(): bool
    {
        return (bool) config('services.webpush.enabled', true)
            && config('services.webpush.public_key')
            && config('services.webpush.private_key_pem');
    }

    public function publicKey(): ?string
    {
        return config('services.webpush.public_key');
    }

    /**
     * 한 사용자의 모든 구독(폰·PC)에 보낸다. 성공 건수를 돌려준다.
     * $message = ['title' => ..., 'body' => ..., 'url' => '/app/...', 'tag' => ..., 'urgent' => bool]
     */
    public function sendToUser(int $userId, array $message): int
    {
        if (!$this->enabled()) {
            return 0;
        }
        $subs = DB::table('push_subscriptions')->where('user_id', $userId)->get();
        $ok = 0;
        foreach ($subs as $sub) {
            $status = $this->send($sub->endpoint, $sub->p256dh, $sub->auth, $message);
            if ($status >= 200 && $status < 300) {
                $ok++;
                DB::table('push_subscriptions')->where('id', $sub->id)
                    ->update(['failures' => 0, 'last_success_at' => now(), 'updated_at' => now()]);
            } elseif ($status === 404 || $status === 410) {
                DB::table('push_subscriptions')->where('id', $sub->id)->delete();   // 만료·해지된 구독
            } else {
                DB::table('push_subscriptions')->where('id', $sub->id)->increment('failures');
                // 계속 실패하는 구독은 정리(일시 장애로 지우지 않게 넉넉히)
                DB::table('push_subscriptions')->where('id', $sub->id)->where('failures', '>=', 20)->delete();
            }
        }
        return $ok;
    }

    /** 한 구독에 보내고 HTTP 상태 코드를 돌려준다(연결 실패는 0). */
    public function send(string $endpoint, string $p256dh, string $auth, array $message): int
    {
        try {
            $payload = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $body = self::encrypt($payload, self::b64uDecode($p256dh), self::b64uDecode($auth));
            $jwt = $this->vapidJwt($endpoint);

            $res = Http::timeout(10)
                ->withHeaders([
                    'Authorization' => 'vapid t=' . $jwt . ', k=' . $this->publicKey(),
                    'Content-Encoding' => 'aes128gcm',
                    'Content-Type' => 'application/octet-stream',
                    'TTL' => (string) ($message['ttl'] ?? 86400),
                    'Urgency' => !empty($message['urgent']) ? 'high' : 'normal',
                ])
                ->withBody($body, 'application/octet-stream')
                ->post($endpoint);

            if (!$res->successful() && !in_array($res->status(), [404, 410], true)) {
                Log::warning('[WEBPUSH] 발송 실패', ['status' => $res->status(), 'host' => parse_url($endpoint, PHP_URL_HOST), 'body' => mb_substr($res->body(), 0, 200)]);
            }
            return $res->status();
        } catch (\Throwable $e) {
            Log::warning('[WEBPUSH] 발송 예외', ['host' => parse_url($endpoint, PHP_URL_HOST), 'error' => $e->getMessage()]);
            return 0;
        }
    }

    /** VAPID JWT (ES256). aud = 푸시 서비스 origin, 12시간 유효. */
    private function vapidJwt(string $endpoint): string
    {
        $p = parse_url($endpoint);
        $header = self::b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims = self::b64u(json_encode([
            'aud' => $p['scheme'] . '://' . $p['host'],
            'exp' => time() + 12 * 3600,
            'sub' => (string) config('services.webpush.subject'),
        ], JSON_UNESCAPED_SLASHES));
        $input = $header . '.' . $claims;

        $pem = base64_decode((string) config('services.webpush.private_key_pem'));
        $key = openssl_pkey_get_private($pem);
        if (!$key || !openssl_sign($input, $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('VAPID 서명 실패 — VAPID_PRIVATE_KEY_PEM 확인');
        }
        return $input . '.' . self::b64u(self::derToRaw($der));
    }

    /**
     * RFC 8291 aes128gcm 암호화. 반환 = 헤더(salt16 | rs4 | idlen1 | keyid65) + 암호문(태그 포함).
     * public static — 테스트에서 복호화 짝과 맞춰 볼 수 있게.
     */
    public static function encrypt(string $plaintext, string $uaPublic, string $authSecret, ?string $salt = null, $asKey = null): string
    {
        if (strlen($uaPublic) !== 65 || strlen($authSecret) !== 16) {
            throw new \InvalidArgumentException('구독 키 길이가 올바르지 않습니다');
        }
        $asKey ??= openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $asPublic = self::rawPublicKey($asKey);

        $uaKey = openssl_pkey_get_public(self::rawToPem($uaPublic));
        $ecdh = openssl_pkey_derive($uaKey, $asKey, 32);
        if ($ecdh === false) {
            throw new \RuntimeException('ECDH 실패');
        }

        // IKM = HKDF(salt=auth, ikm=ecdh, info="WebPush: info\0"||ua_pub||as_pub, 32)
        $ikm = hash_hkdf('sha256', $ecdh, 32, "WebPush: info\0" . $uaPublic . $asPublic, $authSecret);
        $salt ??= random_bytes(16);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);

        // 단일 레코드: 평문 뒤 0x02(마지막 레코드 구분자), 패딩 없음
        $cipher = openssl_encrypt($plaintext . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($cipher === false) {
            throw new \RuntimeException('AES-GCM 암호화 실패');
        }
        return $salt . pack('N', 4096) . chr(65) . $asPublic . $cipher . $tag;
    }

    /** 새 VAPID 키 쌍 — [공개키 base64url(65바이트 raw), 개인키 PEM] */
    public static function generateVapidKeys(): array
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        openssl_pkey_export($key, $pem);
        return [self::b64u(self::rawPublicKey($key)), $pem];
    }

    public static function rawPublicKey($key): string
    {
        $d = openssl_pkey_get_details($key);
        return "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
    }

    public static function rawToPem(string $raw): string
    {
        $der = hex2bin(self::P256_SPKI_PREFIX) . $raw;
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    /** openssl 의 DER ECDSA 서명 → JWS 가 요구하는 R||S(각 32바이트) */
    private static function derToRaw(string $der): string
    {
        $pos = 2;                                   // SEQUENCE, len
        if (ord($der[1]) & 0x80) {
            $pos += ord($der[1]) & 0x7f;
        }
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            $len = ord($der[$pos + 1]);             // INTEGER tag at $pos
            $int = substr($der, $pos + 2, $len);
            $pos += 2 + $len;
            $int = ltrim($int, "\0");
            $out .= str_pad($int, 32, "\0", STR_PAD_LEFT);
        }
        return $out;
    }

    public static function b64u(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function b64uDecode(string $s): string
    {
        return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
    }
}
