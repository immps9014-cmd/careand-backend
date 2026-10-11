<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Scenario;
use Tests\TestCase;

/** 관리자 결제 내역(2026-10-11) — 대시보드 「이번 주 매출」과 같은 합계, KST 주 경계, 바우처 선납·환불 포함 */
class AdminPaymentsTest extends TestCase
{
    use RefreshDatabase, Scenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpScenario();
    }

    public function test_payments_list_matches_dashboard_revenue(): void
    {
        [, $tok] = $this->createAdminUser();
        $h = $this->h($tok);
        [$gUser, $g] = $this->createGuardianUser();
        [, $cg] = $this->createCaregiverUser();
        [$m1] = $this->makeMatch($this->makeRequest($g), $cg);
        [$m2] = $this->makeMatch($this->makeRequest($g), $cg);
        $weekStartKst = now('Asia/Seoul')->startOfWeek();
        $pay = fn ($m, $amt, $st, $at) => DB::table('payments')->insert([
            'guardian_id' => $g->id, 'match_id' => $m->id, 'total_amount' => $amt, 'amount_self_pay' => $amt, 'amount_ltc_pay' => 0,
            'method' => 'card', 'status' => $st, 'paid_at' => $st === 'paid' ? $at : null, 'pg_order_id' => 'T-' . $amt, 'created_at' => $at, 'updated_at' => $at,
        ]);
        $pay($m1, 30000, 'paid', $weekStartKst->copy()->addHours(2)->utc());          // 이번 주(KST 월 02시 = UTC 일요일 17시)
        $pay($m2, 50000, 'paid', $weekStartKst->copy()->subHours(1)->utc());          // 지난주(KST 일 23시)
        $pay($m2, 70000, 'failed', $weekStartKst->copy()->addHours(3)->utc());        // 실패 — 매출 아님
        // 바우처 선납 40,000(이번 주)·환불 10,000(이번 주)
        $clientId = DB::table('postpartum_clients')->insertGetId([
            'user_id' => $gUser->id, 'name' => '산모', 'phone_encrypted' => 'x', 'birth_date' => '1995-01-01', 'address' => '서울 강남구',
            'region_code' => '11', 'delivery_date' => now()->toDateString(), 'delivery_type' => 'natural', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('mnh_contracts')->insert([
            'contract_no' => 'MNH-T-1', 'postpartum_client_id' => $clientId, 'user_id' => $gUser->id, 'year' => 2026, 'days' => 10,
            'start_date' => now()->toDateString(), 'payment_method' => 'cash', 'status' => 'cancelled',
            'prepaid_amount' => 40000, 'prepaid_at' => $weekStartKst->copy()->addHours(5)->utc(),
            'refund_amount' => 10000, 'refunded_at' => $weekStartKst->copy()->addHours(6)->utc(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $list = $this->getJson('/api/v1/admin/payments?period=this_week', $h)->assertOk();
        $this->assertSame(30000 + 40000 - 10000, $list->json('summary.revenue'));
        $this->assertSame(['card', 'voucher_prepaid', 'voucher_refund'], collect($list->json('data'))->pluck('kind')->sort()->values()->all());
        $this->assertSame(1, $list->json('summary.card_paid.count'));

        Cache::forget('admin:kpi');
        $this->assertSame($list->json('summary.revenue'), $this->getJson('/api/v1/admin/dashboard/kpi', $h)->json('data.revenue_this_week'));

        // 지난주에는 KST 일요일 23시 결제만
        $this->assertSame(50000, $this->getJson('/api/v1/admin/payments?period=last_week', $h)->json('summary.revenue'));
        // 결제 시도 전부(실패 포함)·카드만
        $all = $this->getJson('/api/v1/admin/payments?period=this_week&status=all&kind=card', $h)->json();
        $this->assertSame(['failed', 'paid'], collect($all['data'])->pluck('status')->sort()->values()->all());
        // 검색
        $this->assertCount(2, $this->getJson('/api/v1/admin/payments?period=this_week&q=MNH-T-1&kind=voucher&per_page=100', $h)->json('data'));   // 같은 계약의 선납·환불
        // 권한: CS 는 못 봄
        [, $csTok] = $this->createAdminUser('cs');
        $this->getJson('/api/v1/admin/payments', $this->h($csTok))->assertStatus(403);
    }
}
