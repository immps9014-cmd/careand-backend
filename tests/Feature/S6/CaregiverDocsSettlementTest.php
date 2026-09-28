<?php

namespace Tests\Feature\S6;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Scenario;
use Tests\TestCase;

/**
 * 통합시험 IT-05 인력 가입·서류·정산 — REQ-F-09·15·16·20·23 (S6, 2026-09-29)
 */
class CaregiverDocsSettlementTest extends TestCase
{
    use RefreshDatabase, Scenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpScenario();
        Storage::fake('local');
    }

    /** UT-09·20 서류 암호화 저장 → 관리자 열람(사유 필수·원본 일치·감사로그) → 반려 알림 → 승인 시 미확인 경고 */
    public function test_documents_upload_review_and_approve_warning(): void
    {
        [$cUser, $cg, $cTok] = $this->createCaregiverUser('pending');
        [, $aTok] = $this->createAdminUser();
        $png = UploadedFile::fake()->image('id.png', 8, 8);
        $raw = file_get_contents($png->getRealPath());

        $this->post('/api/v1/caregivers/me/documents', ['doc_type' => 'id_card', 'file' => $png], $this->h($cTok, ['Accept' => 'application/json']))->assertCreated();
        $doc = DB::table('caregiver_documents')->first();
        $this->assertStringNotContainsString($raw, Storage::disk('local')->get($doc->file_path), '암호화 저장');
        $this->post('/api/v1/caregivers/me/documents', ['doc_type' => 'passport', 'file' => UploadedFile::fake()->image('x.png')], $this->h($cTok, ['Accept' => 'application/json']))->assertStatus(422);

        $this->get("/api/v1/admin/caregivers/{$cg->id}/documents/{$doc->id}/file", $this->h($aTok))->assertStatus(422);
        $res = $this->get("/api/v1/admin/caregivers/{$cg->id}/documents/{$doc->id}/file", $this->h($aTok, ['X-Access-Reason' => rawurlencode('자격 심사 확인')]))->assertOk();
        $this->assertSame($raw, $res->getContent(), '원본 바이트 일치');
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'caregiver.document.view')->count());

        $this->postJson("/api/v1/admin/caregivers/{$cg->id}/documents/{$doc->id}/reject", ['reason' => '흐려요'], $this->h($aTok))->assertOk();
        $this->assertSame(1, $this->notifications('CAREGIVER_DOC_REJECTED')->where('user_id', $cUser->id)->count());

        $ap = $this->postJson("/api/v1/admin/caregivers/{$cg->id}/approve", [], $this->h($aTok))->assertOk();
        $this->assertCount(3, $ap->json('missing_documents'), '필수 3종 미확인 경고');
    }

    /** UT-09 정산 계좌는 암호화, 응답은 가림 */
    public function test_payout_account_encrypted_and_masked(): void
    {
        [, $cg, $cTok] = $this->createCaregiverUser();
        $this->putJson('/api/v1/caregivers/me/payout-account', ['bank_name' => '국민', 'bank_account' => '123-456-7890123', 'bank_holder' => '시험'], $this->h($cTok))->assertOk();
        $this->assertStringNotContainsString('1234567890123', (string) DB::table('caregivers')->where('id', $cg->id)->value('bank_account'));
        $this->getJson('/api/v1/caregivers/me/documents', $this->h($cTok))->assertJsonPath('data.payout.bank_account_masked', '*********0123');
    }

    /** UT-15·23 명세서 확인·이의제기 → 이의 열린 건 입금 불가 → 답변 → 확인 → 입금·알림, 남의 정산 404 */
    public function test_settlement_ack_dispute_paid(): void
    {
        [$cUser, $cg, $cTok] = $this->createCaregiverUser();
        [, , $otherTok] = $this->createCaregiverUser();
        [$admin, $aTok] = $this->createAdminUser();
        $id = DB::table('settlements')->insertGetId(['caregiver_id' => $cg->id, 'period_start' => '2026-09-21', 'period_end' => '2026-09-27',
            'gross_amount' => 500000, 'withholding_tax_3_3' => 16500, 'net_amount' => 483500, 'status' => 'confirmed', 'created_at' => now(), 'updated_at' => now()]);

        $this->postJson("/api/v1/settlements/{$id}/ack", [], $this->h($otherTok))->assertStatus(404);
        $this->postJson("/api/v1/settlements/{$id}/dispute", ['reason' => '9/24 두 시간 누락'], $this->h($cTok))->assertOk();
        $this->assertSame(1, $this->notifications('SETTLEMENT_DISPUTED')->where('user_id', $admin->id)->count());
        $this->postJson("/api/v1/admin/settlements/{$id}/paid", [], $this->h($aTok))->assertStatus(422)->assertJson(['error_code' => 'DISPUTE_OPEN']);
        $this->postJson("/api/v1/admin/settlements/{$id}/dispute-reply", ['reply' => '반영했어요', 'resolve' => true], $this->h($aTok))->assertOk();
        $this->postJson("/api/v1/settlements/{$id}/ack", [], $this->h($cTok))->assertOk();
        $this->postJson("/api/v1/admin/settlements/{$id}/paid", ['bank_tx_id' => 'TX1'], $this->h($aTok))->assertOk();
        $this->assertSame('paid', DB::table('settlements')->where('id', $id)->value('status'));
        $this->assertSame(1, $this->notifications('SETTLEMENT_PAID')->where('user_id', $cUser->id)->count());
    }

    /** UT-23 월간 결산 명령 — 전월 결제 합계·도메인별 집계 */
    public function test_monthly_close_command(): void
    {
        [, $guardian] = $this->createGuardianUser();
        [, $cg] = $this->createCaregiverUser();
        [$m] = $this->makeMatch($this->makeRequest($guardian), $cg);
        DB::table('payments')->insert(['guardian_id' => $guardian->id, 'match_id' => $m->id, 'total_amount' => 36000, 'amount_self_pay' => 36000,
            'amount_ltc_pay' => 0, 'method' => 'card', 'status' => 'paid', 'paid_at' => '2026-07-10 03:00:00', 'created_at' => now(), 'updated_at' => now()]);
        $this->artisan('reports:monthly-close', ['--month' => '2026-07', '--no-notify' => true])->assertSuccessful();
        $d = json_decode(DB::table('monthly_reports')->where('month', '2026-07')->value('data'), true);
        $this->assertSame(36000, $d['revenue']['total']);
        $this->assertSame('senior', $d['revenue']['by_domain'][0]['domain']);
    }

    /** UT-16 받은 후기·활동은 실제 기록 기준(시드 저장값 아님) */
    public function test_performance_counts_from_records(): void
    {
        [, $cg, $cTok] = $this->createCaregiverUser();   // 팩토리 저장값 rating_count=10
        $d = $this->getJson('/api/v1/caregivers/me/performance', $this->h($cTok))->assertOk()->json('data');
        $this->assertSame(0, $d['rating_count']);
        $this->assertCount(6, $d['months']);
    }
}
