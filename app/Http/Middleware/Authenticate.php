<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    /**
     * 정지·탈퇴 계정은 이미 받은 토큰으로도 못 쓴다(2026-10-07).
     * 지금까진 로그인 때만 status 를 봐서, 관리자 정지·블랙리스트 뒤에도 갱신 토큰으로 최대 14일 계속 접속됐다.
     */
    public function handle($request, Closure $next, ...$guards)
    {
        $this->authenticate($request, $guards);

        $user = $request->user();
        if ($user && isset($user->status) && $user->status !== 'active') {
            return response()->json([
                'success' => false,
                'error_code' => 'USER_SUSPENDED',
                'message' => '이용이 정지된 계정입니다.',
            ], 403);
        }

        return $next($request);
    }

    /**
     * Get the path the user should be redirected to when they are not authenticated.
     */
    protected function redirectTo(Request $request): ?string
    {
        // API 전용 백엔드 — 웹 로그인 라우트가 없으므로 리다이렉트하지 않는다.
        // (route('login') 평가 시 RouteNotFoundException 500이 나던 문제)
        return null;
    }
}
