<?php

namespace Tests\Feature\S6;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Scenario;
use Tests\TestCase;

/**
 * 통합시험 IT-04 결제 · 후기 — REQ-F-04·07·08·24·31 (S6, 2026-09-29)
 */
class PaymentReviewTest extends TestCase
{
    use RefreshDatabase, Scenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpScenario();
        $this->fakeAi();
    }

    /** UT-04·31 토스 준비 → 금액 위변조 거부 → 승인 → 결제 완료 알림 → 영수증(본인만) → 월 필터 */
    public function test_toss_payment_flow_and_receipt(): void
    {
        [$gUser, $guardian, $gTok] = $this->createGuardianUser();
        [, $cg] = $this->createCaregiverUser();
        [$m] = $this->makeMatch($this->makeRequest($guardian), $cg);
        [, , $otherTok] = $this->createGuardianUser();

        $prep = $this->postJson('/api/v1/payments/toss/prepare', ['match_id' => $m->id, 'method' => 'card'], $this->h($gTok))->assertOk()->json('data');
        $this->assertTrue($prep['toss_required']);
        $this->postJson('/api/v1/payments/toss/confirm', ['payment_key' => 'pk_test', 'order_id' => $prep['order_id'], 'amount' => $prep['amount'] + 1], $this->h($gTok))
            ->assertStatus(422)->assertJson(['error_code' => 'AMOUNT_MISMATCH']);

        $prep = $this->postJson('/api/v1/payments/toss/prepare', ['match_id' => $m->id, 'method' => 'card'], $this->h($gTok))->json('data');
        $this->postJson('/api/v1/payments/toss/confirm', ['payment_key' => 'pk_test2', 'order_id' => $prep['order_id'], 'amount' => $prep['amount']], $this->h($gTok))->assertOk();
        $pay = DB::table('payments')->where('pg_order_id', $prep['order_id'])->first();
        $this->assertSame('paid', $pay->status);
        $this->assertSame(1, $this->notifications('PAYMENT_PAID')->where('user_id', $gUser->id)->count());
        $this->postJson('/api/v1/payments/toss/confirm', ['payment_key' => 'pk_test2', 'order_id' => $prep['order_id'], 'amount' => $prep['amount']], $this->h($gTok))->assertOk();   // 새로고침 중복 승인
        $this->assertSame(1, $this->notifications('PAYMENT_PAID')->count(), '중복 승인에 알림 한 번');

        $this->getJson("/api/v1/payments/{$pay->id}/receipt", $this->h($gTok))->assertOk()->assertJsonPath('data.self_pay', (int) $pay->amount_self_pay)
            ->assertJsonPath('data.seller.biz_no', '106-23-91832');
        $this->getJson("/api/v1/payments/{$pay->id}/receipt", $this->h($otherTok))->assertStatus(403);
        $list = collect($this->getJson('/api/v1/payments?month=' . now('Asia/Seoul')->format('Y-m'), $this->h($gTok))->json('data'));
        $this->assertSame(['failed', 'paid'], $list->pluck('status')->sort()->values()->all(), '위변조로 실패한 첫 주문 + 완료 주문');
        $this->assertCount(0, $this->getJson('/api/v1/payments?month=2020-01', $this->h($gTok))->json('data'));
    }

    /** UT-07·24 도메인별 항목 → 2점 이하 CS 알림 1회·SLA 통계, 3점 이상으로 고치면 대상 해제 */
    public function test_review_criteria_low_rating_alert(): void
    {
        [$admin, $aTok] = $this->createAdminUser();
        [, $guardian, $gTok] = $this->createGuardianUser();
        [, $cg] = $this->createCaregiverUser();
        [$m] = $this->makeMatch($this->makeRequest($guardian), $cg, 'completed');

        $row = $this->getJson('/api/v1/guardians/reviewable', $this->h($gTok))->assertOk()->json('data.0');
        $this->assertSame(['punctual', 'attitude', 'communication', 'care_skill', 'safety'], array_column($row['criteria'], 'key'));

        $body = ['match_id' => $m->id, 'rating' => 2, 'comment' => '시간을 안 지켰어요', 'scores' => ['punctual' => 1, 'hacker' => 5]];
        $this->postJson('/api/v1/guardians/reviews', $body, $this->h($gTok))->assertOk();
        $this->postJson('/api/v1/guardians/reviews', $body, $this->h($gTok))->assertOk();   // 같은 후기 다시 저장
        $rv = DB::table('reviews')->first();
        $this->assertSame(['punctual' => 1], json_decode($rv->scores, true), '없는 항목 키는 버림');
        $this->assertSame(1, $this->notifications('REVIEW_LOW')->where('user_id', $admin->id)->count(), '알림 한 번');

        $stats = $this->getJson('/api/v1/admin/cs/stats', $this->h($aTok, ['X-Access-Reason' => 'test']))->json('data');
        $this->assertSame(1, $stats['negative_open']);

        $this->postJson('/api/v1/guardians/reviews', ['match_id' => $m->id, 'rating' => 4], $this->h($gTok))->assertOk();
        $this->assertNull(DB::table('reviews')->value('flagged_at'));
    }
}
