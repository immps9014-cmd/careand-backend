<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * 비로그인 공개 웹(careand.aiclaude.kr/www)용 읽기 전용 엔드포인트.
 *
 * 정책(2026-06-28): 돌봄전문가 개별 노출은 회원 전용으로 전환했다. 비로그인 공개는
 * 개인 식별이 불가능한 "집계 신뢰지표"(stats)만 제공한다. 인력 목록/상세는
 * 로그인 후 회원 웹(/app/caregivers)에서만 조회한다.
 */
class PublicController extends Controller
{
    /** 홈 신뢰지표(집계) — 개별 인력 정보 없음 */
    public function stats(): JsonResponse
    {
        $agg = DB::table('caregivers')
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->selectRaw('COUNT(*) as caregivers, COALESCE(SUM(completed_sessions),0) as sessions, COALESCE(AVG(NULLIF(rating_avg,0)),0) as rating')
            ->first();

        $regions = DB::table('caregivers')
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->selectRaw("SUBSTRING_INDEX(base_address,' ',2) as region, COUNT(*) as cnt")
            ->groupBy('region')
            ->orderByDesc('cnt')
            ->limit(8)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'caregivers' => (int) $agg->caregivers,
                'completed_sessions' => (int) $agg->sessions,
                'rating_avg' => round((float) $agg->rating, 1),
                'regions' => $regions,
            ],
        ]);
    }
}
