<?php

namespace App\Http\Middleware;

use App\Support\AdminRbac;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 관리자 API 영역별 권한 검사 — role:admin 다음에 둔다(2026-09-28, 구현계획 S2-3).
 * 조회(GET·HEAD)는 read, 나머지는 write 권한. 거부는 403 FORBIDDEN_PERMISSION(감사로그에 상태 403으로 남는다).
 */
class AdminPermission
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $level = $user?->admin?->permission_level;
        $area = AdminRbac::areaOf($request->path());
        $write = !in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true);

        if (!AdminRbac::can($level, $area, $write)) {
            $label = config("admin_rbac.areas.{$area}.label", $area);
            $lv = config("admin_rbac.levels.{$level}", $level ?: '등급 없음');
            return response()->json([
                'success' => false,
                'error_code' => 'FORBIDDEN_PERMISSION',
                'message' => "{$lv} 권한으로는 「{$label}」" . ($write ? ' 변경' : ' 조회') . '을(를) 할 수 없습니다.',
            ], 403);
        }

        return $next($request);
    }
}
