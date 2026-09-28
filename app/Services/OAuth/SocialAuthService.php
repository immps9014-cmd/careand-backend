<?php

namespace App\Services\OAuth;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * 카카오·구글 OAuth 2.0 (인가 코드 방식) — 기능 1·33 (2026-09-28, 구현계획 S4).
 * Socialite 없이 직접 구현(서버가 외부 패키지를 받을 수 없음).
 * ⚠ 구글 토큰 발급은 www.googleapis.com/oauth2/v4/token 을 쓴다 — 운영 서버에서 oauth2.googleapis.com 이 막혀 있다(2026-09-28 점검).
 * 앱 키가 없으면 enabled() 가 false 라 화면에 버튼이 나오지 않는다.
 */
class SocialAuthService
{
    public const PROVIDERS = ['kakao', 'google'];

    public function enabled(string $provider): bool
    {
        return in_array($provider, self::PROVIDERS, true) && (string) config("services.oauth.{$provider}.client_id") !== '';
    }

    public function redirectUri(string $provider): string
    {
        return rtrim((string) config('services.oauth.redirect_base', 'https://caren.aiclaude.kr/app/auth/callback'), '/') . '/' . $provider;
    }

    public function authorizeUrl(string $provider, string $state): string
    {
        $c = config("services.oauth.{$provider}");
        return match ($provider) {
            'kakao' => 'https://kauth.kakao.com/oauth/authorize?' . http_build_query([
                'response_type' => 'code', 'client_id' => $c['client_id'], 'redirect_uri' => $this->redirectUri('kakao'),
                'state' => $state, 'scope' => 'profile_nickname,account_email',
            ]),
            'google' => 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
                'response_type' => 'code', 'client_id' => $c['client_id'], 'redirect_uri' => $this->redirectUri('google'),
                'state' => $state, 'scope' => 'openid email profile', 'prompt' => 'select_account',
            ]),
            default => throw new RuntimeException('지원하지 않는 로그인 제공자입니다.'),
        };
    }

    /**
     * 인가 코드 → 제공자 프로필.
     * @return array{provider_user_id: string, email: ?string, email_verified: bool, name: ?string}
     */
    public function exchange(string $provider, string $code): array
    {
        $c = config("services.oauth.{$provider}");
        if ($provider === 'kakao') {
            $tok = Http::asForm()->timeout(10)->post('https://kauth.kakao.com/oauth/token', array_filter([
                'grant_type' => 'authorization_code', 'client_id' => $c['client_id'], 'client_secret' => $c['client_secret'] ?: null,
                'redirect_uri' => $this->redirectUri('kakao'), 'code' => $code,
            ]));
            $access = $tok->json('access_token') ?? $this->fail($provider, 'token', $tok);
            $me = Http::withToken($access)->timeout(10)->get('https://kapi.kakao.com/v2/user/me');
            $id = $me->json('id') ?? $this->fail($provider, 'profile', $me);
            $acc = $me->json('kakao_account') ?? [];
            return [
                'provider_user_id' => (string) $id,
                'email' => $acc['email'] ?? null,
                'email_verified' => (bool) (($acc['is_email_valid'] ?? false) && ($acc['is_email_verified'] ?? false)),
                'name' => $acc['profile']['nickname'] ?? ($me->json('properties.nickname')),
            ];
        }
        if ($provider === 'google') {
            $tok = Http::asForm()->timeout(10)->post('https://www.googleapis.com/oauth2/v4/token', [
                'grant_type' => 'authorization_code', 'client_id' => $c['client_id'], 'client_secret' => $c['client_secret'],
                'redirect_uri' => $this->redirectUri('google'), 'code' => $code,
            ]);
            $access = $tok->json('access_token') ?? $this->fail($provider, 'token', $tok);
            $me = Http::withToken($access)->timeout(10)->get('https://openidconnect.googleapis.com/v1/userinfo');
            $sub = $me->json('sub') ?? $this->fail($provider, 'profile', $me);
            return [
                'provider_user_id' => (string) $sub,
                'email' => $me->json('email'),
                'email_verified' => (bool) $me->json('email_verified'),
                'name' => $me->json('name'),
            ];
        }
        throw new RuntimeException('지원하지 않는 로그인 제공자입니다.');
    }

    private function fail(string $provider, string $step, $res): never
    {
        Log::warning("소셜 로그인 실패({$provider}/{$step})", ['http' => $res->status(), 'error' => $res->json('error') ?? $res->json('msg')]);
        throw new RuntimeException('소셜 로그인 확인에 실패했습니다. 다시 시도해 주세요.');
    }
}
