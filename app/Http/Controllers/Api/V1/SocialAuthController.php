<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\OAuth\SocialAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * 카카오·구글 로그인 — 기능 1·33 (2026-09-28, 구현계획 S4).
 *   GET  /v1/auth/oauth/providers            쓸 수 있는 제공자(앱 키가 있는 것만) — 화면이 버튼 표시 여부로 씀
 *   GET  /v1/auth/oauth/{provider}/url       동의 화면 주소 + 일회용 state(10분)
 *   POST /v1/auth/oauth/{provider}/callback  {code, state} → 로그인 토큰 / 첫 가입이면 signup_required + social_token
 * 계정 연결 원칙: 연결된 소셜 계정이면 로그인. 아니면 제공자가 **인증한** 이메일이 기존 회원 아이디와 같을 때만 연결
 * (확인 안 된 이메일로 남의 계정을 가로채는 것 방지). 관리자 계정은 소셜 로그인 불가(2단계 인증 우회 방지).
 */
class SocialAuthController extends Controller
{
    public function __construct(private SocialAuthService $oauth)
    {
    }

    public function providers(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => collect(SocialAuthService::PROVIDERS)
            ->mapWithKeys(fn ($p) => [$p => $this->oauth->enabled($p)])]);
    }

    public function url(string $provider): JsonResponse
    {
        if (!$this->oauth->enabled($provider)) {
            return response()->json(['success' => false, 'error_code' => 'PROVIDER_DISABLED', 'message' => '지금은 이 방법으로 로그인할 수 없습니다.'], 404);
        }
        $state = Str::random(40);
        Cache::put("oauth:state:{$state}", $provider, 600);
        return response()->json(['success' => true, 'data' => ['url' => $this->oauth->authorizeUrl($provider, $state)]]);
    }

    public function callback(Request $request, string $provider): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:1024'], 'state' => ['required', 'string', 'max:64']]);
        if (!$this->oauth->enabled($provider) || Cache::pull("oauth:state:{$data['state']}") !== $provider) {
            return response()->json(['success' => false, 'error_code' => 'INVALID_STATE', 'message' => '로그인 요청이 만료되었거나 올바르지 않습니다. 다시 시도해 주세요.'], 422);
        }
        try {
            $p = $this->oauth->exchange($provider, $data['code']);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'error_code' => 'OAUTH_FAILED', 'message' => $e->getMessage()], 422);
        }

        $link = DB::table('social_accounts')->where('provider', $provider)->where('provider_user_id', $p['provider_user_id'])->first();
        $user = $link ? User::find($link->user_id) : null;

        // 제공자가 인증한 이메일이 기존 회원 아이디와 같으면 연결
        if (!$user && $p['email'] && $p['email_verified']) {
            $user = User::where('email', $p['email'])->first();
            if ($user && $user->role !== 'admin') {
                DB::table('social_accounts')->insert(['user_id' => $user->id, 'provider' => $provider, 'provider_user_id' => $p['provider_user_id'],
                    'email' => $p['email'], 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        if ($user) {
            if ($user->role === 'admin') {
                return response()->json(['success' => false, 'error_code' => 'ADMIN_SOCIAL_BLOCKED', 'message' => '관리자 계정은 소셜 로그인을 쓸 수 없습니다.'], 403);
            }
            if ($user->status !== 'active' || $user->deleted_at) {
                return response()->json(['success' => false, 'error_code' => 'USER_SUSPENDED', 'message' => '이용할 수 없는 계정입니다.'], 403);
            }
            DB::table('social_accounts')->where('user_id', $user->id)->where('provider', $provider)->update(['last_login_at' => now()]);
            $this->audit($request, $user->id, "auth.social.login.{$provider}");
            $user->load(['guardian', 'caregiver', 'organization', 'admin']);
            $token = JWTAuth::fromUser($user);
            return response()->json([
                'success' => true,
                'user' => new UserResource($user),
                'access_token' => $token,
                'refresh_token' => JWTAuth::customClaims(['exp' => now()->addMinutes(config('jwt.refresh_ttl'))->timestamp, 'type' => 'refresh'])->fromUser($user),
                'token_type' => 'Bearer',
                'expires_in' => config('jwt.ttl') * 60,
            ]);
        }

        // 첫 소셜 가입 — 휴대폰 인증·역할 선택은 기존 가입 화면에서(비밀번호 생략). 30분짜리 가입 토큰
        $socialToken = Str::random(48);
        Cache::put("oauth:signup:{$socialToken}", ['provider' => $provider] + $p, 1800);
        return response()->json(['success' => true, 'signup_required' => true, 'social_token' => $socialToken,
            'profile' => ['provider' => $provider, 'name' => $p['name'], 'email' => $p['email_verified'] ? $p['email'] : null]]);
    }

    private function audit(Request $request, int $userId, string $action): void
    {
        try {
            AuditLog::create(['actor_id' => $userId, 'action' => $action, 'entity_type' => 'auth', 'entity_id' => $userId,
                'ip_address' => $request->ip(), 'user_agent' => substr((string) $request->userAgent(), 0, 500)]);
        } catch (\Throwable) {}
    }
}
