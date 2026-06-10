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

        return $next($request);
    }
}
