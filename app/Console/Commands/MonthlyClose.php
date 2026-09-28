<?php

namespace App\Console\Commands;

use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 월간 결산 생성 — 기능 23·26(2026-09-28, 구현계획 S5). 매월 1일 02:30 KST 에 전월분(Kernel).
 * 달의 경계는 한국 시각. 금액은 원. 이미 있으면 덮어쓴다.
 */
class MonthlyClose extends Command
{
    protected $signature = 'reports:monthly-close {--month= : YYYY-MM, 기본 전월} {--no-notify : 관리자 알림 생략}';
    protected $description = '월간 결산(매출·정산·매칭·돌봄·회원·후기) 생성';

    public function handle(NotificationService $svc): int
    {
        $month = $this->option('month') ?: now('Asia/Seoul')->subMonthNoOverflow()->format('Y-m');
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            $this->error('month 는 YYYY-MM');
            return self::FAILURE;
        }
        $fromKst = Carbon::createFromFormat('Y-m-d H:i:s', "$month-01 00:00:00", 'Asia/Seoul');
        [$from, $to] = [$fromKst->copy()->utc(), $fromKst->copy()->addMonth()->utc()];
        $in = fn ($q, $col) => $q->where($col, '>=', $from)->where($col, '<', $to);

        $paid = $in(DB::table('payments as p')->join('matches as m', 'm.id', '=', 'p.match_id')
            ->join('match_requests as r', 'r.id', '=', 'm.request_id')->join('caregivers as c', 'c.id', '=', 'm.caregiver_id')
            ->where('p.status', 'paid'), 'p.paid_at');
        $byDomain = (clone $paid)->select('r.service_domain as k', DB::raw('COUNT(*) n'), DB::raw('SUM(p.total_amount) amt'),
            DB::raw('SUM(p.amount_self_pay) self_pay'), DB::raw('SUM(p.amount_ltc_pay) ltc_pay'))->groupBy('k')->get();
        $byBranch = (clone $paid)->leftJoin('branches as b', 'b.id', '=', 'c.branch_id')
            ->select(DB::raw("COALESCE(b.name, '지점 미지정') as k"), DB::raw('COUNT(*) n'), DB::raw('SUM(p.total_amount) amt'))->groupBy('k')->get();
        $cancelled = $in(DB::table('payments')->whereIn('status', ['cancelled', 'refunded']), 'updated_at');

        $settle = $in(DB::table('settlements'), 'period_end');
        $req = $in(DB::table('match_requests'), 'created_at');
        $sessions = $in(DB::table('care_sessions')->where('status', 'completed'), 'actual_end');
        $kpiMatch = $in(DB::table('match_requests')->whereNotNull('matched_at'), 'created_at')
            ->selectRaw('COUNT(*) n, AVG(TIMESTAMPDIFF(SECOND, created_at, matched_at))/3600 h')->first();
        $kpiLog = $in(DB::table('care_sessions')->whereNotNull('log_started_at')->whereNotNull('log_sent_at'), 'log_sent_at')
            ->selectRaw('COUNT(*) n, AVG(TIMESTAMPDIFF(SECOND, log_started_at, log_sent_at))/60 m')->first();
        $reviews = $in(DB::table('reviews'), 'created_at');

        $data = [
            'month' => $month,
            'revenue' => [
                'paid_count' => (clone $paid)->count(),
                'total' => (int) (clone $paid)->sum('p.total_amount'),
                'self_pay' => (int) (clone $paid)->sum('p.amount_self_pay'),
                'ltc_pay' => (int) (clone $paid)->sum('p.amount_ltc_pay'),
                'cancelled_count' => (clone $cancelled)->count(),
                'cancelled_amount' => (int) (clone $cancelled)->sum('total_amount'),
                'by_domain' => $byDomain->map(fn ($r) => ['domain' => $r->k, 'label' => \App\Support\ServiceDomains::label((string) $r->k),
                    'count' => (int) $r->n, 'amount' => (int) $r->amt, 'self_pay' => (int) $r->self_pay, 'ltc_pay' => (int) $r->ltc_pay])->values(),
                'by_branch' => $byBranch->map(fn ($r) => ['branch' => $r->k, 'count' => (int) $r->n, 'amount' => (int) $r->amt])->values(),
            ],
            'settlement' => [
                'count' => (clone $settle)->count(),
                'gross' => (int) (clone $settle)->sum('gross_amount'),
                'withholding' => (int) (clone $settle)->sum('withholding_tax_3_3'),
                'net' => (int) (clone $settle)->sum('net_amount'),
                'paid_count' => (clone $settle)->where('status', 'paid')->count(),
            ],
            'matching' => [
                'requests' => (clone $req)->count(),
                'matched' => (clone $req)->where('status', 'matched')->count(),
                'expired' => (clone $req)->where('status', 'expired')->count(),
                'avg_match_hours' => $kpiMatch && $kpiMatch->n ? round((float) $kpiMatch->h, 2) : null,
            ],
            'care' => [
                'sessions_completed' => (clone $sessions)->count(),
                'care_hours' => round((int) (clone $sessions)->sum('duration_min') / 60, 1),
                'logs_sent' => $kpiLog ? (int) $kpiLog->n : 0,
                'avg_log_minutes' => $kpiLog && $kpiLog->n ? round((float) $kpiLog->m, 1) : null,
            ],
            'members' => [
                'new_guardians' => $in(DB::table('users')->where('role', 'guardian'), 'created_at')->count(),
                'new_caregivers' => $in(DB::table('users')->where('role', 'caregiver'), 'created_at')->count(),
                'withdrawn' => $in(DB::table('users'), 'deleted_at')->count(),
            ],
            'reviews' => [
                'count' => (clone $reviews)->count(),
                'avg_rating' => ($a = (clone $reviews)->avg('rating')) !== null ? round((float) $a, 2) : null,
                'negative' => (clone $reviews)->where('rating', '<=', 2)->count(),
            ],
        ];

        DB::table('monthly_reports')->updateOrInsert(['month' => $month], [
            'data' => json_encode($data, JSON_UNESCAPED_UNICODE), 'generated_at' => now(), 'updated_at' => now(), 'created_at' => now(),
        ]);
        $this->info("월간 결산 {$month}: 매출 " . number_format($data['revenue']['total']) . "원 · 결제 {$data['revenue']['paid_count']}건 · 돌봄 {$data['care']['sessions_completed']}회");

        if (!$this->option('no-notify')) {
            foreach ($svc->adminsFor('reports') as $a) {
                $svc->notifySafely($a, NotificationService::TYPE_MONTHLY_REPORT_READY, ['month' => $month, 'total' => $data['revenue']['total']]);
            }
        }
        return self::SUCCESS;
    }
}
