<?php

namespace App\Services;

use Symfony\Component\Process\Process;

/**
 * TOTP(RFC 6238: HMAC-SHA1, 30초, 6자리) — 관리자 2단계 인증(2026-09-28, 구현계획 S2).
 * 외부 패키지를 받을 수 없는 서버라 직접 구현한다. Google Authenticator·Microsoft Authenticator 호환.
 * QR 은 서버의 qrencode(hisense 와 같은 방식)로 SVG 를 만든다 — 비밀키가 외부 QR 서비스로 나가지 않게.
 */
class TotpService
{
    private const B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generateSecret(int $bytes = 20): string
    {
        return $this->base32Encode(random_bytes($bytes));
    }

    /** 코드가 맞으면 사용한 시간 단계(timestep)를, 틀리면 null. 앞뒤 1단계(±30초) 허용. */
    public function verify(string $secret, string $code, int $window = 1, ?int $now = null): ?int
    {
        if (!preg_match('/^\d{6}$/', $code)) {
            return null;
        }
        $t = intdiv($now ?? time(), 30);
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals($this->codeAt($secret, $t + $i), $code)) {
                return $t + $i;
            }
        }
        return null;
    }

    public function codeAt(string $secret, int $timestep): string
    {
        $key = $this->base32Decode($secret);
        $msg = pack('N*', 0) . pack('N*', $timestep);
        $h = hash_hmac('sha1', $msg, $key, true);
        $o = ord($h[19]) & 0x0f;
        $bin = ((ord($h[$o]) & 0x7f) << 24) | ((ord($h[$o + 1]) & 0xff) << 16) | ((ord($h[$o + 2]) & 0xff) << 8) | (ord($h[$o + 3]) & 0xff);
        return str_pad((string) ($bin % 1000000), 6, '0', STR_PAD_LEFT);
    }

    public function otpauthUri(string $account, string $secret, string $issuer = 'Care& 관리자'): string
    {
        return sprintf('otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=30',
            rawurlencode($issuer), rawurlencode($account), $secret, rawurlencode($issuer));
    }

    /** QR SVG — qrencode 가 없거나 실패하면 null(화면은 설정 키 직접 입력으로 안내). */
    public function qrSvg(string $text): ?string
    {
        try {
            $p = new Process(['qrencode', '-t', 'SVG', '-m', '1', '-s', '6', '-o', '-', $text]);
            $p->setTimeout(5);
            $p->run();
            return $p->isSuccessful() ? $p->getOutput() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function base32Encode(string $data): string
    {
        $bits = '';
        foreach (str_split($data) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::B32[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    private function base32Decode(string $b32): string
    {
        $b32 = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32));
        $bits = '';
        foreach (str_split($b32) as $c) {
            $bits .= str_pad(decbin(strpos(self::B32, $c)), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }
}
