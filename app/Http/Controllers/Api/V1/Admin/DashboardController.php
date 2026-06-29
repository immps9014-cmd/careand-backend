<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AnomalyAlert;
use App\Models\Caregiver;
use App\Models\CareMatch;
use App\Models\MatchRequest;
use App\Models\Payment;
use App\Models\Senior;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * GET /v1/admin/dashboard/kpi
     * 실시간 KPI (5분 캐시)
     */
    public function kpi(Request $request): JsonResponse
    {
        $kpi = Cache::remember('admin:kpi', 300, function () {
            return [
                'matches_in_progress' => CareMatch::whereIn('status', ['confirmed', 'in_progress'])->count(),
                'matches_today' => CareMatch::whereDate('created_at', today())->count(),
                'revenue_today' => (int) Payment::where('status', 'paid')
                    ->whereDate('paid_at', today())
                    ->sum('total_amount'),
                'revenue_this_week' => (int) Payment::where('status', 'paid')
                    ->where('paid_at', '>=', now()->startOfWeek())
                    ->sum('total_amount'),
                'high_alerts_unresolved' => AnomalyAlert::high()->unresolved()->count(),
                'pending_caregivers' => Caregiver::where('status', 'pending')->count(),
                'active_users' => User::where('status', 'active')->count(),
                'active_seniors' => Senior::count(),
                'active_caregivers' => Caregiver::where('status', 'active')->count(),
                'by_domain' => $this->domainBreakdown(),
            ];
        });

        // 전주 대비 증감
        $lastWeekRevenue = Payment::where('status', 'paid')
            ->whereBetween('paid_at', [
                now()->subWeek()->startOfWeek(),
                now()->subWeek()->endOfWeek(),
            ])
            ->sum('total_amount');

        $kpi['revenue_change_pct'] = $lastWeekRevenue > 0
            ? round((($kpi['revenue_this_week'] - $lastWeekRevenue) / $lastWeekRevenue) * 100, 1)
            : 0;

        return response()->json([
            'success' => true,
            'data' => $kpi,
            'updated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * 도메인별 현황 — 진행중 매칭 / 주간 요청 / 주간 매출
     */
    private function domainBreakdown(): array
    {
        $inProgress = DB::table('matches as m')
            ->join('match_requests as r', 'r.id', '=', 'm.request_id')
            ->whereIn('m.status', ['confirmed', 'in_progress'])
            ->select('r.service_domain', DB::raw('COUNT(*) as cnt'))
            ->groupBy('r.service_domain')
            ->pluck('cnt', 'service_domain');

        $requestsWeek = DB::table('match_requests')
            ->where('created_at', '>=', now()->startOfWeek())
            ->select('service_domain', DB::raw('COUNT(*) as cnt'))
            ->groupBy('service_domain')
            ->pluck('cnt', 'service_domain');

        $revenueWeek = DB::table('payments as p')
            ->join('matches as m', 'm.id', '=', 'p.match_id')
            ->join('match_requests as r', 'r.id', '=', 'm.request_id')
            ->where('p.status', 'paid')
            ->where('p.paid_at', '>=', now()->startOfWeek())
            ->select('r.service_domain', DB::raw('SUM(p.total_amount) as amt'))
            ->groupBy('r.service_domain')
            ->pluck('amt', 'service_domain');

        $result = [];
        foreach (['senior', 'postpartum', 'nursing', 'living_support'] as $domain) {
            $result[$domain] = [
                'matches_in_progress' => (int) ($inProgress[$domain] ?? 0),
                'requests_this_week' => (int) ($requestsWeek[$domain] ?? 0),
                'revenue_this_week' => (int) ($revenueWeek[$domain] ?? 0),
            ];
        }

        return $result;
    }

    /**
     * GET /v1/admin/dashboard/hourly-requests
     * 시간대별 매칭 요청 (오늘)
     */
    public function hourlyRequests(Request $request): JsonResponse
    {
        $period = $request->input('period', 'today');

        $query = MatchRequest::query();
        match ($period) {
            'today' => $query->whereDate('created_at', today()),
            '7d' => $query->where('created_at', '>=', now()->subDays(7)),
            '30d' => $query->where('created_at', '>=', now()->subDays(30)),
        };

        $byHour = $query->select(
            DB::raw('HOUR(created_at) as hour'),
            DB::raw('COUNT(*) as count')
        )
            ->groupBy('hour')
            ->pluck('count', 'hour')
            ->toArray();

        $result = [];
        for ($h = 0; $h < 24; $h++) {
            $result[] = [
                'hour' => $h,
                'count' => $byHour[$h] ?? 0,
            ];
        }

        $peak = collect($result)->sortByDesc('count')->first();

        // 매칭 성공률 & 평균 매칭 소요 (period 동일 적용)
        $applyPeriod = function ($q, string $col = 'created_at') use ($period) {
            return match ($period) {
                'today' => $q->whereDate($col, today()),
                '7d' => $q->where($col, '>=', now()->subDays(7)),
                '30d' => $q->where($col, '>=', now()->subDays(30)),
                default => $q,
            };
        };
        $totalReq = $applyPeriod(MatchRequest::query())->count();
        $matchedReq = $applyPeriod(MatchRequest::query())->where('status', 'matched')->count();
        $matchSuccessRate = $totalReq > 0 ? round($matchedReq / $totalReq * 100, 1) : null;

        $avgMatchMinutes = (int) round(
            $applyPeriod(
                DB::table('matches as m')->join('match_requests as r', 'r.id', '=', 'm.request_id'),
                'r.created_at'
            )->avg(DB::raw('TIMESTAMPDIFF(MINUTE, r.created_at, m.created_at)')) ?? 0
        );

        return response()->json([
            'success' => true,
            'period' => $period,
            'data' => $result,
            'peak_hour' => $peak['hour'] ?? null,
            'peak_count' => $peak['count'] ?? 0,
            'total_count' => array_sum(array_column($result, 'count')),
            'match_success_rate' => $matchSuccessRate,
            'avg_match_minutes' => $avgMatchMinutes ?: null,
        ]);
    }

    /**
     * GET /v1/admin/dashboard/regional-demand
     * 지역별 수요/공급 현황
     */
    public function regionalDemand(Request $request): JsonResponse
    {
        // 기본: 대전 지역 기준 (운영 시 주소 파싱 또는 region 컬럼)
        // 여기서는 senior.home_address의 첫 번째 단어로 그룹핑 (단순화)
        $demand = Senior::select(
            DB::raw("SUBSTRING_INDEX(home_address, ' ', 2) as region"),
            DB::raw('COUNT(*) as senior_count')
        )
            ->groupBy('region')
            ->orderByDesc('senior_count')
            ->limit(10)
            ->get();

        $result = $demand->map(function ($row) {
            $caregiverCount = Caregiver::where('status', 'active')
                ->where('base_address', 'like', "{$row->region}%")
                ->count();

            $weeklyRequests = MatchRequest::where('created_at', '>=', now()->subDays(7))
                ->whereHas('senior', fn ($q) => $q->where('home_address', 'like', "{$row->region}%"))
                ->count();

            $supplyRate = $row->senior_count > 0
                ? round(($caregiverCount / $row->senior_count) * 100, 1)
                : 0;

            return [
                'region' => $row->region,
                'senior_count' => $row->senior_count,
                'caregiver_count' => $caregiverCount,
                'weekly_requests' => $weeklyRequests,
                'supply_rate_pct' => $supplyRate,
                'status' => $supplyRate >= 80 ? 'good' : ($supplyRate >= 60 ? 'warning' : 'critical'),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    /**
     * GET /v1/admin/dashboard/recent-alerts
     * 최근 긴급 알림 (high+)
     */
    public function recentAlerts(Request $request): JsonResponse
    {
        $alerts = AnomalyAlert::with('senior:id,name,care_grade')
            ->high()
            ->unresolved()
            ->orderByDesc('detected_at')
            ->limit(10)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $alerts->map(fn ($a) => [
                'id' => $a->id,
                'senior_id' => $a->senior_id,
                'senior_name' => $a->senior->name,
                'risk_type' => $a->risk_type,
                'risk_score' => (float) $a->risk_score,
                'severity' => $a->severity,
                'status' => $a->status,
                'detected_at' => $a->detected_at->toIso8601String(),
                'detected_ago' => $a->detected_at->diffForHumans(),
            ]),
        ]);
    }
}
