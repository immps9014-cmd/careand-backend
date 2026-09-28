<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * 관리자 — 월간 결산 (기능 23·26, 2026-09-28 S5). RBAC 'reports' 영역(조회 super·지점장·분석가, 재생성 super).
 */
class ReportController extends Controller
{
    /** GET /v1/admin/reports/monthly */
    public function index(): JsonResponse
    {
        $rows = DB::table('monthly_reports')->orderByDesc('month')->limit(24)->get(['month', 'data', 'generated_at']);
        return response()->json(['success' => true, 'data' => $rows->map(fn ($r) => [
            'month' => $r->month, 'generated_at' => $r->generated_at, 'data' => json_decode($r->data, true),
        ])]);
    }

    /** POST /v1/admin/reports/monthly {month} — 수동 (재)생성 */
    public function generate(Request $request): JsonResponse
    {
        $v = $request->validate(['month' => ['required', 'regex:/^\d{4}-\d{2}$/']]);
        if ($v['month'] > now('Asia/Seoul')->format('Y-m')) {
            return response()->json(['success' => false, 'message' => '미래 달은 결산할 수 없어요.'], 422);
        }
        Artisan::call('reports:monthly-close', ['--month' => $v['month'], '--no-notify' => true]);
        $r = DB::table('monthly_reports')->where('month', $v['month'])->first();
        return response()->json(['success' => true, 'message' => "{$v['month']} 결산을 만들었어요." . ($v['month'] === now('Asia/Seoul')->format('Y-m') ? ' (이번 달은 진행 중 값)' : ''),
            'data' => ['month' => $r->month, 'generated_at' => $r->generated_at, 'data' => json_decode($r->data, true)]]);
    }
}
