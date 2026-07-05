<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\CareMatch;
use App\Models\MatchRequest;
use App\Models\Payment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 관리자 자연어 인사이트 검색
 *
 * GET /v1/admin/insights/query?q=오늘 매출 현황
 *
 * 규칙기반 의도 분류: 문장에서 지표(회원가입/매칭/매출)와 기간(오늘/이번주/이번달 등)을
 * 추출해 집계 후 구조화된 카드 + 요약 문장으로 반환한다. 프론트가 화면 렌더 + PDF 출력에 사용.
 */
class InsightsController extends Controller
{
    /** 집계는 한국시간(KST) 경계로 계산 후 UTC 저장값과 비교 */
    private const TZ = 'Asia/Seoul';

    private const DOMAIN_LABELS = [
        'senior'         => '방문요양',
        'nursing'        => '병원간병',
        'living_support' => '생활지원',
        'postpartum'     => '산모산후',
        'childcare'      => '아이돌봄',
        'mental_care'    => '마음돌봄',
    ];

    public function query(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q', ''));
        $intent = $this->parse($q);
        [$start, $end, $label] = $this->resolvePeriod($intent['period']);

        $cards = [];
        foreach ($intent['metrics'] as $metric) {
            $card = match ($metric) {
                'signups'  => $this->signupsCard($start, $end),
                'matching' => $this->matchingCard($start, $end),
                'revenue'  => $this->revenueCard($start, $end),
                default    => null,
            };
            if ($card) {
                $cards[] = $card;
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'query' => $q,
                'intent' => $intent,
                'period' => [
                    'key' => $intent['period'],
                    'label' => $label,
                    'start' => $start->toIso8601String(),
                    'end' => $end->toIso8601String(),
                ],
                'generated_at' => now()->toIso8601String(),
                'cards' => $cards,
                'summary' => $this->summary($cards, $label),
            ],
        ]);
    }

    /**
     * 자연어 → 의도(지표 목록 + 기간 키) 규칙기반 분류
     *
     * @return array{metrics: list<string>, period: string, matched: bool}
     */
    private function parse(string $q): array
    {
        $t = str_replace(' ', '', $q);

        // ---- 지표 추출 ----
        $metrics = [];
        if (preg_match('/회원가입|가입|신규회원|신규가입|회원현황|회원수/u', $t)) {
            $metrics[] = 'signups';
        }
        if (preg_match('/매칭|매치|요청|성사/u', $t)) {
            $metrics[] = 'matching';
        }
        if (preg_match('/매출|결제|수익|거래액|정산/u', $t)) {
            $metrics[] = 'revenue';
        }
        $metricsMatched = ! empty($metrics);
        // 전체/요약/현황 만 있거나 지표 미검출 → 3종 종합
        if (! $metricsMatched || preg_match('/전체|종합|요약|현황판|대시보드/u', $t)) {
            $metrics = array_values(array_unique(array_merge(['signups', 'matching', 'revenue'], $metrics)));
        }
        $metrics = array_values(array_unique($metrics));

        // ---- 기간 추출 ----
        $period = 'today';
        if (preg_match('/어제|전일/u', $t)) {
            $period = 'yesterday';
        } elseif (preg_match('/지난주|저번주|전주/u', $t)) {
            $period = 'last_week';
        } elseif (preg_match('/이번주|금주|이주|주간/u', $t)) {
            $period = 'this_week';
        } elseif (preg_match('/지난달|저번달|전월/u', $t)) {
            $period = 'last_month';
        } elseif (preg_match('/이번달|이달|당월|금월|월간/u', $t)) {
            $period = 'this_month';
        } elseif (preg_match('/최근7일|7일|일주일/u', $t)) {
            $period = '7d';
        } elseif (preg_match('/최근30일|30일|한달|1개월/u', $t)) {
            $period = '30d';
        } elseif (preg_match('/오늘|금일|당일/u', $t)) {
            $period = 'today';
        }

        return [
            'metrics' => $metrics,
            'period' => $period,
            'matched' => $metricsMatched,
        ];
    }

    /**
     * 기간 키 → [시작(UTC), 종료(UTC), 표시라벨]. 경계는 KST 기준.
     *
     * @return array{0: Carbon, 1: Carbon, 2: string}
     */
    private function resolvePeriod(string $key): array
    {
        $now = Carbon::now(self::TZ);

        [$start, $end, $label] = match ($key) {
            'yesterday' => [
                $now->copy()->subDay()->startOfDay(),
                $now->copy()->subDay()->endOfDay(),
                '어제 (' . $now->copy()->subDay()->format('Y-m-d') . ')',
            ],
            'this_week' => [
                $now->copy()->startOfWeek(),
                $now->copy(),
                '이번 주 (' . $now->copy()->startOfWeek()->format('m/d') . '~' . $now->format('m/d') . ')',
            ],
            'last_week' => [
                $now->copy()->subWeek()->startOfWeek(),
                $now->copy()->subWeek()->endOfWeek(),
                '지난 주 (' . $now->copy()->subWeek()->startOfWeek()->format('m/d') . '~' . $now->copy()->subWeek()->endOfWeek()->format('m/d') . ')',
            ],
            'this_month' => [
                $now->copy()->startOfMonth(),
                $now->copy(),
                '이번 달 (' . $now->format('Y-m') . ')',
            ],
            'last_month' => [
                $now->copy()->subMonthNoOverflow()->startOfMonth(),
                $now->copy()->subMonthNoOverflow()->endOfMonth(),
                '지난 달 (' . $now->copy()->subMonthNoOverflow()->format('Y-m') . ')',
            ],
            '7d' => [
                $now->copy()->subDays(7)->startOfDay(),
                $now->copy(),
                '최근 7일',
            ],
            '30d' => [
                $now->copy()->subDays(30)->startOfDay(),
                $now->copy(),
                '최근 30일',
            ],
            default => [
                $now->copy()->startOfDay(),
                $now->copy(),
                '오늘 (' . $now->format('Y-m-d') . ')',
            ],
        };

        return [$start->copy()->utc(), $end->copy()->utc(), $label];
    }

    private function signupsCard(Carbon $start, Carbon $end): array
    {
        $byRole = User::whereBetween('created_at', [$start, $end])
            ->select('role', DB::raw('COUNT(*) as cnt'))
            ->groupBy('role')
            ->pluck('cnt', 'role');

        $total = (int) $byRole->sum();

        return [
            'key' => 'signups',
            'title' => '회원가입 현황',
            'icon' => 'users',
            'primary' => ['label' => '신규 가입', 'value' => $total, 'unit' => '명'],
            'stats' => [
                ['label' => '보호자', 'value' => (int) ($byRole['guardian'] ?? 0), 'unit' => '명'],
                ['label' => '돌봄전문가', 'value' => (int) ($byRole['caregiver'] ?? 0), 'unit' => '명'],
                ['label' => '기관', 'value' => (int) ($byRole['organization'] ?? 0), 'unit' => '명'],
            ],
            'breakdown' => null,
        ];
    }

    private function matchingCard(Carbon $start, Carbon $end): array
    {
        $newRequests = MatchRequest::whereBetween('created_at', [$start, $end])->count();
        $matched = CareMatch::whereBetween('created_at', [$start, $end])->count();
        $inProgress = CareMatch::whereIn('status', ['confirmed', 'in_progress'])->count();
        $rate = $newRequests > 0 ? round($matched / $newRequests * 100, 1) : 0.0;

        $byDomain = DB::table('match_requests')
            ->whereBetween('created_at', [$start, $end])
            ->select('service_domain', DB::raw('COUNT(*) as cnt'))
            ->groupBy('service_domain')
            ->pluck('cnt', 'service_domain');

        return [
            'key' => 'matching',
            'title' => '매칭 현황',
            'icon' => 'match',
            'primary' => ['label' => '신규 매칭요청', 'value' => (int) $newRequests, 'unit' => '건'],
            'stats' => [
                ['label' => '성사 매칭', 'value' => (int) $matched, 'unit' => '건'],
                ['label' => '매칭 성사율', 'value' => $rate, 'unit' => '%'],
                ['label' => '진행중(전체)', 'value' => (int) $inProgress, 'unit' => '건'],
            ],
            'breakdown' => [
                'title' => '도메인별 신규요청',
                'unit' => '건',
                'rows' => $this->domainRows($byDomain),
            ],
        ];
    }

    private function revenueCard(Carbon $start, Carbon $end): array
    {
        $paid = Payment::where('status', 'paid')->whereBetween('paid_at', [$start, $end]);
        $agg = (clone $paid)
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(total_amount),0) as gross, COALESCE(SUM(amount_self_pay),0) as self_pay')
            ->first();

        $cnt = (int) ($agg->cnt ?? 0);
        $gross = (int) ($agg->gross ?? 0);
        $selfPay = (int) ($agg->self_pay ?? 0);
        $avg = $cnt > 0 ? (int) round($gross / $cnt) : 0;

        $byDomain = DB::table('payments as p')
            ->join('matches as m', 'm.id', '=', 'p.match_id')
            ->join('match_requests as r', 'r.id', '=', 'm.request_id')
            ->where('p.status', 'paid')
            ->whereBetween('p.paid_at', [$start, $end])
            ->select('r.service_domain', DB::raw('SUM(p.total_amount) as amt'))
            ->groupBy('r.service_domain')
            ->pluck('amt', 'service_domain');

        return [
            'key' => 'revenue',
            'title' => '매출 현황',
            'icon' => 'revenue',
            'primary' => ['label' => '결제 매출', 'value' => $gross, 'unit' => '원'],
            'stats' => [
                ['label' => '결제 건수', 'value' => $cnt, 'unit' => '건'],
                ['label' => '평균 결제액', 'value' => $avg, 'unit' => '원'],
                ['label' => '본인부담 합계', 'value' => $selfPay, 'unit' => '원'],
            ],
            'breakdown' => [
                'title' => '도메인별 매출',
                'unit' => '원',
                'rows' => $this->domainRows($byDomain),
            ],
        ];
    }

    /**
     * 도메인별 집계 → 라벨 붙인 정렬된 행 목록(값 내림차순, 0 제외)
     */
    private function domainRows($pluck): array
    {
        $rows = [];
        foreach ($pluck as $domain => $val) {
            $val = (int) $val;
            if ($val <= 0) {
                continue;
            }
            $rows[] = [
                'label' => self::DOMAIN_LABELS[$domain] ?? ($domain ?: '기타'),
                'value' => $val,
            ];
        }
        usort($rows, fn ($a, $b) => $b['value'] <=> $a['value']);

        return $rows;
    }

    private function summary(array $cards, string $label): string
    {
        $parts = [];
        foreach ($cards as $c) {
            $p = $c['primary'];
            $parts[] = match ($c['key']) {
                'signups' => "신규가입 {$p['value']}명",
                'matching' => "매칭요청 {$p['value']}건(성사 {$c['stats'][0]['value']}건)",
                'revenue' => '결제매출 ' . number_format((int) $p['value']) . '원',
                default => '',
            };
        }
        $parts = array_filter($parts);

        if (empty($parts)) {
            return $label . ' 기준 표시할 데이터가 없습니다.';
        }

        return $label . ' 기준 ' . implode(', ', $parts) . ' 입니다.';
    }
}
