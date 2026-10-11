<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Scenario;
use Tests\TestCase;

/** 결제 내역 엑셀(2026-10-11) — 슈퍼관리자만·사유 필수·감사로그·이름 마스킹·금액 숫자 칸 */
class AdminPaymentsExportTest extends TestCase
{
    use RefreshDatabase, Scenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpScenario();
    }

    public function test_payments_xlsx_export(): void
    {
        [$admin, $tok] = $this->createAdminUser();
        [$gUser, $g] = $this->createGuardianUser();
        $gUser->update(['name' => '홍길동']);
        [, $cg] = $this->createCaregiverUser();
        [$m] = $this->makeMatch($this->makeRequest($g), $cg);
        DB::table('payments')->insert([
            'guardian_id' => $g->id, 'match_id' => $m->id, 'total_amount' => 36000, 'amount_self_pay' => 36000, 'amount_ltc_pay' => 0,
            'method' => 'card', 'status' => 'paid', 'paid_at' => now(), 'pg_order_id' => '=HYPERLINK("x")', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // 사유 없으면 거부
        $this->getJson('/api/v1/admin/exports/payments?period=this_week', $this->h($tok))->assertStatus(422)->assertJson(['error_code' => 'REASON_REQUIRED']);
        // 지점장은 다운로드 권한 없음(조회는 가능)
        [, $brTok] = $this->createAdminUser('branch');
        $this->getJson('/api/v1/admin/exports/payments', $this->h($brTok, ['X-Access-Reason' => rawurlencode('월말 매출 점검')]))->assertStatus(403);

        $res = $this->get('/api/v1/admin/exports/payments?period=this_week', $this->h($tok, ['X-Access-Reason' => rawurlencode('월말 매출 점검')]));
        $res->assertOk();
        $this->assertStringContainsString('spreadsheetml', $res->headers->get('Content-Type'));
        $tmp = tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($tmp, $res->getContent());
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($tmp) === true);
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($tmp);
        $this->assertStringContainsString('홍○○', $sheet);
        $this->assertStringNotContainsString('홍길동', $sheet);
        $this->assertMatchesRegularExpression('/<c r="I4" s="2"><v>36000<\/v><\/c>/', $sheet);   // 금액은 숫자 칸(#,##0)
        $this->assertStringContainsString("'=HYPERLINK", html_entity_decode($sheet, ENT_QUOTES | ENT_XML1));   // 수식 주입 방지
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'export.payments.xlsx')->where('actor_id', $admin->id)->count());
    }
}
