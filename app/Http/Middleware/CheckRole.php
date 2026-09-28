<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * 사용 예: Route::get('/admin/...', ...)->middleware('role:admin');
     *         Route::get('/...', ...)->middleware('role:guardian,caregiver');
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'error_code' => 'UNAUTHENTICATED',
                'message' => '인증이 필요합니다.',
            ], 401);
        }

        if (!in_array($user->role, $roles, true)) {
            return response()->json([
                'success' => false,
                'error_code' => 'FORBIDDEN_ROLE',
                'message' => '해당 작업에 대한 권한이 없습니다.',
            ], 403);
        }

        // 관리자 API 는 2단계 인증을 거친 토큰(mfa 클레임)만 허용 — 도입 전 발급된 토큰·갱신 토큰 차단
        // (사업계획서 3.3 「OTP 미설정 시 관리자 웹 접속 차단」, 2026-09-28 S2). ADMIN_2FA_REQUIRED=false 로 끌 수 있다.
        if (in_array('admin', $roles, true) && $user->role === 'admin' && config('auth.admin_2fa_required', true)) {
            $mfa = false;
            try { $mfa = (bool) \Tymon\JWTAuth\Facades\JWTAuth::parseToken()->getPayload()->get('mfa'); } catch (\Throwable) {}
            if (!$mfa) {
                return response()->json([
                    'success' => false,
                    'error_code' => 'MFA_REQUIRED',
                    'message' => '2단계 인증이 필요합니다. 다시 로그인해 주세요.',
                ], 401);
            }
        }

        return $next($request);
    }
}
