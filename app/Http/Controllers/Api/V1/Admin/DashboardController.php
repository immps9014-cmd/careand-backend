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
     * GET /v1/admin/dashboard/breakdown?period=today|week|month&branch_id=&domain=
     * 지점·도메인·기간 필터 현황(기능 17, 2026-09-28 S5). 지점 = 배정된 돌봄전문가의 소속 지점(caregivers.branch_id).
     * 요청 건수는 지점 필터가 걸리면 「그 지점 인력이 후보로 오른 요청」 기준. 가동률 = 기간 중 돌봄 1회 이상 한 활성 인력 / 활성 인력.
     */
    public function breakdown(Request $request): JsonResponse
    {
        $v = $request->validate([
            'period' => 'nullable|in:today,week,month',
            'branch_id' => 'nullable|integer',
            'domain' => 'nullable|string|max:30',
        ]);
        $period = $v['period'] ?? 'week';
        $from = match ($period) {
            'today' => now('Asia/Seoul')->startOfDay()->utc(),
            'month' => now('Asia/Seoul')->startOfMonth()->utc(),
            default => now('Asia/Seoul')->startOfWeek()->utc(),
        };
        $branch = $v['branch_id'] ?? null;
        $domain = $v['domain'] ?? null;

        $matchQ = fn () => DB::table('matches as m')
            ->join('match_requests as r', 'r.id', '=', 'm.request_id')
            ->join('caregivers as c', 'c.id', '=', 'm.caregiver_id')
            ->when($branch, fn ($q) => $q->where('c.branch_id', $branch))
            ->when($domain, fn ($q) => $q->where('r.service_domain', $domain));

        $requests = DB::table('match_requests as r')->where('r.created_at', '>=', $from)
            ->when($domain, fn ($q) => $q->where('r.service_domain', $domain))
            ->when($branch, fn ($q) => $q->whereExists(fn ($e) => $e->from('match_candidates as mc')
                ->join('caregivers as c', 'c.id', '=', 'mc.caregiver_id')
                ->whereColumn('mc.request_id', 'r.id')->where('c.branch_id', $branch)));
        $reqTotal = (clone $requests)->count();
        $reqMatched = (clone $requests)->where('r.status', 'matched')->count();

        $revenue = (int) $matchQ()->join('payments as p', 'p.match_id', '=', 'm.id')
            ->where('p.status', 'paid')->where('p.paid_at', '>=', $from)->sum('p.total_amount');
        $sessionsDone = DB::table('care_sessions as cs')->join('matches as m', 'm.id', '=', 'cs.match_id')
            ->join('match_requests as r', 'r.id', '=', 'm.request_id')->join('caregivers as c', 'c.id', '=', 'm.caregiver_id')
            ->where('cs.status', 'completed')->where('cs.actual_end', '>=', $from)
            ->when($branch, fn ($q) => $q->where('c.branch_id', $branch))
            ->when($domain, fn ($q) => $q->where('r.service_domain', $domain));
        $sessionCount = (clone $sessionsDone)->count();
        $workedCaregivers = (clone $sessionsDone)->distinct()->count('m.caregiver_id');

        $activeCg = DB::table('caregivers')->where('status', 'active')->whereNull('deleted_at')
            ->when($branch, fn ($q) => $q->where('branch_id', $branch))
            ->when($domain, fn ($q) => $q->whereRaw('FIND_IN_SET(?, service_domains)', [$domain]));
        $activeCount = (clone $activeCg)->count();
        $ratingAvg = (clone $activeCg)->where('rating_count', '>', 0)->avg('rating_avg');

        $byBranch = DB::table('branches as b')->orderBy('b.id')->get(['b.id', 'b.name'])->map(function ($b) use ($from, $domain) {
            $rev = (int) DB::table('payments as p')->join('matches as m', 'm.id', '=', 'p.match_id')
                ->join('match_requests as r', 'r.id', '=', 'm.request_id')->join('caregivers as c', 'c.id', '=', 'm.caregiver_id')
                ->where('c.branch_id', $b->id)->where('p.status', 'paid')->where('p.paid_at', '>=', $from)
                ->when($domain, fn ($q) => $q->where('r.service_domain', $domain))->sum('p.total_amount');
            $cg = DB::table('caregivers')->where('status', 'active')->whereNull('deleted_at')->where('branch_id', $b->id)->count();
            return ['branch_id' => $b->id, 'name' => $b->name, 'revenue' => $rev, 'active_caregivers' => $cg];
        });
        $unassignedCg = DB::table('caregivers')->where('status', 'active')->whereNull('deleted_at')->whereNull('branch_id')->count();

        $byDomain = DB::table('match_requests as r')->where('r.created_at', '>=', $from)
            ->when($branch, fn ($q) => $q->whereExists(fn ($e) => $e->from('match_candidates as mc')
                ->join('caregivers as c', 'c.id', '=', 'mc.caregiver_id')
                ->whereColumn('mc.request_id', 'r.id')->where('c.branch_id', $branch)))
            ->select('r.service_domain', DB::raw('COUNT(*) as requests'), DB::raw("SUM(r.status = 'matched') as matched"))
            ->groupBy('r.service_domain')->get()->keyBy('service_domain');
        $domains = array_keys(config('service_domains', []));

        return response()->json(['success' => true, 'data' => [
            'filter' => ['period' => $period, 'from' => $from->toIso8601String(), 'branch_id' => $branch, 'domain' => $domain],
            'requests' => $reqTotal,
            'matched' => $reqMatched,
            'match_rate' => $reqTotal ? round($reqMatched / $reqTotal * 100, 1) : null,
            'revenue' => $revenue,
            'sessions_completed' => $sessionCount,
            'active_caregivers' => $activeCount,
            'utilization' => $activeCount ? round(min($workedCaregivers, $activeCount) / $activeCount * 100, 1) : null,
            'rating_avg' => $ratingAvg !== null ? round((float) $ratingAvg, 2) : null,
            'by_branch' => $byBranch,
            'unassigned_caregivers' => $unassignedCg,
            'by_domain' => collect($domains)->map(fn ($d) => [
                'domain' => $d,
                'label' => \App\Support\ServiceDomains::label($d),
                'requests' => (int) ($byDomain[$d]->requests ?? 0),
                'matched' => (int) ($byDomain[$d]->matched ?? 0),
            ])->values(),
        ], 'updated_at' => now()->toIso8601String()]);
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
     * GET /v1/admin/dashboard/business-kpi?period=all|30d|7d
     * 사업계획서(협약) 핵심성과지표 3종 — 구축 전 값·목표와 함께 현재 측정값을 돌려준다(2026-09-28, 구현계획 S1).
     *   1. 정상처리율(STT 케어용어 인식률) %  — 최신 stt_evaluations (평가 스크립트 결과)
     *   2. 서비스 리드타임(매칭 소요시간) h    — 매칭 요청 등록 → 매칭 확정
     *   3. 업무처리 리드타임(케어일지 작성시간) min — 일지 작성 시작 → 보호자 전송(최초 승인)
     * 표본 수(n)를 같이 준다 — 표본이 적거나 0이면 화면에서 "측정 전"으로 보여야 한다.
     */
    public function businessKpi(Request $request): JsonResponse
    {
        $period = $request->query('period', 'all');
        $since = match ($period) {
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            default => null,
        };

        $stt = DB::table('stt_evaluations')->orderByDesc('evaluated_at')->orderByDesc('id')->first();

        $match = DB::table('matches as m')
            ->join('match_requests as r', 'r.id', '=', 'm.request_id')
            ->when($since, fn ($q) => $q->where('r.created_at', '>=', $since))
            ->selectRaw('COUNT(*) AS n, AVG(TIMESTAMPDIFF(SECOND, r.created_at, m.created_at)) AS avg_sec')
            ->first();

        $log = DB::table('care_sessions')
            ->whereNotNull('log_started_at')->whereNotNull('log_sent_at')
            ->when($since, fn ($q) => $q->where('log_sent_at', '>=', $since))
            ->selectRaw('COUNT(*) AS n, AVG(TIMESTAMPDIFF(SECOND, log_started_at, log_sent_at)) AS avg_sec')
            ->first();

        return response()->json([
            'success' => true,
            'period' => $period,
            'data' => [
                [
                    'key' => 'stt_term_rate',
                    'name' => '정상처리율',
                    'label' => 'STT 케어용어 인식률',
                    'unit' => '%',
                    'weight' => 0.4,
                    'baseline' => null,
                    'target' => 90,
                    'direction' => 'up',
                    'value' => $stt ? (float) $stt->rate : null,
                    'n' => $stt ? (int) $stt->terms_total : 0,
                    'n_label' => $stt ? "음성 {$stt->samples}건 · 용어 {$stt->terms_total}개" : null,
                    'measured_at' => $stt->evaluated_at ?? null,
                    'note' => $stt ? "{$stt->term_set} 기준 · {$stt->stt_engine}" : '평가 데이터 없음 — STT 평가 스크립트 실행 필요',
                ],
                [
                    'key' => 'match_lead_hours',
                    'name' => '서비스 리드타임',
                    'label' => '매칭 소요시간',
                    'unit' => 'h',
                    'weight' => 0.3,
                    'baseline' => 48,
                    'target' => 0.5,
                    'direction' => 'down',
                    'value' => $match->n ? round($match->avg_sec / 3600, 2) : null,
                    'n' => (int) $match->n,
                    'n_label' => "매칭 {$match->n}건",
                    'measured_at' => now()->toIso8601String(),
                    'note' => '매칭 요청 등록 → 매칭 확정',
                ],
                [
                    'key' => 'care_log_minutes',
                    'name' => '업무처리 리드타임',
                    'label' => '케어일지 작성시간',
                    'unit' => 'min',
                    'weight' => 0.3,
                    'baseline' => 30,
                    'target' => 5,
                    'direction' => 'down',
                    'value' => $log->n ? round($log->avg_sec / 60, 1) : null,
                    'n' => (int) $log->n,
                    'n_label' => "일지 {$log->n}건",
                    'measured_at' => now()->toIso8601String(),
                    'note' => '작성 시작 → 보호자 전송(승인) · 2026-09-28 이후 일지부터 측정',
                ],
            ],
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
